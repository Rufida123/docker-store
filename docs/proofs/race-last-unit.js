// Race proof: N customers order the last unit at the same moment; exactly one
// succeeds. Plain Node (18+), no dependencies.
//
// Usage:
//   node race-last-unit.js [bases] [customers]
//     bases      comma-separated API instances (default the four dev servers
//                http://localhost:3001..3004; a single URL works too)
//     customers  how many simultaneous orders (default 20)
// Env: ADMIN_EMAIL, ADMIN_PASSWORD (required).

const bases = (process.argv[2] || 'http://localhost:3001,http://localhost:3002,http://localhost:3003,http://localhost:3004').split(',');
const CUSTOMERS = Number(process.argv[3] || 20);
const { ADMIN_EMAIL, ADMIN_PASSWORD } = process.env;

const base = (i) => bases[i % bases.length];

async function api(b, method, path, token, body) {
  const res = await fetch(b + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  let json = null;
  try { json = await res.json(); } catch {}
  return { status: res.status, json };
}

async function main() {
  if (!ADMIN_EMAIL || !ADMIN_PASSWORD) {
    console.error('Set ADMIN_EMAIL and ADMIN_PASSWORD first.');
    process.exit(1);
  }

  const admin = (await api(base(0), 'POST', '/api/v1/auth/login', null, { email: ADMIN_EMAIL, password: ADMIN_PASSWORD })).json.token;

  const product = (await api(base(0), 'POST', '/api/v1/products', admin, {
    name: `Race Last Unit ${Date.now()}`,
    price: '42.00',
    stock: 1,
  })).json;
  console.log(`Product created: ${product.id} (stock ${product.stock})`);

  const email = `race.${Date.now()}@example.com`;
  await api(base(0), 'POST', '/api/v1/auth/register', null, { email, password: 'Password123!' });
  const customer = (await api(base(0), 'POST', '/api/v1/auth/login', null, { email, password: 'Password123!' })).json.token;

  console.log(`Firing ${CUSTOMERS} simultaneous orders for the last unit...\n`);
  const results = await Promise.all(
    Array.from({ length: CUSTOMERS }, (_, i) =>
      api(base(i), 'POST', '/api/v1/orders', customer, { items: [{ product_id: product.id, quantity: 1 }] })),
  );

  const counts = new Map();
  for (const r of results) {
    const key = `${r.status} ${r.json?.error?.code ?? 'OK'}`;
    counts.set(key, (counts.get(key) || 0) + 1);
  }

  console.log('result               count');
  console.log('-------------------  -----');
  for (const [key, count] of [...counts].sort()) {
    console.log(`${key.padEnd(21)}${count}`);
  }

  const created = results.filter((r) => r.status === 201).length;
  const refused = results.filter((r) => r.status === 409 && r.json?.error?.code === 'INSUFFICIENT_STOCK').length;
  const finalStock = (await api(base(0), 'GET', `/api/v1/products/${product.id}`)).json.stock;

  console.log(`\nfinal stock: ${finalStock}`);
  const ok = created === 1 && refused === CUSTOMERS - 1 && finalStock === 0;
  console.log(ok
    ? `\nPASS: exactly 1 of ${CUSTOMERS} orders succeeded, ${refused} were refused, stock ended at 0.`
    : `\nFAIL: expected 1x201 and ${CUSTOMERS - 1}x409 with final stock 0.`);
  process.exitCode = ok ? 0 : 1;
}

main().catch((err) => { console.error(err); process.exitCode = 1; });
