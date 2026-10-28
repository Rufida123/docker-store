// Reacts to events other modules publish on the shared event bus (shared/events.js).
// The orders module publishes them only after its transaction committed, so a push is
// never sent for an order change that was rolled back.
const { subscribe } = require('../../shared/events');
const service = require('./notifications.service');

function sendPush(event) {
  return service.pushForOrderEvent(event).catch((err) => {
    // A push failure (Firebase down, network...) never affects the order itself:
    // the notification is already stored and visible in the app.
    console.error(`Push for order ${event.orderId} (${event.status}) failed:`, err.message);
  });
}

subscribe('order.created', ({ orderId }) =>
  sendPush({ orderId, type: 'order_created', status: 'pending' })
);

subscribe('order.status_changed', ({ orderId, to }) =>
  sendPush({ orderId, type: 'order_status_changed', status: to })
);
