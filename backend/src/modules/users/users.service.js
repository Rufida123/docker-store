// Business rules of the users module (validation, hashing, tokens).
const bcrypt = require('bcryptjs');
const jwt = require('jsonwebtoken');
const repo = require('./users.repository');
const { AppError } = require('../../shared/errors');

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

async function register({ email, password }) {
  const details = [];
  // typeof checks: JSON can also send numbers or arrays, which must be a 400, not a crash.
  if (typeof email !== 'string' || email.length > 255 || !EMAIL_RE.test(email)) {
    details.push({ field: 'email', message: 'A valid email is required' });
  }
  if (typeof password !== 'string' || password.length < 8) {
    details.push({ field: 'password', message: 'Password must be at least 8 characters' });
  }
  if (details.length) {
    throw new AppError(400, 'VALIDATION_ERROR', 'Invalid input', details);
  }

  try {
    const passwordHash = await bcrypt.hash(password, 12);
    // Public registration ALWAYS creates a customer. A user can never choose their own role.
    return await repo.create({
      email: email.toLowerCase(),
      passwordHash,
      role: 'customer',
    });
  } catch (err) {
    if (err.code === '23505') {
      throw new AppError(409, 'EMAIL_TAKEN', 'Email already registered');
    }
    throw err;
  }
}

async function login({ email, password }) {
  if (typeof email !== 'string' || typeof password !== 'string' || !email || !password) {
    throw new AppError(400, 'VALIDATION_ERROR', 'Email and password are required');
  }

  const user = await repo.findByEmail(email.toLowerCase());

  // Same error for unknown email and wrong password, so attackers can't tell which
  const valid = user && (await bcrypt.compare(password, user.password_hash));
  if (!valid) {
    throw new AppError(401, 'INVALID_CREDENTIALS', 'Invalid credentials');
  }

  // The role goes inside the token, so every module can check it without a DB query.
  const token = jwt.sign({ sub: user.id, role: user.role }, process.env.JWT_SECRET, {
    algorithm: 'HS256',
    expiresIn: process.env.JWT_EXPIRES_IN || '15m',
  });

  return { token, token_type: 'Bearer' };
}

async function getById(id) {
  const user = await repo.findById(id);
  if (!user) {
    throw new AppError(404, 'USER_NOT_FOUND', 'User not found');
  }
  return user;
}

function listAll() {
  return repo.list();
}

// Creates the first admin from ADMIN_EMAIL / ADMIN_PASSWORD (if they are set and no such user exists).
async function ensureAdmin() {
  const email = (process.env.ADMIN_EMAIL || '').toLowerCase();
  const password = process.env.ADMIN_PASSWORD || '';
  if (!email || !password) {
    console.log('ADMIN_EMAIL / ADMIN_PASSWORD not set: skipping admin creation');
    return;
  }
  if (await repo.findByEmail(email)) return;

  const passwordHash = await bcrypt.hash(password, 12);
  await repo.create({ email, passwordHash, role: 'admin' });
  console.log(`Admin user created: ${email}`);
}

module.exports = { register, login, getById, listAll, ensureAdmin };
