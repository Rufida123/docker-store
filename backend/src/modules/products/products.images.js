// Product images: checks, saves and serves the uploaded files.
// In Docker the folder is a named volume (see docker-compose.yml), so images survive rebuilds.
const fs = require('fs/promises');
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const { AppError } = require('../../shared/errors');

const UPLOAD_DIR = path.join(process.env.UPLOAD_DIR || path.join(process.cwd(), 'uploads'), 'products');
const PUBLIC_PATH = '/uploads/products';
const MAX_IMAGE_BYTES = 2 * 1024 * 1024; // 2 MB

const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

// The real type is read from the file's first bytes, never from its name or from the
// Content-Type the client sent (both are easy to fake). SVG is refused: it can carry scripts.
function detectType(buffer) {
  if (buffer.length >= 3 && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) {
    return 'jpg';
  }
  if (buffer.length >= 8 && buffer.subarray(0, 8).equals(PNG_SIGNATURE)) {
    return 'png';
  }
  if (
    buffer.length >= 12 &&
    buffer.toString('ascii', 0, 4) === 'RIFF' &&
    buffer.toString('ascii', 8, 12) === 'WEBP'
  ) {
    return 'webp';
  }
  return null;
}

// Saves the image under a new random name (the client's file name is never used,
// so it can't overwrite files or escape the folder). Returns the image's public URL.
async function save(buffer) {
  const ext = detectType(buffer);
  if (!ext) {
    throw new AppError(400, 'INVALID_IMAGE', 'Invalid image', [
      { field: 'image', message: 'image must be a JPEG, PNG or WebP file' },
    ]);
  }
  await fs.mkdir(UPLOAD_DIR, { recursive: true });
  const name = `${crypto.randomUUID()}.${ext}`;
  await fs.writeFile(path.join(UPLOAD_DIR, name), buffer, { flag: 'wx' });
  return `${PUBLIC_PATH}/${name}`;
}

// Deletes an image saved by save(), e.g. when saving the product itself failed.
async function remove(url) {
  await fs.unlink(path.join(UPLOAD_DIR, path.basename(url))).catch(() => {});
}

// Serves the saved images at /uploads/products/<name>. A missing file falls through to the 404 handler.
const serve = express.static(UPLOAD_DIR, {
  index: false,
  dotfiles: 'deny',
  maxAge: '7d', // names are random and never reused, so browsers may cache them
  setHeaders: (res) => {
    // helmet allows same-origin only; images must also load in a front end on another port/domain.
    res.set('Cross-Origin-Resource-Policy', 'cross-origin');
  },
});

module.exports = { save, remove, serve, PUBLIC_PATH, MAX_IMAGE_BYTES };
