// Public face of the products module.
// Other modules may only import from this file, never from the files next to it.
const express = require('express');
const { reserveStock, releaseStock } = require('./products.service');

// The products API (create with image, list, filter by category, pagination) is not built yet.
// It will get its own products.routes.js, following the same structure as the users module.
const router = express.Router();

// reserveStock / releaseStock take the caller's transaction client, so stock changes
// commit or roll back together with the caller's own changes (used by orders).
module.exports = { router, reserveStock, releaseStock };
