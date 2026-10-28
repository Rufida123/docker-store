// In-process event bus: lets one module react to another without importing it.
// Example: orders publishes 'order.status_changed', and the notifications module
// subscribes to it. orders never needs to know that notifications exists.
const { EventEmitter } = require('events');

const bus = new EventEmitter();

// Publish only AFTER the database transaction has committed, so listeners never act
// on data that was rolled back. Listeners run after the current request code, and a
// failing listener is logged without affecting the request that published the event.
function publish(event, payload) {
  for (const listener of bus.listeners(event)) {
    Promise.resolve()
      .then(() => listener(payload))
      .catch((err) => console.error(`Listener for "${event}" failed:`, err));
  }
}

function subscribe(event, listener) {
  bus.on(event, listener);
}

module.exports = { publish, subscribe };
