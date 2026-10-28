// Public face of the orders module.
// Other modules may only import from this file, never from the files next to it.
//
// Events this module publishes on the shared event bus (shared/events.js),
// always after the change has been committed:
//   'order.created'         { orderId, userId, total }
//   'order.status_changed'  { orderId, userId, from, to, changedBy }
// Other modules (e.g. notifications) subscribe to these instead of importing orders.
const router = require('./orders.routes');

module.exports = { router };
