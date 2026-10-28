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

// filters: isRead (true/false) and type are optional. `total` counts the filtered list;
// `unread_count` is always all of the user's unread notifications (for the bell badge).
async function list({ userId, isRead, type, limit, offset }) {
  const where = ['user_id = $1'];
  const params = [userId];
  if (isRead !== undefined) {
    params.push(isRead);
    where.push(`is_read = $${params.length}`);
  }
  if (type) {
    params.push(type);
    where.push(`type = $${params.length}`);
  }
  const whereSql = where.join(' AND ');

  const [page, counts] = await Promise.all([
    pool.query(
      `SELECT ${COLUMNS}
         FROM notifications
        WHERE ${whereSql}
        ORDER BY created_at DESC, id DESC
        LIMIT $${params.length + 1} OFFSET $${params.length + 2}`,
      [...params, limit, offset]
    ),
    pool.query(
      `SELECT COUNT(*) FILTER (WHERE ${whereSql})::int AS total,
              COUNT(*) FILTER (WHERE is_read = FALSE)::int AS unread_count
         FROM notifications
        WHERE user_id = $1`,
      params
    ),
  ]);
  return { rows: page.rows, ...counts.rows[0] };
}

// The notification written for one order event (order_id + type + status is unique).
async function findByEvent({ orderId, type, status }) {
  const { rows } = await pool.query(
    `SELECT ${COLUMNS} FROM notifications WHERE order_id = $1 AND type = $2 AND status = $3`,
    [orderId, type, status]
  );
  return rows[0];
}

// ---- devices (browsers that receive push notifications) ----

// Registers a browser for a user. If the token was registered by another user before
// (someone else signed in on this browser), it now belongs to this user only.
async function saveDevice({ token, userId, userAgent }) {
  await pool.query(
    `INSERT INTO notification_devices (token, user_id, user_agent)
     VALUES ($1, $2, $3)
     ON CONFLICT (token) DO UPDATE
        SET user_id = EXCLUDED.user_id,
            user_agent = EXCLUDED.user_agent,
            last_seen_at = NOW()`,
    [token, userId, userAgent]
  );
}

// Only removes the token if it belongs to this user.
async function deleteDevice(token, userId) {
  const { rowCount } = await pool.query(
    'DELETE FROM notification_devices WHERE token = $1 AND user_id = $2',
    [token, userId]
  );
  return rowCount;
}

async function listDeviceTokens(userId) {
  const { rows } = await pool.query(
    'SELECT token FROM notification_devices WHERE user_id = $1',
    [userId]
  );
  return rows.map((r) => r.token);
}

// Tokens Firebase reported as no longer valid (app uninstalled, permission revoked...).
async function deleteDeviceTokens(tokens) {
  if (!tokens.length) return;
  await pool.query('DELETE FROM notification_devices WHERE token = ANY($1::text[])', [tokens]);
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

module.exports = {
  insert,
  list,
  findByIdForUser,
  findByEvent,
  markRead,
  markAllRead,
  saveDevice,
  deleteDevice,
  listDeviceTokens,
  deleteDeviceTokens,
};
