// The only file in the notifications module that talks to the database.
const pool = require('../../shared/db');

const COLUMNS = 'id, user_id, order_id, type, title, message, metadata, is_read, read_at, created_at';

async function insert(client, notification) {
  const { rows } = await client.query(
    `INSERT INTO notifications (user_id, order_id, type, status, title, message, metadata)
     VALUES ($1, $2, $3, $4, $5, $6, $7::jsonb)
     ON CONFLICT (order_id, type, status) DO NOTHING
     RETURNING ${COLUMNS}`,
    [
      notification.userId,
      notification.orderId,
      notification.type,
      notification.status,
      notification.title,
      notification.message,
      JSON.stringify(notification.metadata),
    ]
  );
  return rows[0];
}

async function list({ userId, limit, offset }) {
  const [page, counts] = await Promise.all([
    pool.query(
      `SELECT ${COLUMNS}
         FROM notifications
        WHERE user_id = $1
        ORDER BY created_at DESC, id DESC
        LIMIT $2 OFFSET $3`,
      [userId, limit, offset]
    ),
    pool.query(
      `SELECT COUNT(*)::int AS total,
              COUNT(*) FILTER (WHERE is_read = FALSE)::int AS unread_count
         FROM notifications
        WHERE user_id = $1`,
      [userId]
    ),
  ]);
  return { rows: page.rows, ...counts.rows[0] };
}

async function findByIdForUser(id, userId) {
  const { rows } = await pool.query(
    `SELECT ${COLUMNS} FROM notifications WHERE id = $1 AND user_id = $2`,
    [id, userId]
  );
  return rows[0];
}

async function markRead(id, userId) {
  const { rows } = await pool.query(
    `UPDATE notifications
        SET is_read = TRUE, read_at = COALESCE(read_at, NOW())
      WHERE id = $1 AND user_id = $2
      RETURNING ${COLUMNS}`,
    [id, userId]
  );
  return rows[0];
}

async function markAllRead(userId) {
  const { rowCount } = await pool.query(
    `UPDATE notifications
        SET is_read = TRUE, read_at = NOW()
      WHERE user_id = $1 AND is_read = FALSE`,
    [userId]
  );
  return rowCount;
}

module.exports = { insert, list, findByIdForUser, markRead, markAllRead };
