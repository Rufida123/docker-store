// The four RPC implementations. A refusal is a normal response; gRPC errors
// are reserved for invalid input and infrastructure failures.
const { status: grpcStatus } = require('@grpc/grpc-js');
const pool = require('./db');

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const MAX_IDS = 100;

function invalid(message) {
  const err = new Error(message);
  err.code = grpcStatus.INVALID_ARGUMENT;
  return err;
}

function checkUuid(value, field) {
  if (typeof value !== 'string' || !UUID_RE.test(value)) {
    throw invalid(`${field} must be a valid UUID`);
  }
  return value.toLowerCase();
}

// Duplicate product ids are summed into one line, and lines are sorted by
// product_id so every transaction locks rows in the same order (no deadlocks).
function normalizeItems(items) {
  if (!Array.isArray(items) || items.length === 0 || items.length > MAX_IDS) {
    throw invalid(`items must contain 1 to ${MAX_IDS} entries`);
  }
  const merged = new Map();
  for (const item of items) {
    const productId = checkUuid(item.product_id, 'product_id');
    const quantity = Number(item.quantity);
    if (!Number.isInteger(quantity) || quantity <= 0) {
      throw invalid('quantity must be a positive integer');
    }
    merged.set(productId, (merged.get(productId) || 0) + quantity);
  }
  return [...merged.entries()]
    .map(([productId, quantity]) => ({ productId, quantity }))
    .sort((a, b) => (a.productId < b.productId ? -1 : 1));
}

async function getStock({ product_ids: productIds = [] }) {
  if (!Array.isArray(productIds) || productIds.length > MAX_IDS) {
    throw invalid(`product_ids must contain at most ${MAX_IDS} ids`);
  }
  const ids = [...new Set(productIds.map((id) => checkUuid(id, 'product_id')))];
  if (ids.length === 0) {
    return { levels: [] };
  }

  const { rows } = await pool.query(
    'SELECT product_id, available FROM stock WHERE product_id = ANY($1::uuid[])',
    [ids]
  );
  const known = new Map(rows.map((r) => [r.product_id, r.available]));
  return {
    levels: ids.map((id) => ({ product_id: id, available: known.get(id) ?? 0 })),
  };
}

async function reserveStock({ reservation_id: reservationId, items }) {
  const id = checkUuid(reservationId, 'reservation_id');
  const lines = normalizeItems(items);

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    // The idempotency record. A concurrent reserve with the same id blocks
    // here until the first transaction finishes, then sees its row.
    const inserted = await client.query(
      `INSERT INTO reservations (id, status) VALUES ($1, 'reserved')
       ON CONFLICT (id) DO NOTHING`,
      [id]
    );

    if (inserted.rowCount === 0) {
      const { rows } = await client.query('SELECT status FROM reservations WHERE id = $1', [id]);
      await client.query('COMMIT');
      return rows[0].status === 'released'
        ? { status: 'ALREADY_RELEASED' }
        : { status: 'RESERVED' }; // replay of a reserve that already succeeded
    }

    for (const line of lines) {
      const updated = await client.query(
        `UPDATE stock
            SET available = available - $2, updated_at = NOW()
          WHERE product_id = $1 AND available >= $2`,
        [line.productId, line.quantity]
      );
      if (updated.rowCount === 0) {
        const { rows } = await client.query(
          'SELECT available FROM stock WHERE product_id = $1',
          [line.productId]
        );
        await client.query('ROLLBACK'); // every earlier decrement comes back
        return {
          status: 'REFUSED',
          shortage: {
            product_id: line.productId,
            requested: line.quantity,
            available: rows[0]?.available ?? 0,
          },
        };
      }
    }

    const values = [];
    const params = [id];
    for (const line of lines) {
      params.push(line.productId, line.quantity);
      values.push(`($1, $${params.length - 1}, $${params.length})`);
    }
    await client.query(
      `INSERT INTO reservation_items (reservation_id, product_id, quantity)
       VALUES ${values.join(', ')}`,
      params
    );

    await client.query('COMMIT');
    return { status: 'RESERVED' };
  } catch (err) {
    await client.query('ROLLBACK').catch(() => {});
    throw err;
  } finally {
    client.release();
  }
}

async function releaseStock({ reservation_id: reservationId }) {
  const id = checkUuid(reservationId, 'reservation_id');

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    const { rows } = await client.query(
      'SELECT status FROM reservations WHERE id = $1 FOR UPDATE',
      [id]
    );

    if (rows.length === 0) {
      // Tombstone: this id can never reserve stock any more. If two releases
      // race here, ON CONFLICT lets the second one pass quietly.
      await client.query(
        `INSERT INTO reservations (id, status, released_at) VALUES ($1, 'released', NOW())
         ON CONFLICT (id) DO NOTHING`,
        [id]
      );
      await client.query('COMMIT');
      return { status: 'NOT_FOUND' };
    }

    if (rows[0].status === 'released') {
      await client.query('COMMIT');
      return { status: 'ALREADY_RELEASED' };
    }

    const items = await client.query(
      `SELECT product_id, quantity FROM reservation_items
        WHERE reservation_id = $1
        ORDER BY product_id`,
      [id]
    );
    for (const item of items.rows) {
      await client.query(
        'UPDATE stock SET available = available + $2, updated_at = NOW() WHERE product_id = $1',
        [item.product_id, item.quantity]
      );
    }
    await client.query(
      `UPDATE reservations SET status = 'released', released_at = NOW() WHERE id = $1`,
      [id]
    );

    await client.query('COMMIT');
    return { status: 'RELEASED' };
  } catch (err) {
    await client.query('ROLLBACK').catch(() => {});
    throw err;
  } finally {
    client.release();
  }
}

async function adjustStock({ product_id: productId, change }) {
  const id = checkUuid(productId, 'product_id');
  const delta = Number(change);
  if (!Number.isInteger(delta) || delta === 0) {
    throw invalid('change must be a non-zero integer');
  }

  if (delta > 0) {
    const { rows } = await pool.query(
      `INSERT INTO stock (product_id, available) VALUES ($1, $2)
       ON CONFLICT (product_id) DO UPDATE
          SET available = stock.available + $2, updated_at = NOW()
       RETURNING available`,
      [id, delta]
    );
    return { status: 'APPLIED', available: rows[0].available };
  }

  const updated = await pool.query(
    `UPDATE stock
        SET available = available + $2, updated_at = NOW()
      WHERE product_id = $1 AND available + $2 >= 0
      RETURNING available`,
    [id, delta]
  );
  if (updated.rowCount === 1) {
    return { status: 'APPLIED', available: updated.rows[0].available };
  }

  const { rows } = await pool.query('SELECT available FROM stock WHERE product_id = $1', [id]);
  return { status: 'REFUSED', available: rows[0]?.available ?? 0 };
}

module.exports = { getStock, reserveStock, releaseStock, adjustStock };
