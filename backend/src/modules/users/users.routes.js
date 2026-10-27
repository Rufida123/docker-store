// URLs of the users module. Handles request/response only; the logic lives in the service.
const express = require('express');
const rateLimit = require('express-rate-limit');
const service = require('./users.service');
const { authenticate, authorize } = require('../../shared/middleware/auth');
const { asyncHandler } = require('../../shared/errors');

const router = express.Router();

// Limit FAILED login attempts: 5 per 15 minutes for the same IP + email.
// Successful logins are not counted.
const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 5,
  standardHeaders: true,
  legacyHeaders: false,
  skipSuccessfulRequests: true,
  keyGenerator: (req) => `${req.ip}|${String((req.body && req.body.email) || '').toLowerCase()}`,
  handler: (req, res) =>
    res.status(429).json({
      error: {
        code: 'TOO_MANY_REQUESTS',
        message: 'Too many failed login attempts. Try again in 15 minutes.',
      },
    }),
});

// POST /auth/register  (public, creates a customer)
router.post(
  '/auth/register',
  asyncHandler(async (req, res) => {
    const user = await service.register(req.body || {});
    res.status(201).json(user);
  })
);

// POST /auth/login  (public, rate limited)
router.post(
  '/auth/login',
  loginLimiter,
  asyncHandler(async (req, res) => {
    const result = await service.login(req.body || {});
    res.json(result);
  })
);

// GET /auth/me  (any logged-in user)
router.get(
  '/auth/me',
  authenticate,
  asyncHandler(async (req, res) => {
    res.json(await service.getById(req.user.id));
  })
);

// GET /users  (admin only)
router.get(
  '/users',
  authenticate,
  authorize('admin'),
  asyncHandler(async (req, res) => {
    res.json(await service.listAll());
  })
);

module.exports = router;
