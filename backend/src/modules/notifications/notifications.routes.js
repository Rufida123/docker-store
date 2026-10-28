// URLs of the notifications module. Every query is scoped to the authenticated user.
const express = require('express');
const service = require('./notifications.service');
const { authenticate, authorize } = require('../../shared/middleware/auth');
const { asyncHandler } = require('../../shared/errors');

const router = express.Router();

router.use(authenticate, authorize('customer', 'admin'));

// POST /notifications/devices  body: { "token": "<FCM token of this browser>" }
// Called by the front end after the user allowed notifications.
router.post('/devices', asyncHandler(async (req, res) => {
  res.status(201).json(await service.registerDevice(req.user, req.body || {}, req.get('user-agent')));
}));

// DELETE /notifications/devices/:token  (on sign out: this browser stops receiving pushes)
router.delete('/devices/:token', asyncHandler(async (req, res) => {
  await service.unregisterDevice(req.user, req.params.token);
  res.status(204).end();
}));

// GET /notifications?page=&limit=&is_read=true|false&type=order_created|order_status_changed
router.get('/', asyncHandler(async (req, res) => {
  res.json(await service.listForUser(req.user, req.query));
}));

router.patch('/read-all', asyncHandler(async (req, res) => {
  res.json(await service.markAllReadForUser(req.user));
}));

router.get('/:id', asyncHandler(async (req, res) => {
  res.json(await service.getForUser(req.user, req.params.id));
}));

router.patch('/:id/read', asyncHandler(async (req, res) => {
  res.json(await service.markReadForUser(req.user, req.params.id));
}));

module.exports = router;
