// The only file in the products module that talks to the database.
// Functions that take `client` run inside the caller's transaction;
// the others use the shared pool.
const pool = require('../../shared/db');

const PRODUCT_COLUMNS =
  'id, name, description, category, price, stock, image_url, created_at, updated_at';

// price is NUMERIC and comes back as a string ("19.99"): exact, no float rounding.

async function create({ name, description, category, price, stock, imageUrl }) {
  const { rows } = await pool.query(
    `INSERT INTO products (name, description, category, price, stock, image_url)
     VALUES ($1, $2, $3, $4, $5, $6)
     RETURNING ${PRODUCT_COLUMNS}`,
    [name, description, category, price, stock, imageUrl]
  );
  return rows[0];
}

async function findById(id) {
  const { rows } = await pool.query(`SELECT ${PRODUCT_COLUMNS} FROM products WHERE id = $1`, [id]);
  return rows[0];
}

// One page of products (newest first) plus the total count. category is optional.
async function list({ category, limit, offset }) {
  const params = [];
  let whereSql = '';
  if (category) {
    params.push(category);
    whereSql = 'WHERE category = $1';
  }

  const [page, count] = await Promise.all([
    pool.query(
      `SELECT ${PRODUCT_COLUMNS} FROM products ${whereSql}
        ORDER BY created_at DESC, id DESC
        LIMIT $${params.length + 1} OFFSET $${params.length + 2}`,
      [...params, limit, offset]
    ),
    pool.query(`SELECT COUNT(*)::int AS total FROM products ${whereSql}`, params),
  ]);

  return { rows: page.rows, total: count.rows[0].total };
}

// fields: only keys the service allows (name, description, category, price).
// Column names never come from the request itself; values are always parameters.
async function update(id, fields) {
  const sets = [];
  const params = [id];
  for (const [column, value] of Object.entries(fields)) {
    params.push(value);
    sets.push(`${column} = $${params.length}`);
  }
  const { rows } = await pool.query(
    `UPDATE products SET ${sets.join(', ')}, updated_at = NOW()
      WHERE id = $1
      RETURNING ${PRODUCT_COLUMNS}`,
    params
  );
  return rows[0];
}

// Adds (or removes, if negative) stock relative to the CURRENT value, in one statement.
// Never "set stock = X" from a value read earlier: an order placed in between would be lost.
// Returns undefined if the product is missing or the change would go below zero.
async function changeStock(id, change) {
  const { rows } = await pool.query(
    `UPDATE products
        SET stock = stock + $2, updated_at = NOW()
      WHERE id = $1 AND stock + $2 >= 0
      RETURNING ${PRODUCT_COLUMNS}`,
    [id, change]
  );
  return rows[0];
}

// ---- used by other modules through products/index.js, inside their transaction ----

// Takes `quantity` from stock only if that much is left, in ONE statement.
// UPDATE locks the product row, so concurrent orders for the same product wait for each
// other, and `stock >= $2` is re-checked on the latest value. Stock can never go negative.
// Returns the product (id, name, price), or undefined if the product is missing or short.
async function decrementStock(client, productId, quantity) {
  const { rows } = await client.query(
    `UPDATE products
        SET stock = stock - $2, updated_at = NOW()
      WHERE id = $1 AND stock >= $2
      RETURNING id, name, price`,
    [productId, quantity]
  );
  return rows[0];
}

async function incrementStock(client, productId, quantity) {
  await client.query(
    'UPDATE products SET stock = stock + $2, updated_at = NOW() WHERE id = $1',
    [productId, quantity]
  );
}

// `db`: pass the transaction client when called inside a transaction.
async function findStock(productId, db = pool) {
  const { rows } = await db.query('SELECT id, stock FROM products WHERE id = $1', [productId]);
  return rows[0];
}

module.exports = {
  create,
  findById,
  list,
  update,
  changeStock,
  decrementStock,
  incrementStock,
  findStock,
};
