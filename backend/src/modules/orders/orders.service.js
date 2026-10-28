// Business rules of the orders module (validation, stock, ownership, status changes).
const repo = require('./orders.repository');
const products = require('../products'); // only through the products module's index.js
const { withTransaction } = require('../../shared/transaction');
const { publish } = require('../../shared/events');
const { AppError } = require('../../shared/errors');
const {
  isUuid,
  validationError,
  parsePagination,
  paginated,
} = require('../../shared/validation');

const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];

// Allowed status changes. delivered and cancelled are final.
const TRANSITIONS = {
  pending: ['confirmed', 'cancelled'],
  confirmed: ['shipped', 'cancelled'],
  shipped: ['delivered'],
  delivered: [],
  cancelled: [],
};

const MAX_ITEMS = 50;
const MAX_QUANTITY = 100;

function checkOrderId(id) {
  if (!isUuid(id)) {
    throw validationError([{ field: 'id', message: 'id must be a valid UUID' }]);
  }
}

// Body: { items: [{ product_id, quantity }] }. Anything else (price, user_id, status...)
// is ignored: prices come from the database and the owner comes from the token.
// The same product listed twice is merged into one line.
function parseItems(body) {
  const { items } = body;
  if (!Array.isArray(items) || items.length === 0 || items.length > MAX_ITEMS) {
    throw validationError([
      { field: 'items', message: `items must be an array of 1 to ${MAX_ITEMS} entries` },
    ]);
  }

  const details = [];
  const merged = new Map(); // product_id -> quantity

  items.forEach((item, i) => {
    const productId = item && typeof item.product_id === 'string' ? item.product_id.toLowerCase() : null;
    const quantity = item ? item.quantity : undefined;

    if (!isUuid(productId)) {
      details.push({ field: `items[${i}].product_id`, message: 'product_id must be a valid UUID' });
    }
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > MAX_QUANTITY) {
      details.push({
        field: `items[${i}].quantity`,
        message: `quantity must be an integer between 1 and ${MAX_QUANTITY}`,
      });
    }
    if (isUuid(productId) && Number.isInteger(quantity)) {
      merged.set(productId, (merged.get(productId) || 0) + quantity);
    }
  });

  if (details.length) throw validationError(details);

  return [...merged].map(([product_id, quantity]) => ({ product_id, quantity }));
}

// Full order: header + items + status history. Call only after the access check.
async function loadOrder(order) {
  const [items, history] = await Promise.all([
    repo.findItems(order.id),
    repo.findHistory(order.id),
  ]);
  return { ...order, items, history };
}

// POST /orders
// One transaction: reserve stock, create the order, its items and its first history row.
// If any step fails (unknown product, not enough stock...) nothing is saved.
async function createOrder(user, body) {
  const items = parseItems(body);

  const orderId = await withTransaction(async (client) => {
    const reserved = await products.reserveStock(client, items);
    const id = await repo.insertOrder(client, user.id, reserved);
    await repo.insertItems(client, id, reserved);
    await repo.insertHistory(client, {
      orderId: id,
      fromStatus: null,
      toStatus: 'pending',
      changedBy: user.id,
    });
    return id;
  });

  const order = await loadOrder(await repo.findById(orderId));
  publish('order.created', { orderId, userId: user.id, total: order.total });
  return order;
}

// GET /orders/:id
// A customer only ever gets their own orders: the owner check is part of the SQL query.
// Someone else's order returns the same 404 as a missing one, so order ids can't be probed.
async function getOrder(user, id) {
  checkOrderId(id);
  const ownerId = user.role === 'admin' ? null : user.id;
  const order = await repo.findById(id.toLowerCase(), ownerId);
  if (!order) {
    throw new AppError(404, 'ORDER_NOT_FOUND', 'Order not found');
  }
  return loadOrder(order);
}

// GET /orders?page=&limit=&status=&user_id=
// Customers always get their own orders (a user_id in the query is ignored).
// Admins get all orders and may filter by user_id.
async function listOrders(user, query) {
  const pagination = parsePagination(query);
  const filters = {};

  if (query.status !== undefined) {
    if (!STATUSES.includes(query.status)) {
      throw validationError([
        { field: 'status', message: `status must be one of: ${STATUSES.join(', ')}` },
      ]);
    }
    filters.status = query.status;
  }

  if (user.role === 'admin') {
    if (query.user_id !== undefined) {
      if (!isUuid(query.user_id)) {
        throw validationError([{ field: 'user_id', message: 'user_id must be a valid UUID' }]);
      }
      filters.userId = query.user_id.toLowerCase();
    }
  } else {
    filters.userId = user.id;
  }

  const { rows, total } = await repo.list({
    ...filters,
    limit: pagination.limit,
    offset: pagination.offset,
  });
  return paginated(rows, pagination, total);
}

// PATCH /orders/:id/status  (admin only, enforced in the routes)
async function changeStatus(user, id, body) {
  checkOrderId(id);
  const { status } = body;
  if (!STATUSES.includes(status)) {
    throw validationError([
      { field: 'status', message: `status must be one of: ${STATUSES.join(', ')}` },
    ]);
  }

  const change = await withTransaction(async (client) => {
    // Row lock: if two admins change the same order at once, the second waits and is
    // checked against the first one's result (e.g. an order can't be cancelled twice).
    const order = await repo.lockById(client, id.toLowerCase());
    if (!order) {
      throw new AppError(404, 'ORDER_NOT_FOUND', 'Order not found');
    }

    const allowed = TRANSITIONS[order.status];
    if (!allowed.includes(status)) {
      throw new AppError(
        409,
        'INVALID_STATUS_TRANSITION',
        `Cannot change order status from ${order.status} to ${status}`,
        [
          {
            field: 'status',
            message: allowed.length
              ? `Allowed next statuses: ${allowed.join(', ')}`
              : `${order.status} is a final status`,
          },
        ]
      );
    }

    if (status === 'cancelled') {
      // Put the reserved stock back, in the same transaction as the status change.
      const items = await repo.findItems(order.id, client);
      await products.releaseStock(client, items);
    }

    await repo.updateStatus(client, order.id, status);
    await repo.insertHistory(client, {
      orderId: order.id,
      fromStatus: order.status,
      toStatus: status,
      changedBy: user.id,
    });

    return { orderId: order.id, userId: order.user_id, from: order.status, to: status, changedBy: user.id };
  });

  publish('order.status_changed', change);
  return loadOrder(await repo.findById(change.orderId));
}

module.exports = { createOrder, getOrder, listOrders, changeStatus };
