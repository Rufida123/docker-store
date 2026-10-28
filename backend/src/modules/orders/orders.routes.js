// URLs of the orders module. Handles request/response only; the logic lives in the service.
const express = require('express');
const service = require('./orders.service');
const { authenticate, authorize } = require('../../shared/middleware/auth');
const { asyncHandler } = require('../../shared/errors');

const router = express.Router();

// Every orders URL needs a valid token (401 otherwise).
router.use(authenticate);

// POST /orders  (customer only)
// Body: { "items": [{ "product_id": "<uuid>", "quantity": 2 }] }
router.post(
  '/',
  authorize('customer'),
  asyncHandler(async (req, res) => {
    const order = await service.createOrder(req.user, req.body || {});
    res.status(201).location(`${req.baseUrl}/${order.id}`).json(order);
  })
);

// GET /orders  (customer: own orders only, admin: all orders)
// Query: ?page=1&limit=20&status=pending  (+ &user_id=<uuid> for admins)
router.get(
  '/',
  authorize('customer', 'admin'),
  asyncHandler(async (req, res) => {
    res.json(await service.listOrders(req.user, req.query));
  })
);

// GET /orders/:id  (customer: own order only, admin: any order)
router.get(
  '/:id',
  authorize('customer', 'admin'),
  asyncHandler(async (req, res) => {
    res.json(await service.getOrder(req.user, req.params.id));
  })
);

// PATCH /orders/:id/status  (admin only)
// Body: { "status": "confirmed" }
router.patch(
  '/:id/status',
  authorize('admin'),
  asyncHandler(async (req, res) => {
    res.json(await service.changeStatus(req.user, req.params.id, req.body || {}));
  })
);

module.exports = router;
