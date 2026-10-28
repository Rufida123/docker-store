// Business rules for persistent, user-owned notifications and their push delivery.
const repo = require('./notifications.repository');
const push = require('./notifications.push');
const { AppError } = require('../../shared/errors');
const { isUuid, validationError, parsePagination, paginated } = require('../../shared/validation');

const TYPES = ['order_created', 'order_status_changed'];
const MAX_TOKEN_LENGTH = 4096;

// What the customer reads, per order status. "#1a2b3c4d" = the first 8 characters of the order id.
const TEXTS = {
  pending: { title: 'Order placed', message: (ref) => `Order ${ref} was placed and is waiting for confirmation.` },
  confirmed: { title: 'Order confirmed', message: (ref) => `Order ${ref} was confirmed.` },
  shipped: { title: 'Order shipped', message: (ref) => `Order ${ref} is on its way.` },
  delivered: { title: 'Order delivered', message: (ref) => `Order ${ref} was delivered.` },
  cancelled: { title: 'Order cancelled', message: (ref) => `Order ${ref} was cancelled.` },
};

function checkNotificationId(id) {
  if (!isUuid(id)) {
    throw validationError([{ field: 'id', message: 'id must be a valid UUID' }]);
  }
}

// Called by the orders module with its transaction client. This is deliberately
// not an event listener: the notification must roll back with the order mutation.
function createForOrderEvent(client, { userId, orderId, type, status }) {
  const text = TEXTS[status];
  return repo.insert(client, {
    userId,
    orderId,
    type,
    status,
    title: text.title,
    message: text.message(`#${orderId.slice(0, 8)}`),
    metadata: { order_id: orderId, status },
  });
}

// GET /notifications?page=&limit=&is_read=true|false&type=order_created|order_status_changed
async function listForUser(user, query) {
  const pagination = parsePagination(query);
  const details = [];

  let isRead;
  if (query.is_read !== undefined) {
    if (query.is_read === 'true') isRead = true;
    else if (query.is_read === 'false') isRead = false;
    else details.push({ field: 'is_read', message: 'is_read must be true or false' });
  }
  if (query.type !== undefined && !TYPES.includes(query.type)) {
    details.push({ field: 'type', message: `type must be one of: ${TYPES.join(', ')}` });
  }
  if (details.length) throw validationError(details);

  const { rows, total, unread_count: unreadCount } = await repo.list({
    userId: user.id,
    isRead,
    type: query.type,
    limit: pagination.limit,
    offset: pagination.offset,
  });
  return { ...paginated(rows, pagination, total), unread_count: unreadCount };
}

async function getForUser(user, id) {
  checkNotificationId(id);
  const notification = await repo.findByIdForUser(id.toLowerCase(), user.id);
  if (!notification) throw new AppError(404, 'NOTIFICATION_NOT_FOUND', 'Notification not found');
  return notification;
}

async function markReadForUser(user, id) {
  checkNotificationId(id);
  const notification = await repo.markRead(id.toLowerCase(), user.id);
  if (!notification) throw new AppError(404, 'NOTIFICATION_NOT_FOUND', 'Notification not found');
  return notification;
}

async function markAllReadForUser(user) {
  const updated = await repo.markAllRead(user.id);
  return { updated };
}

// ---- push devices ----

function checkToken(token) {
  if (typeof token !== 'string' || !token.trim() || token.length > MAX_TOKEN_LENGTH || /\s/.test(token)) {
    throw validationError([{ field: 'token', message: 'token must be the FCM registration token of this browser' }]);
  }
}

// POST /notifications/devices  body: { token }
async function registerDevice(user, body, userAgent) {
  checkToken(body.token);
  await repo.saveDevice({
    token: body.token,
    userId: user.id,
    userAgent: typeof userAgent === 'string' ? userAgent.slice(0, 300) : null,
  });
  return { registered: true, push_enabled: push.isEnabled() };
}

// DELETE /notifications/devices/:token  (on sign out). Only the user's own token is removed.
async function unregisterDevice(user, token) {
  checkToken(token);
  await repo.deleteDevice(token, user.id);
}

// Runs AFTER the order transaction committed (see notifications.listeners.js).
// Sends the stored notification to every browser of its owner.
async function pushForOrderEvent({ orderId, type, status }) {
  if (!push.isEnabled()) return;

  const notification = await repo.findByEvent({ orderId, type, status });
  if (!notification) return;

  const tokens = await repo.listDeviceTokens(notification.user_id);
  if (!tokens.length) return;

  const { deadTokens } = await push.sendToTokens(tokens, {
    notification_id: notification.id,
    order_id: orderId,
    type,
    status,
    title: notification.title,
    body: notification.message,
    url: `/orders/${orderId}`,
  });
  await repo.deleteDeviceTokens(deadTokens);
}

module.exports = {
  createForOrderEvent,
  listForUser,
  getForUser,
  markReadForUser,
  markAllReadForUser,
  registerDevice,
  unregisterDevice,
  pushForOrderEvent,
};
