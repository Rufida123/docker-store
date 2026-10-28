// Runs fn(client) inside one database transaction.
// Everything fn does with `client` is committed together, or rolled back together if fn throws.
// Pass `client` to every query (and to other modules) that must be part of the same transaction.
const pool = require('./db');

async function withTransaction(fn) {
  const client = await pool.connect();
  let brokenConnection;
  try {
    await client.query('BEGIN');
    const result = await fn(client);
    await client.query('COMMIT');
    return result;
  } catch (err) {
    try {
      await client.query('ROLLBACK');
    } catch (rollbackErr) {
      brokenConnection = rollbackErr; // don't give a broken connection back to the pool
    }
    throw err;
  } finally {
    client.release(brokenConnection);
  }
}

module.exports = { withTransaction };
