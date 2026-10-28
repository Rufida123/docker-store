// Public face of the notifications module. Other modules only import this file.
const router = require('./notifications.routes');
const { createForOrderEvent } = require('./notifications.service');

// Push notifications: listens to 'order.created' and 'order.status_changed'.
require('./notifications.listeners');
// Logs at startup whether Firebase push is on or off.
require('./notifications.push').isEnabled();

module.exports = { router, createForOrderEvent };
