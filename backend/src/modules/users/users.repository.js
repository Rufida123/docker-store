// The only file in the users module that talks to the database.
const pool = require('../../shared/db');

async function create({ email, passwordHash, role }) {
  const { rows } = await pool.query(
    `INSERT INTO users (email, password_hash, role)
     VALUES ($1, $2, $3)
     RETURNING id, email, role, created_at`,
    [email, passwordHash, role]
  );
  return rows[0];
}

async function findByEmail(email) {
  const { rows } = await pool.query(
    'SELECT id, email, role, password_hash FROM users WHERE email = $1',
    [email]
  );
  return rows[0];
}

async function findById(id) {
  const { rows } = await pool.query(
    'SELECT id, email, role, created_at FROM users WHERE id = $1',
    [id]
  );
  return rows[0];
}

async function list() {
  const { rows } = await pool.query(
    'SELECT id, email, role, created_at FROM users ORDER BY created_at DESC'
  );
  return rows;
}

module.exports = { create, findByEmail, findById, list };
