// URLs of the notifications module. Every query is scoped to the authenticated user.
const express = require('express');
const service = require('./notifications.service');
const { authenticate, authorize } = require('../../shared/middleware/auth');
const { asyncHandler } = require('../../shared/errors');

const router = express.Router();

router.use(authenticate, authorize('customer', 'admin'));

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
