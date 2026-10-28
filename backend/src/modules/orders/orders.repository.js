// The only file in the orders module that talks to the database.
// Functions that take `client` run inside the caller's transaction;
// the others use the shared pool (or an optional `db` passed in).
const pool = require('../../shared/db');

const ORDER_COLUMNS = 'id, user_id, status, total, created_at, updated_at';

// Money columns are NUMERIC and come back as strings ("59.97"): exact, no float rounding.

// Creates the order with its total computed by PostgreSQL from the reserved items.
async function insertOrder(client, userId, items) {
  const { rows } = await client.query(
    `INSERT INTO orders (user_id, total)
     SELECT $1, SUM(t.price * t.quantity)
       FROM unnest($2::numeric[], $3::int[]) AS t(price, quantity)
     RETURNING id`,
    [userId, items.map((i) => i.price), items.map((i) => i.quantity)]
  );
  return rows[0].id;
}

// One INSERT for all items (arrays + unnest), whatever the number of items.
async function insertItems(client, orderId, items) {
  await client.query(
    `INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity)
     SELECT $1::uuid, t.product_id, t.product_name, t.unit_price, t.quantity
       FROM unnest($2::uuid[], $3::text[], $4::numeric[], $5::int[])
         AS t(product_id, product_name, unit_price, quantity)`,
    [
      orderId,
      items.map((i) => i.product_id),
      items.map((i) => i.name),
      items.map((i) => i.price),
      items.map((i) => i.quantity),
    ]
  );
}

async function insertHistory(client, { orderId, fromStatus, toStatus, changedBy }) {
  await client.query(
    `INSERT INTO order_status_history (order_id, from_status, to_status, changed_by)
     VALUES ($1, $2, $3, $4)`,
    [orderId, fromStatus, toStatus, changedBy]
  );
}

// ownerId set (customer): the order must belong to that user, checked in the query itself.
// ownerId null (admin): any order.
async function findById(id, ownerId = null) {
  const params = [id];
  let sql = `SELECT ${ORDER_COLUMNS} FROM orders WHERE id = $1`;
  if (ownerId) {
    params.push(ownerId);
    sql += ' AND user_id = $2';
  }
  const { rows } = await pool.query(sql, params);
  return rows[0];
}

async function findItems(orderId, db = pool) {
  const { rows } = await db.query(
    `SELECT product_id, product_name, unit_price, quantity,
            unit_price * quantity AS line_total
       FROM order_items
      WHERE order_id = $1
      ORDER BY product_name`,
    [orderId]
  );
  return rows;
}

async function findHistory(orderId) {
  const { rows } = await pool.query(
    `SELECT from_status, to_status, changed_at
       FROM order_status_history
      WHERE order_id = $1
      ORDER BY changed_at, id`,
    [orderId]
  );
  return rows;
}

// filters: { userId, status } (both optional). Returns one page plus the total count.
async function list({ userId, status, limit, offset }) {
  const where = [];
  const params = [];
  if (userId) {
    params.push(userId);
    where.push(`user_id = $${params.length}`);
  }
  if (status) {
    params.push(status);
    where.push(`status = $${params.length}`);
  }
  const whereSql = where.length ? `WHERE ${where.join(' AND ')}` : '';

  const [page, count] = await Promise.all([
    pool.query(
      `SELECT ${ORDER_COLUMNS} FROM orders ${whereSql}
        ORDER BY created_at DESC, id DESC
        LIMIT $${params.length + 1} OFFSET $${params.length + 2}`,
      [...params, limit, offset]
    ),
    pool.query(`SELECT COUNT(*)::int AS total FROM orders ${whereSql}`, params),
  ]);

  return { rows: page.rows, total: count.rows[0].total };
}

// Locks the order row until the transaction ends. A second status change on the same
// order waits here, then sees the status the first one left.
async function lockById(client, id) {
  const { rows } = await client.query(
    'SELECT id, user_id, status FROM orders WHERE id = $1 FOR UPDATE',
    [id]
  );
  return rows[0];
}

async function updateStatus(client, id, status) {
  await client.query('UPDATE orders SET status = $2, updated_at = NOW() WHERE id = $1', [
    id,
    status,
  ]);
}

module.exports = {
  insertOrder,
  insertItems,
  insertHistory,
  findById,
  findItems,
  findHistory,
  list,
  lockById,
  updateStatus,
};
