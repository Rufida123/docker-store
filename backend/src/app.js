const express = require('express');
const helmet = require('helmet');

const users = require('./modules/users');
const products = require('./modules/products');
const orders = require('./modules/orders');
const notifications = require('./modules/notifications');
const { notFound, errorHandler } = require('./shared/middleware/errorHandler');

const app = express();

app.use(helmet()); // adds security headers to every response
app.use(express.json());

app.get('/health', (req, res) => res.json({ status: 'ok' }));

// API version 1. Every module's URLs live under /api/v1, and each module owns its URLs.
// A future breaking change goes under /api/v2 while v1 keeps working for existing clients.
const v1 = express.Router();
v1.use('/', users.router);              // /api/v1/auth/*, /api/v1/users
v1.use('/products', products.router);
v1.use('/orders', orders.router);
v1.use('/notifications', notifications.router);
app.use('/api/v1', v1);

app.use(notFound);
app.use(errorHandler);

module.exports = app;
