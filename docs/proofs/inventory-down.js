// Failure-case proof: what the app does when the inventory service is stopped
// or slow. Plain Node (18+), no dependencies.
//
// Usage (against one API instance, default http://localhost:3000):
//   node inventory-down.js baseline   - prints the demo product's current stock
//   node inventory-down.js stopped    - run with the inventory service STOPPED
//   node inventory-down.js slow       - run with SIMULATE_DELAY_MS=5000 on the service
//                                       (in Docker: docker compose pause inventory)
//   node inventory-down.js recovered <expectedStock>
//                                     - run after the service is back; waits for
//                                       it and checks the stock is unchanged
// Env: CUSTOMER_EMAIL / CUSTOMER_PASSWORD (optional; a fresh account is
// registered when not given). BASE overrides the API URL.

const BASE = process.env.BASE || 'http://localhost:3000';
const PRODUCT = process.env.PRODUCT_ID || '00000000-0000-4000-8000-000000000001'; // Wireless Mouse
const mode = process.argv[2];

async function api(method, path, token, body) {
  const started = Date.now();
  const res = await fetch(BASE + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let json = null;
  try { json = await res.json(); } catch {}
  return { status: res.status, json, ms: Date.now() - started };
}

let failures = 0;
function check(name, ok, extra = '') {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? `  (${extra})` : ''}`);
  if (!ok) failures += 1;
}

async function customerToken() {
  if (process.env.CUSTOMER_EMAIL) {
    return (await api('POST', '/api/v1/auth/login', null, {
      email: process.env.CUSTOMER_EMAIL,
      password: process.env.CUSTOMER_PASSWORD,
    })).json.token;
  }
  const email = `down.${Date.now()}@example.com`;
  await api('POST', '/api/v1/auth/register', null, { email, password: 'Password123!' });
  return (await api('POST', '/api/v1/auth/login', null, { email, password: 'Password123!' })).json.token;
}

async function orderCount(token) {
  return (await api('GET', '/api/v1/orders?limit=1', token)).json?.pagination?.total ?? 0;
}

async function main() {
  if (mode === 'baseline') {
    const res = await api('GET', `/api/v1/products/${PRODUCT}`);
    console.log(`stock of ${res.json.name}: ${res.json.stock} (${res.json.stock_status})`);
    console.log(`\nAfter the failure demos, verify with: node inventory-down.js recovered ${res.json.stock}`);
    return;
  }

  if (mode === 'stopped') {
    console.log('Expecting the inventory service to be STOPPED...\n');
    const token = await customerToken();
    const before = await orderCount(token);

    const details = await api('GET', `/api/v1/products/${PRODUCT}`);
    check('product details still answer 200', details.status === 200, `${details.ms}ms`);
    check('stock is null with stock_status "unavailable"',
      details.json.stock === null && details.json.stock_status === 'unavailable');

    const order = await api('POST', '/api/v1/orders', token, { items: [{ product_id: PRODUCT, quantity: 1 }] });
    check('order is refused with 503 INVENTORY_UNAVAILABLE',
      order.status === 503 && order.json?.error?.code === 'INVENTORY_UNAVAILABLE');
    check('the user is answered quickly, not left waiting', order.ms < 1500, `${order.ms}ms`);
    console.log(`server message: "${order.json?.error?.message}"`);

    check('no order was created', (await orderCount(token)) === before);
  } else if (mode === 'slow') {
    console.log('Expecting the inventory service to be SLOW (SIMULATE_DELAY_MS=5000, or docker compose pause inventory)...\n');
    const token = await customerToken();
    const before = await orderCount(token);

    const order = await api('POST', '/api/v1/orders', token, { items: [{ product_id: PRODUCT, quantity: 1 }] });
    check('order is refused with 504 INVENTORY_TIMEOUT',
      order.status === 504 && order.json?.error?.code === 'INVENTORY_TIMEOUT');
    check('the deadline cuts the wait at about 2 s', order.ms > 1500 && order.ms < 3500, `${order.ms}ms`);
    console.log(`server message: "${order.json?.error?.message}"`);

    check('no order was created', (await orderCount(token)) === before);
    console.log('\nThe reservation (if it ever landed) is neutralised: the release is parked in the');
    console.log('outbox, and a tombstone on the inventory side blocks the late reserve either way.');
  } else if (mode === 'recovered') {
    const expected = Number(process.argv[3]);
    console.log('Waiting for the inventory service to answer again...');
    let details;
    for (let i = 0; i < 60; i += 1) {
      details = await api('GET', `/api/v1/products/${PRODUCT}`);
      if (details.json?.stock_status === 'available') break;
      await new Promise((r) => setTimeout(r, 2000));
    }
    check('inventory answers again', details.json?.stock_status === 'available');
    check(`stock is unchanged (${expected})`, details.json?.stock === expected, `stock=${details.json?.stock}`);
  } else {
    console.error('Usage: node inventory-down.js baseline | stopped | slow | recovered <expectedStock>');
    process.exit(1);
  }

  console.log(failures === 0 ? '\nALL CHECKS PASSED' : `\n${failures} FAILURES`);
  process.exitCode = failures === 0 ? 0 : 1;
}

main().catch((err) => { console.error(err); process.exitCode = 1; });
