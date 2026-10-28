// Business rules of the products module (validation, images, stock).
const repo = require('./products.repository');
const images = require('./products.images');
const { AppError } = require('../../shared/errors');
const {
  isUuid,
  validationError,
  parsePagination,
  paginated,
} = require('../../shared/validation');

const MAX_STOCK = 1000000;
const PRICE_RE = /^\d{1,8}(\.\d{1,2})?$/; // 0 to 99999999.99, at most 2 decimals
const INT_RE = /^-?\d+$/;

// ---- input helpers ----
// Multipart form fields always arrive as strings, JSON fields as numbers: both are accepted.

function toPrice(value) {
  let text = '';
  if (typeof value === 'number') text = String(value);
  else if (typeof value === 'string') text = value.trim();
  return PRICE_RE.test(text) ? text : null; // kept as a string, stored as NUMERIC (exact)
}

function toInt(value) {
  if (typeof value === 'number') return Number.isInteger(value) ? value : null;
  if (typeof value === 'string' && INT_RE.test(value.trim())) return Number(value.trim());
  return null;
}

// Optional text: undefined = not sent, null or '' = clear it, otherwise a trimmed string.
function readText(body, field, maxLength, details) {
  const value = body[field];
  if (value === undefined) return undefined;
  if (value === null || (typeof value === 'string' && value.trim() === '')) return null;
  if (typeof value !== 'string' || value.trim().length > maxLength) {
    details.push({ field, message: `${field} must be text of at most ${maxLength} characters` });
    return undefined;
  }
  return value.trim();
}

function checkProductId(id) {
  if (!isUuid(id)) {
    throw validationError([{ field: 'id', message: 'id must be a valid UUID' }]);
  }
}

// Reads name, description, category and price. With partial=true (edits) every field is optional.
function parseDetails(body, { partial }) {
  const details = [];
  const data = {};

  if (body.name !== undefined || !partial) {
    const name = typeof body.name === 'string' ? body.name.trim() : '';
    if (!name || name.length > 200) {
      details.push({ field: 'name', message: 'name is required (1 to 200 characters)' });
    } else {
      data.name = name;
    }
  }

  const description = readText(body, 'description', 2000, details);
  if (description !== undefined) data.description = description;

  // Categories are stored in lower case so "Electronics" and "electronics" are one category.
  const category = readText(body, 'category', 100, details);
  if (category !== undefined) data.category = category && category.toLowerCase();

  if (body.price !== undefined || !partial) {
    const price = toPrice(body.price);
    if (price === null) {
      details.push({
        field: 'price',
        message: 'price must be a number from 0 to 99999999.99 with at most 2 decimals',
      });
    } else {
      data.price = price;
    }
  }

  return { data, details };
}

// ---- use cases ----

// POST /products  (admin). file = the optional uploaded image (from multer).
async function createProduct(body, file) {
  const { data, details } = parseDetails(body, { partial: false });

  let stock = 0;
  if (body.stock !== undefined) {
    stock = toInt(body.stock);
    if (stock === null || stock < 0 || stock > MAX_STOCK) {
      details.push({ field: 'stock', message: `stock must be an integer from 0 to ${MAX_STOCK}` });
    }
  }
  if (details.length) throw validationError(details);

  // The image is checked and saved only after all other fields are valid.
  const imageUrl = file ? await images.save(file.buffer) : null;
  try {
    return await repo.create({
      name: data.name,
      description: data.description || null,
      category: data.category || null,
      price: data.price,
      stock,
      imageUrl,
    });
  } catch (err) {
    if (imageUrl) await images.remove(imageUrl); // no orphan files
    throw err;
  }
}

// GET /products?category=&page=&limit=  (public)
async function listProducts(query) {
  const pagination = parsePagination(query);
  let category;
  if (query.category !== undefined) {
    if (typeof query.category !== 'string' || !query.category.trim() || query.category.length > 100) {
      throw validationError([{ field: 'category', message: 'category must be text of 1 to 100 characters' }]);
    }
    category = query.category.trim().toLowerCase();
  }
  const { rows, total } = await repo.list({
    category,
    limit: pagination.limit,
    offset: pagination.offset,
  });
  return paginated(rows, pagination, total);
}

// GET /products/:id  (public)
async function getProduct(id) {
  checkProductId(id);
  const product = await repo.findById(id.toLowerCase());
  if (!product) {
    throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
  }
  return product;
}

// PATCH /products/:id  (admin): name, description, category, price.
// Stock is not edited here, see changeStock.
async function updateProduct(id, body) {
  checkProductId(id);
  const { data, details } = parseDetails(body, { partial: true });
  if (body.stock !== undefined) {
    details.push({ field: 'stock', message: 'use PATCH /products/:id/stock to change stock' });
  }
  if (!details.length && !Object.keys(data).length) {
    details.push({ field: 'body', message: 'send at least one of: name, description, category, price' });
  }
  if (details.length) throw validationError(details);

  const product = await repo.update(id.toLowerCase(), data);
  if (!product) {
    throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
  }
  return product;
}

// PATCH /products/:id/stock  (admin)  body: { change: 25 } to add, { change: -3 } to remove.
// Relative and atomic, so it is safe while customers are placing orders at the same time.
async function changeStock(id, body) {
  checkProductId(id);
  const change = toInt(body.change);
  if (change === null || change === 0 || Math.abs(change) > MAX_STOCK) {
    throw validationError([
      { field: 'change', message: `change must be a non-zero integer between -${MAX_STOCK} and ${MAX_STOCK}` },
    ]);
  }

  const product = await repo.changeStock(id.toLowerCase(), change);
  if (product) return product;

  const current = await repo.findStock(id.toLowerCase());
  if (!current) {
    throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found');
  }
  throw new AppError(409, 'INSUFFICIENT_STOCK', 'Stock cannot go below zero', [
    { field: 'change', available: current.stock, message: `Only ${current.stock} in stock` },
  ]);
}

// ---- used by other modules (through index.js) ----

// Every request locks product rows in the same order (sorted by id).
// Without this, order A (mouse, keyboard) and order B (keyboard, mouse) could each
// hold one lock while waiting for the other: a deadlock.
function sortById(items) {
  return [...items].sort((a, b) => (a.product_id < b.product_id ? -1 : 1));
}

// Reserves stock for an order. `client` is the caller's transaction, so if anything
// later fails (or one product is short), all stock taken here is given back by ROLLBACK.
// items: [{ product_id, quantity }]  ->  [{ product_id, name, price, quantity }]
async function reserveStock(client, items) {
  const reserved = [];

  for (const item of sortById(items)) {
    const product = await repo.decrementStock(client, item.product_id, item.quantity);

    if (!product) {
      const current = await repo.findStock(item.product_id, client);
      if (!current) {
        throw new AppError(404, 'PRODUCT_NOT_FOUND', 'Product not found', [
          { field: 'items', product_id: item.product_id, message: 'No product with this id' },
        ]);
      }
      throw new AppError(409, 'INSUFFICIENT_STOCK', 'Not enough stock for this order', [
        {
          field: 'items',
          product_id: item.product_id,
          requested: item.quantity,
          available: current.stock,
          message: `Only ${current.stock} left in stock`,
        },
      ]);
    }

    reserved.push({
      product_id: product.id,
      name: product.name,
      price: product.price,
      quantity: item.quantity,
    });
  }

  return reserved;
}

// Gives stock back (for example when an order is cancelled), inside the caller's transaction.
// items: [{ product_id, quantity }]
async function releaseStock(client, items) {
  for (const item of sortById(items)) {
    await repo.incrementStock(client, item.product_id, item.quantity);
  }
}

module.exports = {
  createProduct,
  listProducts,
  getProduct,
  updateProduct,
  changeStock,
  reserveStock,
  releaseStock,
};
