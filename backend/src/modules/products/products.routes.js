// URLs of the products module. Handles request/response only; the logic lives in the service.
const express = require('express');
const multer = require('multer');
const service = require('./products.service');
const { MAX_IMAGE_BYTES } = require('./products.images');
const { authenticate, authorize } = require('../../shared/middleware/auth');
const { AppError, asyncHandler } = require('../../shared/errors');

const router = express.Router();

// The upload is kept in memory (max 2 MB) so its real type can be checked before anything
// is written to disk. Limits also cap the number of files and form fields.
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_IMAGE_BYTES, files: 1, fields: 10, fieldSize: 10 * 1024 },
});

// Reads one optional file field named "image" (multipart/form-data) and turns upload
// problems into the unified error format. Placed after authorize: only admins can upload.
function uploadImage(req, res, next) {
  upload.single('image')(req, res, (err) => {
    if (!err) return next();
    if (err.code === 'LIMIT_FILE_SIZE') {
      return next(
        new AppError(413, 'FILE_TOO_LARGE', 'Image is too large', [
          { field: 'image', message: `image must be at most ${MAX_IMAGE_BYTES / (1024 * 1024)} MB` },
        ])
      );
    }
    return next(
      new AppError(400, 'INVALID_UPLOAD', 'Invalid upload', [
        { field: err.field || 'image', message: err.message },
      ])
    );
  });
}

// GET /products  (public)  ?category=electronics&page=1&limit=20
router.get(
  '/',
  asyncHandler(async (req, res) => {
    res.json(await service.listProducts(req.query));
  })
);

// GET /products/:id  (public)
router.get(
  '/:id',
  asyncHandler(async (req, res) => {
    res.json(await service.getProduct(req.params.id));
  })
);

// POST /products  (admin only)
// multipart/form-data: name, price, stock?, category?, description?, image? (JPEG/PNG/WebP, max 2 MB)
// (a JSON body without an image also works)
router.post(
  '/',
  authenticate,
  authorize('admin'),
  uploadImage,
  asyncHandler(async (req, res) => {
    const product = await service.createProduct(req.body || {}, req.file);
    res.status(201).location(`${req.baseUrl}/${product.id}`).json(product);
  })
);

// PATCH /products/:id  (admin only)  JSON: { name?, description?, category?, price? }
router.patch(
  '/:id',
  authenticate,
  authorize('admin'),
  asyncHandler(async (req, res) => {
    res.json(await service.updateProduct(req.params.id, req.body || {}));
  })
);

// PATCH /products/:id/stock  (admin only)  JSON: { "change": 25 } or { "change": -3 }
router.patch(
  '/:id/stock',
  authenticate,
  authorize('admin'),
  asyncHandler(async (req, res) => {
    res.json(await service.changeStock(req.params.id, req.body || {}));
  })
);

module.exports = router;
