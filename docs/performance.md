# Performance baseline

Step 1 of the Performance & Caching work: make the system measurable **before**
any optimization. Nothing in this step changes how the API serves requests:
no new indexes, no caching, no query rewrites. The numbers below describe the
current code, and step 2 will be measured against them with the same data and
the same request rate.

Contents: [dataset](#1-dataset) · [running the load test](#2-load-test) ·
[environment](#3-environment) · [baseline](#4-baseline-results) · [capacity](#41-capacity-stepped-load) ·
[observed problems](#5-observed-problems-to-fix-in-step-2)

## 1. Dataset

### Seeding

Two scripts, always in this order (the inventory script stocks the products the
first one creates). Both are dev-only and never run from `db:seed` or the tests.

```sh
docker compose up -d                                   # stack running, migrations applied

# 1. Main database: users, products, orders, items, status history, notifications
docker compose --profile perf run --rm perf-tools perf:seed --fresh

# 2. Inventory database: stock for every generated product
docker compose exec inventory npm run seed:perf -- --products=50000 --fresh
```

| Step | Time on the test machine |
|---|---|
| `perf:seed` (first run) | 2 min 46 s |
| `perf:seed --fresh` (replacing an existing dataset) | 2 min 33 s – 3 min 06 s |
| `seed:perf --fresh` | 2 s |

`perf:seed` options (defaults shown): `--users=5000 --products=50000
--orders=200000 --seed=42 --anchor=2026-11-01T00:00:00Z --password=PerfPass123!
--chunk=20000 --fresh`. If you change `--products`, pass the same value to
`seed:perf` and to k6 (`PRODUCTS`).

How it works:

- **Bulk SQL only.** Rows are generated inside PostgreSQL with
  `generate_series` and `INSERT … SELECT`, 20 000 orders per transaction, with
  progress and elapsed time printed. The password is hashed once and reused.
- **Deterministic.** Every "random" value is derived from `md5(seed, kind,
  row number)` and dates are relative to the fixed `--anchor`, so the same
  options always produce the same rows (only the bcrypt salt differs).
- **Identifiable ids.** Generated ids are valid UUID v4 values with the row
  number in the first group and a marker in the fourth:
  `8f00` products, `8f01` users, `8f02` orders, `8f03` notifications,
  `8f04` order items. Example: product 12 is `0000000c-0000-4000-8f00-000000000000`.
  The k6 script and `seed:perf` use the same formula.
- **Re-runnable.** Without `--fresh` the command refuses to run when perf data
  exists. `--fresh` deletes only perf rows: every order of a perf user
  (including orders the load test placed; items, history and notifications go
  with them), then perf users and products. The 4 sample products, the admin
  and any other data are kept. `seed:perf --fresh` deletes the reservations of
  perf products and sets their stock back to `--available` (default 1 000 000).
- **Guarded.** `perf:seed` runs only when `APP_ENV` is `local` or `perf`. The
  `perf-tools` service is the backend image with `APP_ENV=perf`; the `backend`
  container itself stays `production` (the image has no config cache, so the
  container's environment is what Laravel sees).

### What gets generated (defaults)

| Table | Rows |
|---|---|
| users | 5 001 (5 000 customers `perf-user-{n}@example.test` + `perf-admin@example.test`) |
| products | 50 000 (10 categories, prices 1–1000 skewed to cheap, ~10 % without description, no images) |
| orders | 200 000, created over the 12 months before the anchor |
| order_items | 601 076 (1–5 distinct products per order, avg 3.0; popular products appear more often) |
| order_status_history | 650 001 |
| notifications | 650 001 (one per status step; ~14 % unread, recent ones unread more often) |
| inventory `stock` | 50 000 rows, 1 000 000 available each |

- **Order owners are skewed:** `perf-user-1 … perf-user-300` are heavy users
  with 250–340 orders each; the other 4 700 have between 2 and 95 (the first
  20 % of them more than the rest; median of all customers: 13).
- **Statuses:** delivered 60 %, shipped 10 %, confirmed 10 %, pending 10 %,
  cancelled 10 % (half while pending, half after confirmation). Each order's
  history and notifications follow its path (pending → confirmed → shipped →
  delivered); the last history row always matches `orders.status`.
- **Consistency checked after seeding:** every order total equals the sum of
  its items, no order is without items, no status/history mismatch.
- Historic orders have no inventory reservations (not needed: stock is set
  high enough for the load test's new orders).

## 2. Load test

`perf/k6/store.js` runs in Docker (`grafana/k6:0.54.0`) on the compose network,
so nothing needs to be installed on Windows. It needs internet access once per
run to import `textSummary` from jslib.k6.io.

```sh
# short check that everything works (20 s + setup)
docker compose --profile perf run --rm -e PROFILE=smoke k6

# a measured run; the label names the result file
docker compose --profile perf run --rm -e LABEL=baseline-1 k6
```

| Env | Default | Meaning |
|---|---|---|
| `PROFILE` | `baseline` | `smoke` = 5 req/s for 20 s, `baseline` = 40 req/s for 2 min |
| `RATE`, `DURATION` | from the profile | override the total request rate (req/s) and duration |
| `LABEL` | the profile | name of the result file |
| `BASE_URL` | `http://backend:3000/api/v1` | set `PERF_BASE_URL` in the host env to change it |
| `PRODUCTS`, `USERS`, `HEAVY_USERS` | 50000, 5000, 300 | must match `perf:seed` |
| `HEAVY_POOL`, `LIGHT_POOL` | 40, 120 | perf users logged in during setup |

**Scenarios.** Each is a `constant-arrival-rate` scenario, so every run sends
the same requests per second whatever the latency. The share of the total
rate (at 40 req/s):

| Scenario | Request | Share | req/s |
|---|---|---|---|
| `browse_list` | `GET /products?page=N`: 70 % pages 1–5, 30 % any page up to the last (deep OFFSET) | 30 % | 12 |
| `product_detail` | `GET /products/{random perf product}` | 25 % | 10 |
| `my_orders` | `GET /orders?page=N` as a customer, half heavy / half light users, 60 % page 1 | 15 % | 6 |
| `order_detail` | `GET /orders/{id}` for one of the customer's own orders | 12 % | 5 |
| `my_notifications` | `GET /notifications?page=N`, 70 % page 1 | 13 % | 5 |
| `create_order` | `POST /orders` with 1–3 random products, quantity 1–2 | 5 % | 2 |

**Auth.** `setup()` logs in 160 perf users once (40 heavy, 120 light), in
batches of 10, and reuses the tokens. The login limiter counts only *failed*
attempts (5 per 15 min per IP + email), so these logins never hit it and no
limit was raised. Tokens live 15 minutes (`JWT_EXPIRES_IN`), more than a run
needs. There is no other rate limit on `/api`.

**What is recorded**, per endpoint (tag `endpoint`):

- `http_req_duration`: avg, min, med (p50), p90, p95, p99, max.
- Responses by category: `status_2xx`, `status_409_stock` (409
  `INSUFFICIENT_STOCK`), `status_429`, `status_4xx_other`, `status_5xx`,
  `status_network`.
- `real_errors`: rate of 5xx, network failures and unexpected 4xx. 409 stock and
  429 are reported on their own and are **not** errors.
- `query_count`, `query_time_ms`: from the `X-Query-Count` / `X-Query-Time-Ms`
  headers, only when the backend runs with `PERF_QUERY_LOG=true`.
- `dropped_iterations`: requests k6 could not start on time (should be 0, or
  the rate is above what the machine can drive).

The run ends with the normal k6 summary plus a per-endpoint table, and writes
`perf/results/<label>-<timestamp>.json` (the table, the run settings and the
raw k6 summary). `perf/results/` is gitignored.

### Query counter (`PERF_QUERY_LOG`)

`PERF_QUERY_LOG=true` adds the `QueryCounter` middleware, which reports the
number of SQL queries and their total time in two response headers. It is off
by default; then the middleware is not registered at all, so it costs nothing
and no headers are sent (covered by `tests/Feature/QueryCounterTest.php`).

Timing runs are always done with the counter **off**. Query counts come from a
separate short run:

```sh
PERF_QUERY_LOG=true docker compose up -d backend     # PowerShell: $env:PERF_QUERY_LOG="true"; docker compose up -d backend
docker compose --profile perf run --rm -e LABEL=querycount -e DURATION=30s k6
docker compose up -d backend                          # back to off
```

### Procedure used for the baseline

1. `perf:seed --fresh` and `seed:perf --fresh` (clean, identical state).
2. One warm-up run (2 min, not counted).
3. Three measured runs of 2 min at 40 req/s, query counter off. Reported value
   = the median of the three runs for each statistic.
4. One 30 s run with `PERF_QUERY_LOG=true` for the query counts.

Re-seed before each measurement set (before and after an optimization). The
runs themselves add ~240 orders each (`create_order`), which is negligible next
to 200 000.

### Running the test suites

On a Windows host where a local PostgreSQL also listens on port 5432, the host
cannot reach the Docker database, so run the suites in containers on the
compose network (after creating the `*_test` databases, see the README):

```sh
# backend (uses the local vendor/ with dev dependencies)
docker run --rm --network docker-store_default -v "%cd%/backend:/app" -w /app ^
  -e DB_HOST=db -e DB_USERNAME=<POSTGRES_USER> -e DB_PASSWORD=<POSTGRES_PASSWORD> ^
  --entrypoint php docker-store-backend artisan test

# inventory service
docker run --rm --network docker-store_default -v "%cd%/inventory-service:/app" -v /app/node_modules ^
  -v "%cd%/proto:/proto:ro" -w /app ^
  -e DATABASE_URL=postgres://<INVENTORY_DB_USER>:<INVENTORY_DB_PASSWORD>@inventory_db:5432/<INVENTORY_DB_NAME> ^
  node:20-alpine sh -c "npm ci && npm test"
```

## 3. Environment

| | |
|---|---|
| Host | Windows 11 Pro, Intel Core i5-1235U (10 cores / 12 threads), 15.7 GB RAM |
| Docker | Docker Desktop, engine 29.9.2, WSL2 backend; **12 CPUs and 7.6 GB RAM** available to Docker |
| Backend | FrankenPHP 1.13.5 (Caddy 2.11.9), PHP 8.4.27 ZTS, **classic mode (not worker mode)**, `num_threads 32`, OPcache on with `validate_timestamps=0`, `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=info` to stderr, `CACHE_STORE=database` |
| Main DB | PostgreSQL 16.15 (postgres:16-alpine), default settings: `shared_buffers` 160 MB, `work_mem` 4 MB, `effective_cache_size` 5 GB, `max_connections` 100, `random_page_cost` 4, scram-sha-256 auth |
| Inventory | Node 20 (`@grpc/grpc-js`), PostgreSQL 16 with defaults, `pg` pool of 10 |
| Load generator | k6 0.54.0 in a container **on the same machine** (it shares the CPUs with the system under test) |
| Network | everything on the compose bridge network; k6 calls `http://backend:3000` directly |
| Machine load during the runs | _placeholder: other applications running, power mode, plugged in or on battery_ |

## 4. Baseline results

Dataset as in section 1, 40 req/s for 2 min per run, median of 3 runs
(2026-11-10). Latencies in milliseconds. Requests are per run. Query counts are
from the separate `querycount` run.

| Endpoint | Requests | p50 | p95 | p99 | Error rate | Avg queries | Avg DB time / request |
|---|---|---|---|---|---|---|---|
| `browse_list` — `GET /products` | 1 441 | 49.3 | 99.6 | 125.4 | 0 % | 2 | 42.3 ms |
| `product_detail` — `GET /products/{id}` | 1 201 | 37.2 | 71.6 | 89.9 | 0 % | 1 | 24.5 ms |
| `my_orders` — `GET /orders` | 721 | 33.1 | 75.9 | 88.1 | 0 % | 2 | 31.5 ms |
| `order_detail` — `GET /orders/{id}` | 601 | 44.4 | 85.5 | 98.1 | 0 % | 3 | 42.3 ms |
| `my_notifications` — `GET /notifications` | 601 | 39.4 | 78.8 | 90.6 | 0 % | 2 | 36.4 ms |
| `create_order` — `POST /orders` | 241 | 94.8 | 133.0 | 145.4 | 0 % | 8 | 64.6 ms |

- All 3 runs: 0 errors, 0 × 409 stock, 0 × 429, 0 dropped iterations.
- The runs agree closely: p50 within ±1.5 ms, p95 within ±4 ms (e.g.
  `browse_list` p95 99.6 / 103.8 / 99.5).
- "DB time" is what Laravel measures around each query. It includes opening the
  database connection on the request's first query (see problem 1), which is
  why a single primary-key lookup shows 24 ms.
- 40 req/s does not saturate the machine; see [4.1](#41-capacity-stepped-load)
  for where it breaks.

Raw results: `perf/results/warmup-*.json`, `baseline-{1,2,3}-*.json`,
`querycount-*.json` (local only, not committed).

### 4.1 Capacity (stepped load)

Same scenarios and mix, one run per rate, 90 s each, after a fresh
`perf:seed` / `seed:perf`, query counter off (2026-11-10). CPU is a
`docker stats` sample about 45 s into each run (100 % = one core; Docker has 12).

```powershell
foreach ($r in 40,80,160,240,320) {
  docker compose --profile perf run --rm -e RATE=$r -e DURATION=90s -e LABEL=step-$r k6
}
```

| Target req/s | Served req/s | Dropped | p50 (range over endpoints) | p95 | p99 | Errors | CPU backend / db / k6 |
|---|---|---|---|---|---|---|---|
| 40 | 40 | 0 | 28–70 ms | 62–105 ms | 76–112 ms | 0 | 85 % / 73 % / 6 % |
| 80 | 80 | 0 | 22–57 ms | 51–98 ms | 68–102 ms | 0 | 146 % / 136 % / 11 % |
| 160 | 160 | 0 | 44–90 ms | 176–315 ms | 345–542 ms | 0 | 473 % / 529 % / 23 % |
| 240 | **165** | 6 714 | 13.7–16.8 **s** | 32 s | 32–33 s | 0 | 319 % / 466 % / 366 % |
| 320 | **113** | 17 902 | 19.5–22.2 **s** | 61–63 s | 65–66 s | 5–7 % | 453 % / 281 % / 135 % |

Per-endpoint numbers are in `perf/results/step-*.json`. At every rate,
`create_order` is the slowest endpoint and `browse_list` the slowest read.

What it shows:

- **Up to 80 req/s the system is comfortable.** Latency is even slightly lower
  than at 40 req/s (warmer caches, busier CPUs).
- **160 req/s is the edge.** Everything is still served with no errors, but p95
  is 2–3× higher and p99 reaches 0.35–0.54 s. The backend and PostgreSQL each
  use about 5 cores.
- **The ceiling is about 165 req/s.** At 240 req/s the system still completes
  only ~165 req/s; the rest queue up, and latency jumps from tenths of a second
  to 14–32 s (no errors, just waiting).
- **At 320 req/s it breaks down.** Throughput *drops* to ~113 req/s.
  101 requests hit PHP's 30 s `max_execution_time` (500), 429 hit k6's 60 s
  request timeout, and ~2 700 were still waiting when the run ended.
- **Above 160 req/s these numbers also measure k6.** k6 runs on the same
  machine: with requests piling up it opened ~3 800 VUs and used up to 3.7
  cores and 6 GB of RAM, competing with the system under test. The ceiling
  (~165 req/s) is reliable; the exact latencies at 240 and 320 are not.

**Rates to use for before/after comparisons:** **40 req/s** (normal load, the
baseline table above) and **120 req/s** (about 70 % of the ceiling, where
per-request costs start to queue). Report also the ceiling itself (served
req/s at 240) after each optimization.

## 5. Observed problems to fix in step 2

Observed only: nothing below was changed in this step.

**No N+1 found.** Every endpoint runs a fixed number of queries whatever the
data (1 to 3 for reads, 8 for `create_order`). The SQL itself is fast: with the
generated data, almost every query plan runs in under 2 ms (`EXPLAIN
ANALYZE`). The time goes into fixed per-request costs:

1. **A new PostgreSQL connection on every request, ~11 ms.** PHP runs in
   classic mode and Laravel does not keep connections, so each request opens a
   fresh connection with scram-sha-256 authentication. Measured from the
   backend container: connect median 11.1 ms, a primary-key lookup on that
   connection 2.7 ms. Candidates: FrankenPHP worker mode (keeps the app and its
   connection alive), PgBouncer, or persistent PDO connections.
2. **Laravel boots on every request, ~13 ms.** `GET /health` (no database, no
   gRPC) has a median of 13.5 ms measured from the host. With problem 1, that
   is about 25 ms of the 37 ms p50 of `product_detail` before any real work.
   Worker mode addresses both.
3. **Deep OFFSET pagination on the catalog.** `GET /products` sorts by
   `created_at DESC, id DESC` and pages with `LIMIT/OFFSET`. Page 2 451
   (`OFFSET 49000`) reads and sorts 49 020 rows: **58.9 ms** of SQL against
   0.15 ms for page 1. The index `idx_products_created` covers only
   `created_at`, so PostgreSQL adds an Incremental Sort for the `id` tie-breaker.
   This is the main reason `browse_list` has the highest read p95/p99.
   Candidates: index on `(created_at DESC, id DESC)`, keyset (cursor)
   pagination, caching of the first pages.
4. **`COUNT(*)` on every list request.** `GET /products` runs `SELECT COUNT(*)
   FROM products` each time (10.9 ms, a full index-only scan of 50 000 entries)
   just for `pagination.total`. The same pattern exists for orders and
   notifications (cheap per user today: 0.2 ms and 1.2 ms for a heavy user),
   and for the admin's unfiltered `GET /orders`: `COUNT(*) FROM orders` is a
   parallel seq scan, **35.9 ms** (not part of the load mix).
5. **`create_order` is the slowest endpoint (p50 95 ms).** It runs 8 queries:
   the product lookup, the order, items, history and notification inserts in
   one transaction, then it reads the whole order back (order, items, history:
   3 more queries) to build the response. It also makes a synchronous
   `ReserveStock` gRPC call.
6. **Every catalog request calls the inventory service.** `GET /products` and
   `GET /products/{id}` make one `GetStock` gRPC call each, over a new HTTP/2
   connection per call (curl handle created and closed per call; ~2.4 ms
   measured), and log one `Log::info` line per call. Stock is never cached,
   even briefly.
7. **No HTTP or application caching.** The catalog and product pages are
   public and identical for every visitor, but every request rebuilds them
   from the database and the inventory service (no ETag, no Cache-Control, no
   cache store). This is expected at this stage and is the main topic of step 2.

Smaller notes:

- `findItems` sorts the order's items by `product_name` (Sort node over 1–5
  rows, negligible).
- The notifications counts query (`total` + `unread_count`) reads all of the
  user's notification index entries on every request (963 rows for a heavy
  user, 1.2 ms). Fine now; grows linearly with history.
- `CACHE_STORE=database`: the login limiter writes to the `cache` table. Not
  in the load mix, but each login costs extra writes.
