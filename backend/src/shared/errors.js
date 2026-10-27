// One error type for the whole project.
// Every error the API returns has the same shape:
// { "error": { "code": "...", "message": "...", "details": [...] } }
class AppError extends Error {
  constructor(status, code, message, details) {
    super(message);
    this.status = status;
    this.code = code;
    this.details = details;
  }
}

// Express 4 does not catch errors thrown inside async handlers.
// Wrapping a handler with this sends those errors to the error middleware.
const asyncHandler = (fn) => (req, res, next) =>
  Promise.resolve(fn(req, res, next)).catch(next);

module.exports = { AppError, asyncHandler };
