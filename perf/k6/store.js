// Load test for the store API: p50 / p95 / p99 per endpoint at a fixed request rate.
// How to seed and run it: docs/performance.md.
//
//   docker compose --profile perf run --rm -e PROFILE=smoke k6
//   docker compose --profile perf run --rm -e LABEL=baseline k6
//
// Env: BASE_URL, PROFILE (smoke|baseline), RATE (requests/s, all scenarios),
// DURATION, LABEL, PRODUCTS / USERS / HEAVY_USERS (must match perf:seed),
// HEAVY_POOL / LIGHT_POOL (users logged in during setup), PERF_PASSWORD,
// SEED, RESULTS_DIR.
import http from 'k6/http';
import { check, fail, randomSeed } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';
import { textSummary } from 'https://jslib.k6.io/k6-summary/0.0.2/index.js';
import { PRODUCT, chunks, distinctInts, errorCode, perfUuid, pick, randInt, renderTable } from './lib.js';

const BASE_URL = (__ENV.BASE_URL || 'http://backend:3000/api/v1').replace(/\/$/, '');
const PROFILES = {
  smoke: { rate: 5, duration: '20s' },
  baseline: { rate: 40, duration: '2m' },
};
const PROFILE = __ENV.PROFILE || 'baseline';
if (!PROFILES[PROFILE]) throw new Error(`PROFILE must be one of: ${Object.keys(PROFILES).join(', ')}`);
const RATE = Number(__ENV.RATE || PROFILES[PROFILE].rate);
const DURATION = __ENV.DURATION || PROFILES[PROFILE].duration;
const LABEL = __ENV.LABEL || PROFILE;
const RESULTS_DIR = __ENV.RESULTS_DIR || '/results';
// Like a browser: remember the ETag per URL and send If-None-Match (ETAG=0 turns it off).
const SEND_ETAG = (__ENV.ETAG || '1') !== '0';

const PRODUCTS = Number(__ENV.PRODUCTS || 50000);
const USERS = Number(__ENV.USERS || 5000);
const HEAVY_USERS = Number(__ENV.HEAVY_USERS || 300);
const HEAVY_POOL = Number(__ENV.HEAVY_POOL || 40);
const LIGHT_POOL = Number(__ENV.LIGHT_POOL || 120);
const PASSWORD = __ENV.PERF_PASSWORD || 'PerfPass123!';
const PAGE_SIZE = 20; // the API default
const CATALOG_PAGES = Math.ceil((PRODUCTS + 4) / PAGE_SIZE); // + the 4 sample products

// Share of the total request rate per scenario. Ordering is rare next to browsing.
const MIX = {
  browse_list: 0.3,
  product_detail: 0.25,
  my_orders: 0.15,
  order_detail: 0.12,
  my_notifications: 0.13,
  create_order: 0.05,
};
const ENDPOINTS = Object.keys(MIX);

// Same random sequence per VU on every run.
randomSeed(Number(__ENV.SEED || 42) * 1000 + (__VU || 0));

// ---- metrics ---------------------------------------------------------------
// Responses by category, tagged by endpoint. 409 INSUFFICIENT_STOCK and 429 are
// counted on their own and are NOT part of the error rate.
const STATUS = {
  ok_2xx: new Counter('status_2xx'),
  not_modified_304: new Counter('status_304'),
  stock_409: new Counter('status_409_stock'),
  too_many_429: new Counter('status_429'),
  other_4xx: new Counter('status_4xx_other'),
  server_5xx: new Counter('status_5xx'),
  network: new Counter('status_network'),
};
const realErrors = new Rate('real_errors'); // 5xx, network failures, unexpected 4xx
// Response time of full 200 answers vs 304 revalidations.
const duration200 = new Trend('duration_200', true);
const duration304 = new Trend('duration_304', true);
const queryCount = new Trend('query_count'); // from X-Query-Count (PERF_QUERY_LOG=true only)
const queryTime = new Trend('query_time_ms'); // from X-Query-Time-Ms
// Catalog cache lookups per request, from X-Cache-Hits / X-Cache-Misses (PERF_QUERY_LOG=true only).
const cacheHits = new Counter('cache_hits');
const cacheMisses = new Counter('cache_misses');
// Read routing (X-DB-Read, X-Route-Us, X-Replica-Connect-Us, X-Refill-*; PERF_QUERY_LOG=true only).
const readsReplica = new Counter('reads_replica');
const readsPrimary = new Counter('reads_primary');
const routeUs = new Trend('route_us');
const replicaConnectUs = new Trend('replica_connect_us');
const refillReplica = new Counter('refill_replica');
const refillPrimary = new Counter('refill_primary');

// Thresholds that always pass: they only make k6 keep per-endpoint sub-metrics,
// so the summary shows p50/p95/p99 and counts for each endpoint.
const thresholds = {};
for (const e of ENDPOINTS) {
  thresholds[`http_req_duration{endpoint:${e}}`] = ['max>=0'];
  thresholds[`http_reqs{endpoint:${e}}`] = ['count>=0'];
  thresholds[`real_errors{endpoint:${e}}`] = ['rate>=0'];
  thresholds[`duration_200{endpoint:${e}}`] = ['max>=0'];
  thresholds[`duration_304{endpoint:${e}}`] = ['max>=0'];
  thresholds[`data_received{endpoint:${e}}`] = ['count>=0'];
  thresholds[`query_count{endpoint:${e}}`] = ['max>=0'];
  thresholds[`query_time_ms{endpoint:${e}}`] = ['max>=0'];
  thresholds[`cache_hits{endpoint:${e}}`] = ['count>=0'];
  thresholds[`cache_misses{endpoint:${e}}`] = ['count>=0'];
  for (const n of ['reads_replica', 'reads_primary', 'refill_replica', 'refill_primary']) thresholds[`${n}{endpoint:${e}}`] = ['count>=0'];
  thresholds[`route_us{endpoint:${e}}`] = ['max>=0'];
  thresholds[`replica_connect_us{endpoint:${e}}`] = ['max>=0'];
  for (const counter of Object.values(STATUS)) thresholds[`${counter.name}{endpoint:${e}}`] = ['count>=0'];
}

const scenarios = {};
for (const [name, share] of Object.entries(MIX)) {
  const rate = Math.max(1, Math.round(RATE * share));
  scenarios[name] = {
    executor: 'constant-arrival-rate',
    exec: name,
    rate,
    timeUnit: '1s',
    duration: DURATION,
    preAllocatedVUs: Math.max(2, rate * 2),
    maxVUs: Math.max(10, rate * 20),
    tags: { endpoint: name },
  };
}

export const options = {
  scenarios,
  thresholds,
  setupTimeout: '180s',
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

// ---- requests --------------------------------------------------------------
function record(res, endpoint) {
  const tags = { endpoint };
  let category;
  if (res.status === 0) category = 'network';
  else if (res.status === 304) category = 'not_modified_304';
  else if (res.status >= 200 && res.status < 300) category = 'ok_2xx';
  else if (res.status === 409 && errorCode(res) === 'INSUFFICIENT_STOCK') category = 'stock_409';
  else if (res.status === 429) category = 'too_many_429';
  else if (res.status >= 500) category = 'server_5xx';
  else category = 'other_4xx';

  STATUS[category].add(1, tags);
  if (res.status === 200) duration200.add(res.timings.duration, tags);
  if (res.status === 304) duration304.add(res.timings.duration, tags);
  realErrors.add(category === 'network' || category === 'server_5xx' || category === 'other_4xx', tags);

  const qc = res.headers['X-Query-Count'];
  if (qc !== undefined) {
    queryCount.add(Number(qc), tags);
    queryTime.add(Number(res.headers['X-Query-Time-Ms'] || 0), tags);
  }
  const ch = res.headers['X-Cache-Hits'];
  if (ch !== undefined) {
    cacheHits.add(Number(ch), tags);
    cacheMisses.add(Number(res.headers['X-Cache-Misses'] || 0), tags);
  }
  const read = res.headers['X-Db-Read'];
  if (read !== undefined) {
    if (read === 'replica') readsReplica.add(1, tags);
    if (read === 'primary') readsPrimary.add(1, tags);
    routeUs.add(Number(res.headers['X-Route-Us'] || 0), tags);
    if (read === 'replica') replicaConnectUs.add(Number(res.headers['X-Replica-Connect-Us'] || 0), tags);
    refillReplica.add(Number(res.headers['X-Refill-Replica'] || 0), tags);
    refillPrimary.add(Number(res.headers['X-Refill-Primary'] || 0), tags);
  }
  return res;
}

const authHeaders = (token) => ({ Authorization: `Bearer ${token}`, Accept: 'application/json' });

// Per VU (one VU behaves like one browser): URL -> last ETag received.
const etags = {};

function get(path, endpoint, token, revalidate = false) {
  const url = `${BASE_URL}${path}`;
  const headers = token ? authHeaders(token) : { Accept: 'application/json' };
  if (revalidate && SEND_ETAG && etags[url]) headers['If-None-Match'] = etags[url];
  const res = http.get(url, { headers, tags: { endpoint, name: endpoint } });
  if (revalidate && res.status === 200 && res.headers['Etag']) etags[url] = res.headers['Etag'];
  return record(res, endpoint);
}

// ---- setup: log in a pool of perf users once, reuse their tokens -----------
// Login is limited only for FAILED attempts (5 per 15 min per IP + email), so
// successful logins here never hit the limit.
export function setup() {
  const heavy = Array.from({ length: HEAVY_POOL }, (_, i) => 1 + Math.floor((i * HEAVY_USERS) / HEAVY_POOL));
  const light = Array.from(
    { length: LIGHT_POOL },
    (_, i) => HEAVY_USERS + 1 + Math.floor((i * (USERS - HEAVY_USERS)) / LIGHT_POOL)
  );
  const wanted = heavy.map((n) => ({ n, heavy: true })).concat(light.map((n) => ({ n, heavy: false })));

  const users = [];
  for (const batch of chunks(wanted, 10)) {
    const responses = http.batch(
      batch.map((u) => ({
        method: 'POST',
        url: `${BASE_URL}/auth/login`,
        body: JSON.stringify({ email: `perf-user-${u.n}@example.test`, password: PASSWORD }),
        params: { headers: { 'Content-Type': 'application/json' }, tags: { endpoint: 'setup', name: 'setup_login' } },
      }))
    );
    responses.forEach((res, i) => {
      if (res.status === 200 && res.json('token')) users.push({ ...batch[i], token: res.json('token') });
      else console.warn(`login of perf-user-${batch[i].n} failed: ${res.status} ${errorCode(res)}`);
    });
  }
  if (users.length === 0) {
    fail('No perf user could log in. Run `php artisan perf:seed` first (see docs/performance.md).');
  }

  // Each user's own order ids and page counts, for my_orders / order_detail / my_notifications.
  for (const batch of chunks(users, 10)) {
    const params = (u) => ({ headers: authHeaders(u.token), tags: { endpoint: 'setup', name: 'setup_lists' } });
    const orders = http.batch(batch.map((u) => ({ method: 'GET', url: `${BASE_URL}/orders?limit=100`, params: params(u) })));
    const notes = http.batch(batch.map((u) => ({ method: 'GET', url: `${BASE_URL}/notifications`, params: params(u) })));
    batch.forEach((u, i) => {
      const o = orders[i].status === 200 ? orders[i].json() : { data: [], pagination: { total: 0 } };
      const n = notes[i].status === 200 ? notes[i].json() : { pagination: { total: 0 } };
      u.orderIds = o.data.map((x) => x.id);
      u.orderPages = Math.max(1, Math.ceil(o.pagination.total / PAGE_SIZE));
      u.notificationPages = Math.max(1, Math.ceil(n.pagination.total / PAGE_SIZE));
    });
  }

  const summary = (list) => list.map((u) => u.orderPages * PAGE_SIZE).sort((a, b) => a - b);
  const h = summary(users.filter((u) => u.heavy));
  const l = summary(users.filter((u) => !u.heavy));
  console.log(
    `setup: ${users.length} users logged in (${h.length} heavy, ~${h[Math.floor(h.length / 2)] || 0} orders each; ` +
      `${l.length} light, ~${l[Math.floor(l.length / 2)] || 0} orders each)`
  );

  return {
    heavy: users.filter((u) => u.heavy),
    light: users.filter((u) => !u.heavy),
    withOrders: users.filter((u) => u.orderIds.length > 0),
  };
}

// Half of the customer traffic comes from heavy users, half from light ones.
function customer(data) {
  const pool = data.heavy.length > 0 && (data.light.length === 0 || Math.random() < 0.5) ? data.heavy : data.light;
  return pick(pool);
}

// ---- scenarios -------------------------------------------------------------
export function browse_list() {
  // Mostly the first pages, sometimes deep in the catalog (large OFFSET).
  const page = Math.random() < 0.7 ? randInt(1, 5) : randInt(1, CATALOG_PAGES);
  const res = get(`/products?page=${page}`, 'browse_list', null, true);
  check(res, { 'browse_list 200/304': (r) => r.status === 200 || r.status === 304 });
}

export function product_detail() {
  const res = get(`/products/${perfUuid(PRODUCT, randInt(1, PRODUCTS))}`, 'product_detail', null, true);
  check(res, { 'product_detail 200/304': (r) => r.status === 200 || r.status === 304 });
}

export function my_orders(data) {
  const u = customer(data);
  const page = Math.random() < 0.6 ? 1 : randInt(1, u.orderPages);
  const res = get(`/orders?page=${page}`, 'my_orders', u.token);
  check(res, { 'my_orders 200': (r) => r.status === 200 });
}

export function order_detail(data) {
  if (data.withOrders.length === 0) return;
  const u = pick(data.withOrders);
  const res = get(`/orders/${pick(u.orderIds)}`, 'order_detail', u.token);
  check(res, { 'order_detail 200': (r) => r.status === 200 });
}

export function my_notifications(data) {
  const u = customer(data);
  const page = Math.random() < 0.7 ? 1 : randInt(1, u.notificationPages);
  const res = get(`/notifications?page=${page}`, 'my_notifications', u.token);
  check(res, { 'my_notifications 200': (r) => r.status === 200 });
}

export function create_order(data) {
  const u = customer(data);
  const items = distinctInts(randInt(1, 3), 1, PRODUCTS).map((n) => ({
    product_id: perfUuid(PRODUCT, n),
    quantity: randInt(1, 2),
  }));
  const res = record(
    http.post(`${BASE_URL}/orders`, JSON.stringify({ items }), {
      headers: { ...authHeaders(u.token), 'Content-Type': 'application/json' },
      tags: { endpoint: 'create_order', name: 'create_order' },
    }),
    'create_order'
  );
  check(res, { 'create_order 201': (r) => r.status === 201 });
}

// ---- summary ---------------------------------------------------------------
function endpointRows(data) {
  const m = (name) => (data.metrics[name] ? data.metrics[name].values : {});
  return ENDPOINTS.map((e) => {
    const d = m(`http_req_duration{endpoint:${e}}`);
    // Without PERF_QUERY_LOG the query trends stay empty (k6 reports 0): show "-" instead.
    const q = m(`query_count{endpoint:${e}}`);
    const qt = m(`query_time_ms{endpoint:${e}}`);
    const hasQueries = q.max > 0;
    const status = {};
    for (const [key, counter] of Object.entries(STATUS)) status[key] = m(`${counter.name}{endpoint:${e}}`).count || 0;
    return {
      endpoint: e,
      requests: m(`http_reqs{endpoint:${e}}`).count || 0,
      p50: d.med,
      p90: d['p(90)'],
      p95: d['p(95)'],
      p99: d['p(99)'],
      avg: d.avg,
      max: d.max,
      error_rate: m(`real_errors{endpoint:${e}}`).rate || 0,
      status,
      avg_queries: hasQueries ? q.avg : null,
      cache_hit_ratio: (() => {
        const h = m(`cache_hits{endpoint:${e}}`).count || 0;
        const x = m(`cache_misses{endpoint:${e}}`).count || 0;
        return h + x > 0 ? h / (h + x) : null;
      })(),
      avg_query_time_ms: hasQueries ? qt.avg : null,
      not_modified_ratio: (() => {
        const n = m(`http_reqs{endpoint:${e}}`).count || 0;
        return n > 0 ? (status.not_modified_304 || 0) / n : 0;
      })(),
      latency_200: (({ med, 'p(95)': p95, 'p(99)': p99 }) => ({ p50: med, p95, p99 }))(m(`duration_200{endpoint:${e}}`)),
      latency_304: (({ med, 'p(95)': p95, 'p(99)': p99 }) => ({ p50: med, p95, p99 }))(m(`duration_304{endpoint:${e}}`)),
      bytes_received: m(`data_received{endpoint:${e}}`).count || 0,
      routing: {
        reads_replica: m(`reads_replica{endpoint:${e}}`).count || 0,
        reads_primary: m(`reads_primary{endpoint:${e}}`).count || 0,
        route_us_avg: m(`route_us{endpoint:${e}}`).avg,
        route_us_p95: m(`route_us{endpoint:${e}}`)['p(95)'],
        replica_connect_us_avg: m(`replica_connect_us{endpoint:${e}}`).avg,
        refill_replica: m(`refill_replica{endpoint:${e}}`).count || 0,
        refill_primary: m(`refill_primary{endpoint:${e}}`).count || 0,
      },
    };
  });
}

export function handleSummary(data) {
  const rows = endpointRows(data);
  const dropped = data.metrics.dropped_iterations ? data.metrics.dropped_iterations.values.count : 0;
  const received = data.metrics.data_received ? data.metrics.data_received.values.count : 0;
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const file = `${RESULTS_DIR}/${LABEL}-${stamp}.json`;

  const result = {
    label: LABEL,
    profile: PROFILE,
    rate_per_s: RATE,
    duration: DURATION,
    base_url: BASE_URL,
    finished_at: new Date().toISOString(),
    dropped_iterations: dropped,
    data_received_bytes: received,
    etag_revalidation: SEND_ETAG,
    endpoints: rows,
    k6: data,
  };

  const text = [
    textSummary(data, { indent: ' ', enableColors: true }),
    '',
    `Per endpoint (${LABEL}, ${RATE} req/s for ${DURATION}, dropped iterations: ${dropped}):`,
    renderTable(rows),
    '',
    `200 vs 304 (ms, p50 / p95 / p99), If-None-Match ${SEND_ETAG ? 'on' : 'off'}:`,
    ...rows.filter((r) => r.endpoint === 'browse_list' || r.endpoint === 'product_detail').map((r) => {
      const f = (x) => (x === undefined || x === null ? '-' : x.toFixed(1));
      const t = (l) => `${f(l.p50)} / ${f(l.p95)} / ${f(l.p99)}`;
      return `  ${r.endpoint.padEnd(16)} 200: ${t(r.latency_200)}   304: ${r.not_modified_ratio ? t(r.latency_304) : '-'}   (304 = ${(r.not_modified_ratio * 100).toFixed(1)} %, received ${(r.bytes_received / 1048576).toFixed(2)} MiB)`;
    }),
    `data_received: ${(received / 1048576).toFixed(1)} MiB in total (setup included)`,
    ...(rows.some((r) => r.routing.reads_replica + r.routing.reads_primary > 0)
      ? ['', 'Read routing (requests whose reads went to replica / primary; routing cost; cache refills replica / primary):',
        ...rows.map((r) => { const g = r.routing; const f = (x) => (x === undefined || x === null ? '-' : (x / 1000).toFixed(2));
          return `  ${r.endpoint.padEnd(16)} replica ${String(g.reads_replica).padStart(5)}  primary ${String(g.reads_primary).padStart(5)}  route avg ${f(g.route_us_avg)} ms p95 ${f(g.route_us_p95)} ms  replica connect avg ${f(g.replica_connect_us_avg)} ms  refills ${g.refill_replica}/${g.refill_primary}`; })]
      : []),
    '',
    `Result written to ${file}`,
    '',
  ].join('\n');

  return { stdout: text, [file]: JSON.stringify(result, null, 2) };
}
