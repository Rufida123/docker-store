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

// Each module owns its URLs.
app.use('/', users.router);              // /auth/*, /users
app.use('/products', products.router);
app.use('/orders', orders.router);
app.use('/notifications', notifications.router);

app.use(notFound);
app.use(errorHandler);

module.exports = app;
