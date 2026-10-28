// Public face of the notifications module. Other modules only import this file.
const router = require('./notifications.routes');
const { createForOrderEvent } = require('./notifications.service');

module.exports = { router, createForOrderEvent };
