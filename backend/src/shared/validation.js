// Small input checks used by more than one module.
const { AppError } = require('./errors');

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// Ids are checked before any query, so a bad id is a clear 400 and never a database error.
function isUuid(value) {
  return typeof value === 'string' && UUID_RE.test(value);
}

function validationError(details) {
  return new AppError(400, 'VALIDATION_ERROR', 'Invalid input', details);
}

// Reads ?page=&limit= from the query string. Defaults: page 1, limit 20, at most 100 per page.
function parsePagination(query, { defaultLimit = 20, maxLimit = 100 } = {}) {
  const page = query.page === undefined ? 1 : Number(query.page);
  const limit = query.limit === undefined ? defaultLimit : Number(query.limit);

  const details = [];
  if (!Number.isInteger(page) || page < 1) {
    details.push({ field: 'page', message: 'page must be a positive integer' });
  }
  if (!Number.isInteger(limit) || limit < 1 || limit > maxLimit) {
    details.push({ field: 'limit', message: `limit must be an integer between 1 and ${maxLimit}` });
  }
  if (details.length) throw validationError(details);

  return { page, limit, offset: (page - 1) * limit };
}

// Shape of every paginated list response.
function paginated(data, { page, limit }, total) {
  return { data, pagination: { page, limit, total, total_pages: Math.ceil(total / limit) } };
}

module.exports = { isUuid, validationError, parsePagination, paginated };
