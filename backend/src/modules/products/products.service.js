// Business rules of the products module.
// For now: the stock operations other modules need (used by orders).
const repo = require('./products.repository');
const { AppError } = require('../../shared/errors');

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
      const current = await repo.findStock(client, item.product_id);
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

module.exports = { reserveStock, releaseStock };
