// Public face of the products module.
// Other modules may only import from this file, never from the files next to it.
const router = require('./products.routes');
const { reserveStock, releaseStock } = require('./products.service');
const images = require('./products.images');

module.exports = {
  router,
  // Serves uploaded product images; app.js mounts it at imagesPath.
  images: images.serve,
  imagesPath: images.PUBLIC_PATH,
  // reserveStock / releaseStock take the caller's transaction client, so stock changes
  // commit or roll back together with the caller's own changes (used by orders).
  reserveStock,
  releaseStock,
};
