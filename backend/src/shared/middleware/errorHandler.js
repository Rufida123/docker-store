const { AppError } = require('../errors');

function send(res, status, code, message, details) {
  const error = { code, message };
  if (details) error.details = details;
  return res.status(status).json({ error });
}

function notFound(req, res) {
  return send(res, 404, 'NOT_FOUND', 'Route not found');
}

// Must have 4 parameters so Express treats it as an error handler.
// eslint-disable-next-line no-unused-vars
function errorHandler(err, req, res, next) {
  if (err instanceof AppError) {
    return send(res, err.status, err.code, err.message, err.details);
  }
  if (err.type === 'entity.parse.failed') {
    return send(res, 400, 'INVALID_JSON', 'Request body is not valid JSON');
  }
  console.error(err);
  return send(res, 500, 'INTERNAL_ERROR', 'Internal server error');
}

module.exports = { notFound, errorHandler };
