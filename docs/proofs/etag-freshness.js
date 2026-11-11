// Freshness proof for HTTP caching (docs/performance.md, 6.5): a product edit
// is visible immediately, even to a client that holds the old ETag.
//
//   1. GET the product, remember its ETag
//   2. GET again with If-None-Match: <ETag>         -> 304, no body
//   3. as admin: change the price and the stock
//   4. GET at once with the OLD ETag                 -> 200, new price/stock, new ETag
//   5. GET with the NEW ETag                         -> 304
//   6. put the price and the stock back
//
// Plain Node (18+), no dependencies.
//   node etag-freshness.js
// Env: ADMIN_EMAIL / ADMIN_PASSWORD (the admin from .env), BASE (default
// http://localhost:3000), PRODUCT_ID (default: the Wireless Mouse sample).

const BASE = process.env.BASE || 'http://localhost:3000';
const PRODUCT = process.env.PRODUCT_ID || '00000000-0000-4000-8000-000000000001';
const EMAIL = process.env.ADMIN_EMAIL;
const PASSWORD = process.env.ADMIN_PASSWORD;

const checks = [];
function check(name, ok, detail) {
  checks.push({ name, ok });
  console.log(`   ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
}

async function request(method, path, { token, body, etag } = {}) {
  const started = Date.now();
  const res = await fetch(BASE + '/api/v1' + path, {
    method,
    headers: {
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(etag ? { 'If-None-Match': etag } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  return {
    status: res.status,
    etag: res.headers.get('etag'),
    cacheControl: res.headers.get('cache-control'),
    bytes: text.length,
    json: text ? JSON.parse(text) : null,
    ms: Date.now() - started,
  };
}

function show(label, r) {
  const data = r.json && r.status === 200 ? `  price ${r.json.price}, stock ${r.json.stock}` : '';
  console.log(`   ${label}: ${r.status}  ETag ${r.etag}  Cache-Control "${r.cacheControl}"  body ${r.bytes} B  ${r.ms} ms${data}`);
}

async function main() {
  if (!EMAIL || !PASSWORD) {
    console.error('Set ADMIN_EMAIL and ADMIN_PASSWORD (the admin account from .env).');
    process.exit(2);
  }

  console.log(`ETag freshness proof against ${BASE}, product ${PRODUCT}\n`);

  const login = await request('POST', '/auth/login', { body: { email: EMAIL, password: PASSWORD } });
  if (login.status !== 200) {
    console.error(`Admin login failed: ${login.status} ${JSON.stringify(login.json)}`);
    process.exit(2);
  }
  const token = login.json.token;

  console.log('1. First read');
  const first = await request('GET', `/products/${PRODUCT}`);
  show('GET', first);
  check('200 with an ETag', first.status === 200 && Boolean(first.etag));
  check('Cache-Control is no-cache, no max-age', /no-cache/.test(first.cacheControl || '') && !/max-age/.test(first.cacheControl || ''));

  console.log('\n2. Same request with If-None-Match: <that ETag>');
  const again = await request('GET', `/products/${PRODUCT}`, { etag: first.etag });
  show('GET', again);
  check('304 Not Modified with an empty body', again.status === 304 && again.bytes === 0);

  const oldPrice = first.json.price;
  const newPrice = (Number(oldPrice) + 1).toFixed(2);
  console.log(`\n3. Admin changes the price ${oldPrice} -> ${newPrice} and adds 5 to the stock`);
  const priceEdit = await request('PATCH', `/products/${PRODUCT}`, { token, body: { price: newPrice } });
  const stockEdit = await request('PATCH', `/products/${PRODUCT}/stock`, { token, body: { change: 5 } });
  console.log(`   PATCH price: ${priceEdit.status}, PATCH stock: ${stockEdit.status}`);
  check('both edits accepted', priceEdit.status === 200 && stockEdit.status === 200);

  console.log('\n4. Immediately: GET with the OLD ETag');
  const after = await request('GET', `/products/${PRODUCT}`, { etag: first.etag });
  show('GET', after);
  check('200, not 304: the old copy is stale', after.status === 200);
  check('new price', after.json && after.json.price === newPrice, `${after.json && after.json.price}`);
  check('new stock', after.json && after.json.stock === first.json.stock + 5, `${after.json && after.json.stock}`);
  check('new ETag', Boolean(after.etag) && after.etag !== first.etag);

  console.log('\n5. GET with the NEW ETag');
  const revalidated = await request('GET', `/products/${PRODUCT}`, { etag: after.etag });
  show('GET', revalidated);
  check('304 for the new ETag', revalidated.status === 304);

  console.log('\n6. Put the product back');
  const priceBack = await request('PATCH', `/products/${PRODUCT}`, { token, body: { price: oldPrice } });
  const stockBack = await request('PATCH', `/products/${PRODUCT}/stock`, { token, body: { change: -5 } });
  console.log(`   PATCH price: ${priceBack.status}, PATCH stock: ${stockBack.status}`);
  const restored = await request('GET', `/products/${PRODUCT}`);
  show('GET', restored);

  const failed = checks.filter((c) => !c.ok).length;
  console.log(`\n${failed === 0 ? 'ALL CHECKS PASSED' : `${failed} CHECK(S) FAILED`} (${checks.length} checks)`);
  process.exit(failed === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(err);
  process.exit(2);
});
