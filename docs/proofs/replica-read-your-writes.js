// Read-your-writes proof (docs/performance.md, 6.6): with a replica that is
// deliberately behind, a user still sees what they just wrote.
//
// Start the replica with an artificial replay delay first:
//   $env:REPLICA_APPLY_DELAY="3s"; docker compose up -d db_replica     (PowerShell)
//   REPLICA_APPLY_DELAY=3s docker compose up -d db_replica             (bash)
// then, from the repository root:
//   node docs/proofs/replica-read-your-writes.js
//
//   1. a new customer creates an order -> GET /orders and GET /orders/{id}
//      show it at once;
//   2. the same row read directly on db_replica at that moment is NOT there
//      yet (the lag is real), and appears after the delay;
//   3. the admin edits a product -> GET /products/{id} shows the new price at
//      once, with a new ETag (cache + replica + ETag together), while the
//      replica itself still has the old price.
//
// Plain Node (18+), no dependencies; uses the docker CLI to query the replica.
// Env: ADMIN_EMAIL / ADMIN_PASSWORD (default: from .env), BASE (default
// http://localhost:3000), PRODUCT_ID (default: the Wireless Mouse sample).

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..', '..');
const dotenv = Object.fromEntries(
  (fs.existsSync(path.join(ROOT, '.env')) ? fs.readFileSync(path.join(ROOT, '.env'), 'utf8') : '')
    .split(/\r?\n/)
    .map((l) => /^([A-Z0-9_]+)=(.*)$/.exec(l.trim()))
    .filter(Boolean)
    .map((m) => [m[1], m[2]])
);
const env = (k, d) => process.env[k] || dotenv[k] || d;

const BASE = env('BASE', 'http://localhost:3000');
const PRODUCT = env('PRODUCT_ID', '00000000-0000-4000-8000-000000000001');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const checks = [];
function check(name, ok, detail) {
  checks.push(ok);
  console.log(`   ${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
}

async function api(method, p, { token, body, etag } = {}) {
  const started = Date.now();
  const res = await fetch(BASE + '/api/v1' + p, {
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
    dbRead: res.headers.get('x-db-read'), // only with PERF_QUERY_LOG=true
    json: text ? JSON.parse(text) : null,
    ms: Date.now() - started,
  };
}

/** One value read directly on the replica, bypassing the API. */
function onReplica(sql) {
  return execFileSync('docker', [
    'compose', 'exec', '-T', 'db_replica',
    'psql', '-U', env('POSTGRES_USER', 'postgres'), '-d', env('POSTGRES_DB', 'postgres'), '-tAc', sql,
  ], { cwd: ROOT, encoding: 'utf8' }).trim();
}

const via = (r) => (r.dbRead ? `, reads: ${r.dbRead}` : '');

async function main() {
  const delay = onReplica('SHOW recovery_min_apply_delay');
  console.log(`Read-your-writes proof against ${BASE}; replica recovery_min_apply_delay = ${delay}\n`);
  if (delay === '0' || delay === '0ms') {
    console.log('   NOTE: no artificial lag; set REPLICA_APPLY_DELAY=3s and recreate db_replica for a meaningful run.\n');
  }

  // A fresh customer, so the proof never depends on existing data.
  const email = `ryw.${Date.now()}@example.com`;
  const password = 'ProofPass123!';
  await api('POST', '/auth/register', { body: { email, password } });
  const customer = (await api('POST', '/auth/login', { body: { email, password } })).json.token;

  console.log('1. Customer creates an order, then reads it back at once');
  const created = await api('POST', '/orders', { token: customer, body: { items: [{ product_id: PRODUCT, quantity: 1 }] } });
  check('order created', created.status === 201, `${created.status}, id ${created.json && created.json.id}`);
  const orderId = created.json.id;

  const list = await api('GET', '/orders', { token: customer });
  const listed = list.status === 200 && list.json.data.some((o) => o.id === orderId);
  check('GET /orders shows it', listed, `${list.status}, ${list.ms} ms${via(list)}`);
  const detail = await api('GET', `/orders/${orderId}`, { token: customer });
  check('GET /orders/{id} shows it', detail.status === 200 && detail.json.id === orderId, `${detail.status}, ${detail.ms} ms${via(detail)}`);

  console.log('\n2. The same order read directly on the replica, right now');
  const onReplicaNow = onReplica(`SELECT count(*) FROM orders WHERE id = '${orderId}'`);
  check('the replica does NOT have it yet (the lag is real)', onReplicaNow === '0', `count = ${onReplicaNow}`);
  const waitMs = 500 + (parseInt(delay, 10) || 0) * (delay.endsWith('ms') ? 1 : 1000);
  await sleep(waitMs);
  const onReplicaLater = onReplica(`SELECT count(*) FROM orders WHERE id = '${orderId}'`);
  check(`after ${waitMs} ms the replica has it`, onReplicaLater === '1', `count = ${onReplicaLater}`);

  console.log('\n3. Admin edits a product, then it is read at once');
  const admin = (await api('POST', '/auth/login', { body: { email: env('ADMIN_EMAIL'), password: env('ADMIN_PASSWORD') } })).json.token;
  const before = await api('GET', `/products/${PRODUCT}`);
  const oldPrice = before.json.price;
  const newPrice = (Number(oldPrice) + 1).toFixed(2);
  const edit = await api('PATCH', `/products/${PRODUCT}`, { token: admin, body: { price: newPrice } });
  check(`price ${oldPrice} -> ${newPrice} accepted`, edit.status === 200);

  const after = await api('GET', `/products/${PRODUCT}`, { etag: before.etag });
  check('GET /products/{id} with the old ETag: 200, not 304', after.status === 200, `${after.status}${via(after)}`);
  check('it shows the new price at once', after.json && after.json.price === newPrice, after.json && after.json.price);
  check('with a new ETag', Boolean(after.etag) && after.etag !== before.etag);
  const replicaPrice = onReplica(`SELECT price FROM products WHERE id = '${PRODUCT}'`);
  check('while the replica itself still has the old price', replicaPrice === oldPrice, `replica: ${replicaPrice}`);

  // Put the price back (not a check).
  await api('PATCH', `/products/${PRODUCT}`, { token: admin, body: { price: oldPrice } });

  const failed = checks.filter((ok) => !ok).length;
  console.log(`\n${failed === 0 ? 'ALL CHECKS PASSED' : `${failed} CHECK(S) FAILED`} (${checks.length} checks)`);
  process.exit(failed === 0 ? 0 : 1);
}

main().catch((err) => {
  console.error(err);
  process.exit(2);
});
