// Integration tests against a real PostgreSQL database and the real gRPC
// server on an ephemeral port.
//
// They ALWAYS run on their own database (TEST_DATABASE_URL, or the configured
// DATABASE_URL with its database swapped for "inventory_test"), never on the
// service's database: the suite truncates tables between tests.
require('../src/env');

const testUrl = process.env.TEST_DATABASE_URL
  || (() => {
    const url = new URL(process.env.DATABASE_URL);
    url.pathname = '/inventory_test';
    return url.toString();
  })();
process.env.DATABASE_URL = testUrl;

const { test, before, beforeEach, after } = require('node:test');
const assert = require('node:assert/strict');
const { randomUUID } = require('node:crypto');
const grpc = require('@grpc/grpc-js');

const pool = require('../src/db');
const { migrate } = require('../src/migrate');
const { buildServer } = require('../src/service');
const { loadDefinition } = require('../src/proto');

let server;
let inventory;
let admin;

const call = (client, method, request, deadlineMs = 5000) =>
  new Promise((resolve, reject) => {
    client[method](request, { deadline: new Date(Date.now() + deadlineMs) }, (err, res) =>
      err ? reject(err) : resolve(res)
    );
  });

const available = async (productId) => {
  const res = await call(inventory, 'GetStock', { product_ids: [productId] });
  return res.levels[0].available;
};

before(async () => {
  // Guard: never run against anything but a *_test database.
  const { rows } = await pool.query('SELECT current_database() AS db');
  if (!rows[0].db.endsWith('_test')) {
    console.error(`Refusing to run tests against "${rows[0].db}": the database name must end with _test.`);
    process.exit(1);
  }

  await migrate(pool);

  server = buildServer();
  const port = await new Promise((resolve, reject) => {
    server.bindAsync('127.0.0.1:0', grpc.ServerCredentials.createInsecure(), (err, p) =>
      err ? reject(err) : resolve(p)
    );
  });

  const definition = loadDefinition();
  inventory = new definition.Inventory(`127.0.0.1:${port}`, grpc.credentials.createInsecure());
  admin = new definition.InventoryAdmin(`127.0.0.1:${port}`, grpc.credentials.createInsecure());
});

beforeEach(async () => {
  await pool.query('TRUNCATE reservation_items, reservations, stock');
});

after(async () => {
  server.forceShutdown();
  await pool.end();
});

test('GetStock reports unknown products as 0 and known ones exactly', async () => {
  const known = randomUUID();
  const unknown = randomUUID();
  await call(admin, 'AdjustStock', { product_id: known, change: 7 });

  const res = await call(inventory, 'GetStock', { product_ids: [known, unknown, known] });
  assert.deepEqual(res.levels, [
    { product_id: known, available: 7 },
    { product_id: unknown, available: 0 },
  ]);
});

test('AdjustStock upserts on a positive change and refuses going below zero', async () => {
  const id = randomUUID();

  const created = await call(admin, 'AdjustStock', { product_id: id, change: 5 });
  assert.equal(created.status, 'APPLIED');
  assert.equal(created.available, 5);

  const refused = await call(admin, 'AdjustStock', { product_id: id, change: -9 });
  assert.equal(refused.status, 'REFUSED');
  assert.equal(refused.available, 5);

  const lowered = await call(admin, 'AdjustStock', { product_id: id, change: -5 });
  assert.equal(lowered.status, 'APPLIED');
  assert.equal(lowered.available, 0);
});

test('ReserveStock is all-or-nothing: the first shortage rolls everything back', async () => {
  const a = randomUUID();
  const b = randomUUID();
  await call(admin, 'AdjustStock', { product_id: a, change: 5 });
  await call(admin, 'AdjustStock', { product_id: b, change: 1 });

  const res = await call(inventory, 'ReserveStock', {
    reservation_id: randomUUID(),
    items: [
      { product_id: a, quantity: 2 },
      { product_id: b, quantity: 2 },
    ],
  });

  assert.equal(res.status, 'REFUSED');
  assert.deepEqual(res.shortage, { product_id: b, requested: 2, available: 1 });
  assert.equal(await available(a), 5); // nothing was taken from a either
  assert.equal(await available(b), 1);
});

test('ReserveStock sums duplicate lines and replays idempotently', async () => {
  const a = randomUUID();
  const reservation = randomUUID();
  await call(admin, 'AdjustStock', { product_id: a, change: 5 });

  const first = await call(inventory, 'ReserveStock', {
    reservation_id: reservation,
    items: [
      { product_id: a, quantity: 1 },
      { product_id: a, quantity: 2 },
    ],
  });
  assert.equal(first.status, 'RESERVED');
  assert.equal(await available(a), 2);

  const replay = await call(inventory, 'ReserveStock', {
    reservation_id: reservation,
    items: [{ product_id: a, quantity: 3 }],
  });
  assert.equal(replay.status, 'RESERVED');
  assert.equal(await available(a), 2); // no stock taken twice
});

test('ReleaseStock returns stock once and is idempotent', async () => {
  const a = randomUUID();
  const reservation = randomUUID();
  await call(admin, 'AdjustStock', { product_id: a, change: 4 });
  await call(inventory, 'ReserveStock', {
    reservation_id: reservation,
    items: [{ product_id: a, quantity: 3 }],
  });
  assert.equal(await available(a), 1);

  const first = await call(inventory, 'ReleaseStock', { reservation_id: reservation });
  assert.equal(first.status, 'RELEASED');
  assert.equal(await available(a), 4);

  const second = await call(inventory, 'ReleaseStock', { reservation_id: reservation });
  assert.equal(second.status, 'ALREADY_RELEASED');
  assert.equal(await available(a), 4);

  // A reserve after a release can never take stock again with that id.
  const late = await call(inventory, 'ReserveStock', {
    reservation_id: reservation,
    items: [{ product_id: a, quantity: 1 }],
  });
  assert.equal(late.status, 'ALREADY_RELEASED');
  assert.equal(await available(a), 4);
});

test('releasing an unknown id leaves a tombstone that blocks a late reserve', async () => {
  const a = randomUUID();
  const reservation = randomUUID();
  await call(admin, 'AdjustStock', { product_id: a, change: 2 });

  const release = await call(inventory, 'ReleaseStock', { reservation_id: reservation });
  assert.equal(release.status, 'NOT_FOUND');

  const late = await call(inventory, 'ReserveStock', {
    reservation_id: reservation,
    items: [{ product_id: a, quantity: 1 }],
  });
  assert.equal(late.status, 'ALREADY_RELEASED');
  assert.equal(await available(a), 2);
});

test('parallel reserves for the last unit: exactly one wins', async () => {
  const a = randomUUID();
  await call(admin, 'AdjustStock', { product_id: a, change: 1 });

  const results = await Promise.all(
    Array.from({ length: 10 }, () =>
      call(inventory, 'ReserveStock', {
        reservation_id: randomUUID(),
        items: [{ product_id: a, quantity: 1 }],
      })
    )
  );

  const reserved = results.filter((r) => r.status === 'RESERVED').length;
  const refused = results.filter((r) => r.status === 'REFUSED').length;
  assert.equal(reserved, 1);
  assert.equal(refused, 9);
  assert.equal(await available(a), 0);
});

test('opposite-order multi-item reserves finish without deadlocks', async () => {
  const d = randomUUID();
  const e = randomUUID();
  await call(admin, 'AdjustStock', { product_id: d, change: 50 });
  await call(admin, 'AdjustStock', { product_id: e, change: 50 });

  const results = await Promise.all(
    Array.from({ length: 20 }, (_, i) =>
      call(inventory, 'ReserveStock', {
        reservation_id: randomUUID(),
        items:
          i % 2 === 0
            ? [{ product_id: d, quantity: 1 }, { product_id: e, quantity: 1 }]
            : [{ product_id: e, quantity: 1 }, { product_id: d, quantity: 1 }],
      })
    )
  );

  assert.equal(results.filter((r) => r.status === 'RESERVED').length, 20);
  assert.equal(await available(d), 30);
  assert.equal(await available(e), 30);
});

test('bad input is INVALID_ARGUMENT, not a refusal', async () => {
  await assert.rejects(
    call(inventory, 'ReserveStock', { reservation_id: 'nope', items: [{ product_id: randomUUID(), quantity: 1 }] }),
    (err) => err.code === grpc.status.INVALID_ARGUMENT
  );
  await assert.rejects(
    call(inventory, 'ReserveStock', { reservation_id: randomUUID(), items: [] }),
    (err) => err.code === grpc.status.INVALID_ARGUMENT
  );
  await assert.rejects(
    call(admin, 'AdjustStock', { product_id: randomUUID(), change: 0 }),
    (err) => err.code === grpc.status.INVALID_ARGUMENT
  );
  await assert.rejects(
    call(inventory, 'GetStock', { product_ids: ['not-a-uuid'] }),
    (err) => err.code === grpc.status.INVALID_ARGUMENT
  );
});
