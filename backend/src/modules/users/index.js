// Public face of the users module.
// Other modules may only import from this file, never from the files next to it.
const router = require('./users.routes');
const { ensureAdmin } = require('./users.service');

module.exports = { router, ensureAdmin };
