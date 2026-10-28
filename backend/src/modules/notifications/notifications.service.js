// Business rules for persistent, user-owned notifications.
const repo = require('./notifications.repository');
const { AppError } = require('../../shared/errors');
const { isUuid, validationError, parsePagination, paginated } = require('../../shared/validation');

function checkNotificationId(id) {
  if (!isUuid(id)) {
    throw validationError([{ field: 'id', message: 'id must be a valid UUID' }]);
  }
}

// Called by the orders module with its transaction client. This is deliberately
// not an event listener: the notification must roll back with the order mutation.
function createForOrderEvent(client, { userId, orderId, type, status }) {
  const created = type === 'order_created';
  return repo.insert(client, {
    userId,
    orderId,
    type,
    status,
    title: created ? 'Order created' : 'Order status updated',
    message: created
      ? `Order ${orderId} has been created and is pending.`
      : `Order ${orderId} status changed to ${status}.`,
    metadata: { order_id: orderId, status },
  });
}

async function listForUser(user, query) {
  const pagination = parsePagination(query);
  const { rows, total, unread_count: unreadCount } = await repo.list({
    userId: user.id,
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

module.exports = {
  createForOrderEvent,
  listForUser,
  getForUser,
  markReadForUser,
  markAllReadForUser,
};
