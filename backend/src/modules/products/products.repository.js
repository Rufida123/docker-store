// The only file in the products module that talks to the database.
// These functions take `client`: they always run inside the caller's transaction.

// Takes `quantity` from stock only if that much is left, in ONE statement.
// UPDATE locks the product row, so concurrent orders for the same product wait for each
// other, and `stock >= $2` is re-checked on the latest value. Stock can never go negative.
// Returns the product (id, name, price), or undefined if the product is missing or short.
async function decrementStock(client, productId, quantity) {
  const { rows } = await client.query(
    `UPDATE products
        SET stock = stock - $2, updated_at = NOW()
      WHERE id = $1 AND stock >= $2
      RETURNING id, name, price`,
    [productId, quantity]
  );
  return rows[0];
}

async function incrementStock(client, productId, quantity) {
  await client.query(
    'UPDATE products SET stock = stock + $2, updated_at = NOW() WHERE id = $1',
    [productId, quantity]
  );
}

async function findStock(client, productId) {
  const { rows } = await client.query('SELECT id, stock FROM products WHERE id = $1', [productId]);
  return rows[0];
}

module.exports = { decrementStock, incrementStock, findStock };
