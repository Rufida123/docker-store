# Performance baseline

Step 1 of the Performance & Caching work: make the system measurable **before**
any optimization. Nothing in this step changes how the API serves requests:
no new indexes, no caching, no query rewrites. The numbers below describe the
current code, and step 2 will be measured against them with the same data and
the same request rate.

Contents: [dataset](#1-dataset) · [running the load test](#2-load-test) ·
[environment](#3-environment) · [baseline](#4-baseline-results) · [capacity](#41-capacity-stepped-load) · [120 req/s](#42-baseline-at-120-reqs-high-load) ·
[observed problems](#5-observed-problems-to-fix-in-step-2) · [step 2](#6-step-2-optimizations)

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
per-request costs start to queue, table 4.2). Report also the ceiling itself
(served req/s at 240) after each optimization.

### 4.2 Baseline at 120 req/s (high load)

Same procedure as the 40 req/s baseline: fresh `perf:seed` / `seed:perf`, one
warm-up run, then three 2-minute runs at 120 req/s, query counter off
(2026-11-10). Median of the three runs; the range (lowest–highest run) is in
brackets for the tail percentiles.

```powershell
docker compose --profile perf run --rm -e RATE=120 -e LABEL=baseline120-1 k6
```

| Endpoint | Requests | p50 | p95 | p99 | Error rate |
|---|---|---|---|---|---|
| `browse_list` — `GET /products` | 4 321 | 50.9 | 133.2 (100.5–138.5) | 294.3 (128.4–430.8) | 0 % |
| `product_detail` — `GET /products/{id}` | 3 601 | 35.6 | 79.4 (49.6–82.4) | 200.5 (70.1–312.2) | 0 % |
| `my_orders` — `GET /orders` | 2 161 | 34.4 | 73.5 (46.8–76.8) | 192.8 (63.4–297.4) | 0 % |
| `order_detail` — `GET /orders/{id}` | 1 681 | 39.9 | 80.6 (53.7–83.7) | 194.8 (70.5–324.3) | 0 % |
| `my_notifications` — `GET /notifications` | 1 921 | 35.1 | 68.9 (48.4–76.9) | 190.8 (61.1–321.5) | 0 % |
| `create_order` — `POST /orders` | 721 | 72.6 | 136.4 (95.1–143.7) | 369.9 (127.1–505.0) | 0 % |

- All runs: 0 errors, 0 × 409 stock, 0 × 429, 0 dropped iterations.
- **p50 is stable** (within ±3 ms between runs) and even a little lower than at
  40 req/s: with more traffic the CPUs stay at a higher clock.
- **p95 and above are noisy at this load.** One run of the three (run 2) had
  p99 2–4× lower than the other two. Close to the ceiling, short stalls on the
  machine (laptop CPU boost/throttling, WSL2 scheduling, k6 on the same
  machine) turn into queues. Compare p50 and p95 first; treat a p99 change as
  real only if it is clearly outside the ranges above, or measure 5 runs.
- **The warm-up matters more here:** the warm-up run right after seeding had a
  p95 2× higher (e.g. `browse_list` 288.5 ms, `create_order` 370.4 ms) than the
  measured runs. Always discard it.

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

## 6. Step 2: optimizations

One optimization per change, each followed by a quick check. The full
before/after measurement is done at the end, back to back (below).

**How changes are measured**

- **Quick check after each change:** 30 s warm-up, then one 2-minute run at
  **100 req/s** on the current data (no re-seed). 100 req/s, not 120: 120 is
  too close to this machine's ceiling for a single run to be reliable (see
  "Observed behavior" below).
- **Final comparison, back to back:** in one session, on a quiet machine,
  fresh seed, warm-up, 3 runs at 40 and at 120 req/s, median: first with the
  baseline commit (`833dc94`), then with the final commit. Both sets come from
  the same machine state, which is fairer than comparing against the numbers
  in section 4, taken on another day under other conditions.

### 6.1 Pagination indexes include `id`

Every list sorts by `(created_at DESC, id DESC)`, but the indexes covered only
`created_at`, so PostgreSQL added an Incremental Sort for the `id` tie-breaker
and sorted every row skipped by `OFFSET`. Migration
`0001_01_03_000001_add_id_to_pagination_indexes` replaces the four list
indexes:

| Old index | New index |
|---|---|
| `products (created_at DESC)` | `products (created_at DESC, id DESC)` |
| `products (category, created_at DESC)` | `products (category, created_at DESC, id DESC)` |
| `orders (user_id, created_at DESC)` | `orders (user_id, created_at DESC, id DESC)` |
| `orders (status, created_at DESC)` | `orders (status, created_at DESC, id DESC)` |

It uses plain `CREATE INDEX` (a few hundred ms on the perf dataset). On a large
live table, build the indexes with `CREATE INDEX CONCURRENTLY` outside a
transaction instead.

`EXPLAIN (ANALYZE, BUFFERS)` on the perf dataset:

| Query | Before | After |
|---|---|---|
| Q1 catalog page 1 | 0.19 ms (Index Scan + Incremental Sort) | 0.10 ms (Index Scan only) |
| Q2 catalog deep page, `OFFSET 49000` | 43.96 ms (Incremental Sort of 49 020 rows) | 28.29 ms (Index Scan only) |
| Q3 category `home`, page 100 | 6.50 ms (Bitmap scan + top-N heapsort) | 5.69 ms (same plan) |
| Q4 heavy user's orders, page 11 | 1.05 ms (Incremental Sort) | 0.60 ms (Index Scan only) |
| Q5 admin orders `status=pending`, page 50 | 3.91 ms (Incremental Sort) | 2.91 ms (Index Scan only) |

Q2 before:

```
Limit (actual time=43.892..43.896 rows=20 loops=1)
  ->  Incremental Sort (actual time=0.154..42.262 rows=49020 loops=1)
        Sort Key: created_at DESC, id DESC
        Presorted Key: created_at
        Full-sort Groups: 1532  Sort Method: quicksort
        ->  Index Scan using idx_products_created on products (actual time=0.080..28.869 rows=49021 loops=1)
Execution Time: 43.958 ms
```

Q2 after:

```
Limit (actual time=28.222..28.232 rows=20 loops=1)
  ->  Index Scan using idx_products_created_id on products (actual time=0.015..26.440 rows=49020 loops=1)
Execution Time: 28.285 ms
```

Quick check (120 req/s, the rate used before the switch to 100):
`browse_list` p95 133.2 → 87.5 ms (below the lowest of the three baseline
runs, 100.5 ms); the other endpoints stayed within the baseline's range.

### 6.2 Catalog pages read with a deferred join

The index removed the sort, but the deep page still read all 49 020 skipped
rows from the table. `ProductsRepository::list` now picks the page's ids with
an index-only scan and reads only those 20 rows (the API and the order of the
rows are unchanged; checked on pages 1, 3, 100 and 2 451, with and without a
category):

```sql
SELECT p.* FROM (SELECT id, created_at FROM products [WHERE category = ?]
                  ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?) page
JOIN products p ON p.id = page.id
ORDER BY page.created_at DESC, page.id DESC
```

| Query | After 6.1 | After 6.2 |
|---|---|---|
| Q1 catalog page 1 | 0.10 ms | 0.29 ms (20 primary-key lookups; negligible) |
| Q2 catalog deep page | 28.29 ms | **9.96 ms** |
| Q3 category page 100 | 5.69 ms | **0.73 ms** |

Q2 after:

```
Nested Loop (actual time=9.224..9.837 rows=20 loops=1)
  ->  Limit
        ->  Index Only Scan using idx_products_created_id on products (actual time=0.020..6.702 rows=49020 loops=1)
              Heap Fetches: 0
  ->  Index Scan using products_pkey on products p (actual time=0.030..0.030 rows=1 loops=20)
        Index Cond: (id = products.id)
Execution Time: 9.956 ms
```

`Heap Fetches: 0` needs the table's visibility map to be current (autovacuum
keeps it so). On a table with many recent updates, part of the index-only scan
reads the table again, and the gain is smaller.

The first quick checks at 120 req/s were not usable (see the collapse below).
Repeated at 100 req/s on a quiet machine, in the table of 6.3.

### 6.3 Order items in `GET /orders`, one query per page

**No N+1 existed in the API.** With `PERF_QUERY_LOG=true` every endpoint ran a
fixed number of queries (section 4), and no service method queries inside a
loop. The only loop is `flushOutbox`, which releases one reservation per
iteration by design (a gRPC call each, scheduler only).

**But the frontend needed the items of every order in the list.** The orders
page shows an item count per order (`o.items.length`), and `GET /orders`
returned no items, so the column always showed "—". The obvious fix, calling
`findItems()` for each order, would have been a classic N+1: 20 extra
queries per page. Instead:

- `OrdersRepository::findItemsForOrders(array $orderIds)` loads the items of
  the whole page in one query (`WHERE order_id = ANY(?::uuid[])`, served by
  the `(order_id, product_id)` unique index) and groups them by order in PHP.
- `GET /orders` now returns `items` for each order (not the status history;
  `GET /orders/{id}` has it). `docs/openapi.yaml`: new `OrderListEntry` schema.
- `tests/Feature/OrderListItemsTest.php` checks the result and that the query
  count is the same, 3 (page, count, items), for 2 or 20 orders; an empty
  page runs 2 (no items query).

`EXPLAIN (ANALYZE, BUFFERS)` for the first page of a heavy user (20 orders,
45 items; the order ids come from an InitPlan here to keep the test
self-contained):

```
Incremental Sort (actual time=1.008..1.072 rows=45 loops=1)
  Sort Key: order_items.order_id, order_items.product_name
  Presorted Key: order_items.order_id
  ->  Index Scan using order_items_order_id_product_id_key on order_items (actual time=0.757..0.985 rows=45 loops=1)
        Index Cond: (order_id = ANY ($0))
        Buffers: shared hit=108
Execution Time: 1.186 ms   (of which the items lookup: ~0.23 ms)
```

Quick checks at 100 req/s, quiet machine (heavy programs closed, AC power),
2026-11-10. Change 3 measured right after change 2, on the same data:

| Endpoint | After 6.2: p50 / p95 / p99 | After 6.3: p50 / p95 / p99 |
|---|---|---|
| `browse_list` | 54.8 / 192.1 / 478.3 | 52.5 / 76.3 / 102.6 |
| `product_detail` | 39.7 / 146.6 / 448.3 | 38.1 / 57.6 / 76.7 |
| `my_orders` | 38.4 / 118.0 / 385.3 | **44.3** / 62.6 / 83.2 |
| `order_detail` | 44.1 / 129.4 / 465.6 | 42.4 / 61.1 / 83.8 |
| `my_notifications` | 39.6 / 122.5 / 390.2 | 38.0 / 56.2 / 71.4 |
| `create_order` | 84.4 / 276.9 / 763.5 | 81.2 / 115.1 / 143.3 |

- **`my_orders` p50 +6 ms is more work, not a regression.** Before 6.3 the
  endpoint returned 20 order headers; now it returns the same 20 orders
  **plus their items** (about 45–60 item rows per page), which the orders page
  needs and never had. The extra time is one more query (~0.23 ms of SQL) and
  a larger JSON response to build and send. The same data fetched the naive
  way would cost 20 extra queries per page. The two runs therefore don't
  measure the same response: compare `my_orders` before and after 6.3 as
  "a bigger answer for +6 ms", not as the same request getting slower.
- **The p95/p99 difference between the two runs is not caused by 6.3.** The
  first run's tails are high for every endpoint at once, including ones 6.3
  does not touch (`product_detail`, `my_notifications`). That pattern is a
  machine-wide stall (as in the collapse below), not one query. Only the p50
  column can be compared between these two single runs; the back-to-back
  final measurement will settle the tails.

### 6.4 Redis cache for the catalog

**Setup.**

- `redis` service (`redis:7.4-alpine`): no persistence (`--save ""`,
  `--appendonly no`, the data is disposable), `--maxmemory 256mb`,
  `--maxmemory-policy volatile-lru`. Under memory pressure Redis evicts only
  keys that have a TTL, so the catalog version key (no TTL) is never evicted.
- Backend image: `phpredis` extension, in its own Docker layer after
  `composer install`, so code changes don't rebuild it.
- Cache store `catalog` (`config/cache.php`) on its own Redis connection
  (`config/database.php`, database 2): connect timeout 0.3 s, read timeout
  0.5 s, **no retries**. Laravel's default Redis connection retries 3 times
  with backoff, which would add up to ~1 s to every request while Redis is down.
- `CATALOG_CACHE_STORE` selects the store (`catalog` in Docker, `array` in the
  tests). The default cache store (`CACHE_STORE=database`, used by the login
  rate limiter) is unchanged: moving it to Redis would make logins fail with
  a 500 when Redis is down.

**Where the code is.** All of it stays inside the products module:
`ProductsCache` (keys, TTLs, locking, failure handling) is used only by
`ProductsService`. The orders module reports stock changes through the public
`ProductsModule::stockChanged()`. Cached values are plain arrays and ints
(rows as the repository returns them), never objects.

#### What we cache, what we don't

| Data | Cached? | Why | TTL | Invalidation |
|---|---|---|---|---|
| Product detail row (`GET /products/{id}`, without stock) | **Yes** | The most-read single object; same for every visitor | 10 min fresh, then served stale up to 20 min while refreshed | Deleted after the commit of an update (fields) |
| Catalog page rows (`GET /products`) | **Yes** | Public, identical for every role; the first pages take most traffic | 2 min fresh, stale up to 10 min | Catalog version `INCR` after the commit of any create or update; the key contains the version |
| Catalog `COUNT(*)` per category | **Yes** | 10.9 ms full index scan on every list request, only for `pagination.total` | 2 min fresh, stale up to 10 min | Same version key |
| Display stock per product | **Yes, for display only** | One gRPC `GetStock` per catalog request otherwise | **5 s** | Deleted after AdjustStock, after every reserve and every release |
| Catalog version | Stored, no TTL | Must never expire, or old page keys would come back | none | Atomic `INCR` (Redis `INCRBY`) |
| Prices used to create an order (`getOrderLines`) | **Never** | An order must charge the price in the database at that moment, never a cached one | — | — |
| Stock used to reserve | **Never** | Reservations always call the real `ReserveStock`; the inventory service decides | — | — |
| Auth, JWT, `/auth/me`, users | **Never** | Security data; a cached role or account would outlive a change | — | — |
| Orders, order details, notifications | **Never** | Per user and change with every action; caching them would need per-user keys and invalidation on every write, for little reuse | — | — |
| 404 for an unknown product | No | Rare; caching misses would make a new product id look missing | — | — |

#### Keys

```
products:item:{id}
products:list:v{version}:c={category|*}:l={limit}:p={page}
products:count:v{version}:c={category|*}
products:stock:{id}
products:catalog-version
```

- **Every input that changes the result is in the key**: category, limit (the
  API's `per_page`) and page. The API has no sort or search parameter (the
  order is always newest first); a future one must be added to the key.
- **Normalized:** the key is built from the validated values, not the raw
  query string. `?page=1`, `?page=01` and no `page` are the same key;
  `category= Home ` and `category=home` are the same key; unknown parameters
  (`?utm=x`, cache busters) are ignored and can't fragment the cache.
- **No role in the key:** the catalog response is the same for customers,
  admins and anonymous visitors (checked in `listProducts` / `getProduct`).
  If admins ever get extra fields, the role must go into the key.

#### Invalidation after commit

Writes call `ProductsCache::productChanged()` / `stockChanged()`, which register
the delete/`INCR` with `DB::afterCommit()`:

- Outside a transaction it runs at once; inside one (product create) it runs
  only after `COMMIT`, and a rollback discards it.
- Invalidating *before* the commit would let another request read the old row
  between the delete and the commit and cache it again, stale until the TTL.
- While a database transaction is open, `ProductsCache` bypasses Redis
  entirely (no reads, no writes), so data that might still be rolled back is
  never cached.

**Product image.** The API has no separate image upload: an image is sent with
`POST /products`. Creating a product (with or without an image) bumps the
catalog version after commit, so the new product and its image appear in the
catalog at once. There is no product delete endpoint.

**Display stock may lag up to 5 s** in two cases: a request that read the old
stock just before a change and stores it just after the delete (a race), and a
reserve that timed out and is later released from the outbox (its order was
never saved, so its products are unknown when the release is delivered). In
both cases the entry simply expires. Stock shown on the page is informational;
the reservation is always checked by the inventory service.

#### Stampede protection

Two different moments, two mechanisms:

- **Expiry: `Cache::flexible` (stale-while-revalidate).** After the fresh TTL,
  requests keep getting the stale value at once, and one deferred refresh
  (behind Laravel's lock, given a 10 s expiry so a crashed refresher can't hold
  it forever) recomputes it after the response is sent. Nobody waits and the
  database sees one query.
- **Cold miss: a lock (`Cache::lock(...)->block(3)`).** `flexible` alone runs
  the callback in every request that finds the key missing. That is exactly
  what happens after an invalidation: a version bump makes every page key new
  at once. With the lock, one request computes; the others wait (up to 3 s),
  then read its result. If the wait times out they compute themselves rather
  than fail.

Why not only one of them: a lock alone makes every request wait at each expiry;
`flexible` alone lets every request hit the database after each product edit.
Probabilistic early expiration was not needed: `flexible` already refreshes
before the value disappears.

#### When Redis is down

Every cache call is wrapped: on the first failure in a request `ProductsCache`
logs one warning (`catalog cache unavailable, serving from the database`),
skips Redis for the rest of that request and computes from PostgreSQL. A failed
invalidation is logged and the entries expire with their TTL. No request fails
because of the cache; it is only slower.

Tests (`tests/Feature/ProductsCacheTest.php`):

- an edit is visible on the next read (detail and catalog page);
- a rolled-back edit leaves the cache with the old data and no version bump;
- equivalent requests share one entry (0 queries), a different page is a new entry;
- display stock is dropped after a reserve/release and after AdjustStock;
- a new product appears in the cached catalog at once;
- Redis down (store pointed at a closed port): the catalog, the detail and an
  edit all work, served from the database, and a warning is logged.

**Redis down, measured live** (`docker compose stop redis`): every request
still answers 200 from the database, with one warning in the log per request.
The first request took 1.34 s (resolving the stopped container's name fails
slowly, before the 0.3 s connect timeout applies); the next ones ~100 ms
instead of ~50 ms. If that matters, the next step is a small circuit breaker
that skips Redis for a few seconds after a failure, shared between requests.

#### Quick check at 100 req/s

Same data as 6.3 (no re-seed), quiet machine, 30 s warm-up, one 2-minute run
with the query counter off; then a separate 30 s run with
`PERF_QUERY_LOG=true` for queries per request and the per-endpoint hit ratio
(2026-11-10).

| Endpoint | After 6.3: p50 / p95 / p99 | After 6.4: p50 / p95 / p99 | Queries / request, 6.3 → 6.4 | Cache hit ratio |
|---|---|---|---|---|
| `browse_list` | 52.5 / 76.3 / 102.6 | **15.3** / 65.8 / 84.9 | 2 → **0.2** | 70.7 % |
| `product_detail` | 38.1 / 57.6 / 76.7 | 41.5 / 59.7 / 73.3 | 1 → 0.9 | 5.5 % |
| `my_orders` | 44.3 / 62.6 / 83.2 | 42.3 / 59.9 / 76.3 | 3 | not cached |
| `order_detail` | 42.4 / 61.1 / 83.8 | 40.3 / 57.5 / 70.6 | 3 | not cached |
| `my_notifications` | 38.0 / 56.2 / 71.4 | 36.6 / 52.4 / 65.7 | 2 | not cached |
| `create_order` | 81.2 / 115.1 / 143.3 | 80.3 / 111.7 / 132.2 | 8 | not cached |

- **The catalog is 3.4× faster at p50** (52.5 → 15.3 ms) and runs 0.2 queries
  per request instead of 2: most requests touch neither PostgreSQL nor the
  inventory service. What remains is Laravel's boot and the Redis round trips.
- **Product detail did not improve, and that is expected from the test, not
  the cache.** k6 picks a product uniformly at random among 50 000, so a
  given product is rarely requested twice within its TTL: 5.5 % hits. Real
  traffic is skewed toward popular products and would hit far more often. The
  scenario was not changed, so the comparisons stay valid.
- **Hit ratio definition:** cache lookups answered from Redis / all lookups,
  counted by `ProductsCache` (`X-Cache-Hits` / `X-Cache-Misses`). A catalog
  page makes up to 22 lookups (page, count, 20 display-stock entries), so its
  ratio is weighted by the 5 s stock entries; the page and count entries hit
  more often than 70.7 %.
- **Redis' own counters** during the 2-minute run: 64 867 hits / 45 274
  misses (58.9 %). That number mixes in Laravel's internal keys (the
  `flexible` timestamps, lock attempts, the version key) and is shown only as
  a cross-check.
- The other endpoints are not cached and stayed within run-to-run variation.

### 6.5 HTTP caching: ETag and 304

**Scope:** `GET /products` and `GET /products/{id}` only (and `HEAD` on them).

**Behavior** (`App\Shared\Http\Middleware\ETagRevalidation`, applied to those
two routes in `app/Modules/Products/routes.php`):

- A `200` gets `ETag: "<hash of the body>"` and
  `Cache-Control: no-cache, public`. `no-cache` means "you may keep a copy,
  but ask me every time": an edit is visible on the very next request. There
  is **no `max-age`**: with one, a client would reuse its copy without asking
  and miss an edit until it expired.
- `public` is there because the body is the same for every caller, and
  because Symfony appends `private` to any `Cache-Control` that names neither,
  so a bare `no-cache` cannot be sent. `public, no-cache` lets a future proxy
  or CDN store the response too, still revalidating every time.
- When `If-None-Match` matches: `304 Not Modified`, empty body, same `ETag` and
  `Cache-Control`. Matching follows RFC 9110: a comma-separated list, `*`, and
  the weak comparison (`W/"x"` matches `"x"`).
- Errors (400, 404, 5xx), redirects and writes get no `ETag`, and a 304 never
  replaces an error: `If-None-Match: *` on a missing product is still a 404.
- `HEAD` gets the same `ETag` as the `GET`: Laravel empties a HEAD body before
  route middleware runs, so the middleware builds the response as a GET and
  drops the body itself (a test caught this: HEAD first had the ETag of an
  empty string).
- **Same for every user:** both endpoints are public and never read the user
  or role (the frontend calls them without a token), so no `Vary:
  Authorization`. If they ever return role-dependent data, add it.

**How the ETag is computed: a hash of the final body (xxh128).** Correct by
construction: any change in any field changes the bytes, so it changes the
ETag. That covers the price and the other fields, `stock` and `stock_status`
(inventory up vs down give different bodies, so different ETags), and the
page, category and limit (different bodies). Equivalent requests
(`?page=1&limit=20` and no parameters) have the same body, so the same ETag,
which is right: it identifies the representation, not the URL. A
version-based ETag (catalog version + `updated_at` + stock of every product on
the page + `stock_status` + parameters) would skip the hashing, but it would
have to list every input of the response, and one input forgotten (now or when
a field is added) means a wrong 304 and stale data on screen. Hashing ~10 KB
with xxh128 costs microseconds, and the body is built from Redis anyway.
Measured with the freshness proof: putting price and stock back to their old
values still gives a new ETag, because `updated_at` changed, so the body did.

**What a 304 saves here, and what it doesn't.** The server does the same work
for a 304 as for a 200: Laravel boots, reads Redis (or the database), builds
the JSON and hashes it. A 304 saves the **transfer of the body** (a catalog
page is ~10 KB, a 304 is a few hundred bytes of headers) and the **client's
work** (parsing the JSON and re-rendering). On a phone or a slow network that
is most of the wait; in our load test, k6 runs on the same machine as the API,
so the network is nearly free and the server-side time barely changes.

**Nothing in between breaks it** (checked live):

- No compression on the way: Caddy has no `encode` directive and the
  frontend's nginx has no `gzip`. (A server that gzips can turn a strong ETag
  into a weak `W/` one; the weak comparison in `If-None-Match` handles that
  anyway.) A request with `Accept-Encoding: gzip` gets the same strong ETag.
- Through the frontend's nginx (`:8080`) the `ETag`, `If-None-Match` and the
  304 pass unchanged.
- No CORS: the frontend reaches the API on the same origin (nginx in Docker,
  the Vite proxy in development), and the browser revalidates by itself, so
  JavaScript never needs to read the `ETag` and no
  `Access-Control-Expose-Headers` is needed.

**Tests** (`tests/Feature/ProductsEtagTest.php`): first request 200 with an
ETag and `no-cache`; the same ETag gives 304 with an empty body; an admin edit
(`PATCH` with an admin token) gives 200, the new data and a new ETag for the
old one; `AdjustStock` gives a new ETag; inventory up vs down give different
ETags; different page / category / limit give different ETags and an
equivalent request the same one; 404 and 400 never get an ETag or a 304, even
with `*`; writes get no ETag; lists, `*` and `W/` in `If-None-Match`; HEAD has
the GET's ETag; the matcher itself against RFC 9110 cases.

**Freshness proof** (`docs/proofs/etag-freshness.js`, required by the
assignment):

```powershell
$env:ADMIN_EMAIL="admin@example.com"; $env:ADMIN_PASSWORD="<from .env>"
node docs/proofs/etag-freshness.js
```

Output on 2026-11-10 (Wireless Mouse sample product):

```
1. First read
   GET: 200  ETag "680adaafd2894057349731131badd5ba"  Cache-Control "no-cache, public"  body 265 B  price 19.99, stock 496
2. Same request with If-None-Match: <that ETag>
   GET: 304  ETag "680adaafd2894057349731131badd5ba"  body 0 B
3. Admin changes the price 19.99 -> 20.99 and adds 5 to the stock
   PATCH price: 200, PATCH stock: 200
4. Immediately: GET with the OLD ETag
   GET: 200  ETag "d99f808a9edd7dd52d077d35cf2a1c02"  body 265 B  price 20.99, stock 501
5. GET with the NEW ETag
   GET: 304  ETag "d99f808a9edd7dd52d077d35cf2a1c02"  body 0 B
6. Put the product back
ALL CHECKS PASSED (9 checks)
```

The edit is visible on the first request after it, for a client that still
holds the old ETag: the Redis entry is invalidated after commit (6.4), so the
body is new, so the ETag is new, so the old one no longer matches.

**k6: a client that revalidates.** Each VU keeps the last ETag per URL and
sends it as `If-None-Match` on `browse_list` and `product_detail`, as a
browser does (`ETAG=0` turns it off). 304s are counted as their own category
(not as errors), with separate response-time trends for 200 and 304, the 304
ratio, and bytes received per endpoint.

#### Quick check at 100 req/s

Two runs back to back on the same data and the same machine state, each after
a 30 s warm-up: `ETAG=0` (the client never sends `If-None-Match`, which is the
previous step's behavior) and `ETAG=1` (it revalidates like a browser).
2026-11-10.

| | `ETAG=0` (as in 6.4) | `ETAG=1` |
|---|---|---|
| `browse_list` p50 / p95 / p99 | 15.6 / 67.6 / 93.8 ms | 14.0 / 57.3 / 67.9 ms |
| `browse_list` 304 ratio | — | **60.7 %** |
| `browse_list` 200 vs 304 (p50 / p95 / p99) | 200: 15.6 / 67.6 / 93.8 | 200: 27.0 / 63.0 / 76.2 · **304: 12.7 / 23.3 / 30.3** |
| `browse_list` bytes received | 31.19 MiB | **14.12 MiB (−55 %)** |
| `product_detail` p50 / p95 / p99 | 42.1 / 66.7 / 100.6 ms | 38.7 / 52.6 / 66.8 ms |
| `product_detail` 304 ratio | — | 0.0 % (1 request in 3 001) |
| `product_detail` bytes received | 3.65 MiB | 3.65 MiB |
| All data received (setup included) | 79.6 MiB | 62.9 MiB (−21 %) |

All requests: 0 errors, 0 dropped iterations.

What the numbers say:

- **The bytes are the clear win.** 60.7 % of catalog requests were answered
  with a 304, and the catalog's received bytes fell by 55 %. That is what an
  ETag saves: the body on the wire and the client's work with it.
- **A 304 is faster mostly because of what is not sent and parsed.** 304 p50
  12.7 ms. The server work is the same as for a 200 (boot, Redis, JSON, hash),
  so the gap to a fast 200 is a few ms here, where k6 sits next to the API; on
  a real network the body transfer is a larger share of the wait.
- **The "200" column in the `ETAG=1` run is not comparable to the `ETAG=0`
  run** (27.0 vs 15.6 ms p50). It is a different mix: the requests that still
  get a 200 are the ones whose ETag the VU did not have or that changed,
  mostly deep pages seen for the first time, which are also cache misses in
  Redis. The fast repeat visits moved into the 304 column.
- **Product detail gains nothing in this test, by design of the test.** A VU
  asks for a random product among 50 000, so it almost never asks for the
  same one twice, and a 304 needs a previous copy. A real visitor going back
  to a product page would get one.
- **The other endpoints were also 2–4 ms faster in the second run**, though
  they don't use ETags at all. That is run-to-run variation (and slightly less
  CPU load from smaller responses), and it puts the p50 differences above in
  proportion: the bytes and the 304 ratio are the solid results; single-run
  p95/p99 differences are not.
- **VUs revalidate more than browsers would.** A VU makes thousands of
  requests and keeps its ETags for the whole run, so the 60.7 % is an upper
  range for repeat visits to the first catalog pages, not a prediction for
  real users.

### 6.6 Read replica and read-your-writes

```
                writes, transactions, sticky reads,
                reads that must see a recent write
   backend ───────────────────────────────────────────▶  db (primary)
      │                                                     │ WAL, streaming
      │   other reads, when the replica is healthy          ▼
      └───────────────────────────────────────────────▶  db_replica (hot standby, read-only)
      │
      └──▶ redis: ryw:user:{id} = LSN of the user's last write
                  products:last-write-lsn, db:replica-status (shared, 200 ms), db:replica-down
```

**Infrastructure** (official `postgres:16-alpine` only, `README.md` → "Read replica"):

- `db` is the primary: `wal_level=replica` (PostgreSQL 16's default),
  `max_wal_senders=5`, `max_replication_slots=5`, `max_slot_wal_keep_size=1GB`,
  and `hba_file=/etc/postgresql/pg_hba.conf` mounted from `postgres/pg_hba.conf`
  (the image's defaults plus one `host replication all all scram-sha-256`
  line). A mounted hba file works on the existing data volume, where
  `docker-entrypoint-initdb.d` scripts no longer run.
- `db_replica` runs `postgres/replica-entrypoint.sh`: on an empty volume it
  creates the replication role on the primary (`REPLICATION_USER` /
  `REPLICATION_PASSWORD` from `.env`, never committed), drops a leftover
  inactive slot, and runs `pg_basebackup -R -X stream -C -S replica_slot`; on
  every start it runs `postgres` as a hot standby with
  `recovery_min_apply_delay=$REPLICA_APPLY_DELAY` (0 unless the lag demo is on).
- The slot keeps WAL for a stopped replica; `max_slot_wal_keep_size=1GB` caps
  it, so a replica that is down for long cannot fill the primary's disk (the
  slot is invalidated instead, and the replica is rebuilt: README).
- Only the Laravel database has a replica; `inventory_db` is unchanged.

**Laravel.** `config/database.php`: when `DB_READ_HOST` is set, the `pgsql`
connection gets `read.host = db_replica` (2 s connect timeout),
`write.host = db` and `sticky = true`. `DB_READ_HOST` is empty, so a single
connection to the primary, for the tests, the scheduler (outbox,
`inventory:flush-outbox`), `perf-tools` (`perf:seed` reads its own temporary
tables) and the migrations and seeders (`docker-entrypoint.sh` runs them with
`DB_READ_HOST=`), so none of them ever reads a lagging replica.
`OrdersRepository::outboxPending()` also reads with
`selectFromWriteConnection`.

What Laravel already guarantees (checked in `ReadRouterTest`): every write
uses the primary; **every read inside a transaction uses the primary**
(`getReadPdo()` returns the write PDO while `transactions > 0`); and with
`sticky`, every read after a write **in the same request** uses the primary.

**A bug the replica exposed.** Five writes used `DB::select('INSERT/UPDATE
... RETURNING ...')` to get the row back. Laravel treats `select()` as a read,
so with the replica on, the product edit was sent to `db_replica` and failed
(`read-only transaction`), and those writes did not mark the connection as
written, so `sticky` did not apply after them. They now go through
`App\Shared\Database\Sql::returning()` (primary + marks the write), and
`tests/Architecture/WritesUseThePrimaryTest` fails the build if a write
statement goes through a read method again. The proof script found it.

#### Routing rules (`App\Shared\Database\ReadRouter`)

Decided lazily, on the first read of a request that Laravel would send to the
read connection:

| Situation | Reads go to |
|---|---|
| Write, transaction, read after a write in the same request | primary (Laravel) |
| The user wrote recently and the replica has not replayed that write yet (`pg_last_wal_replay_lsn()` < the user's LSN) | primary |
| The user wrote recently and the replica is past it | replica |
| No recent write by this user (or anonymous) | replica |
| Replica lag above `REPLICA_MAX_LAG_SECONDS` (5 s) | primary, one warning |
| Replica unreachable | primary, one warning; every request skips it for `REPLICA_DOWN_SECONDS` (10 s) |
| Redis down, or the check fails | primary |

**Read-your-writes: LSN, not a time window.** After every successful write
(non-GET, 2xx) by a signed-in user, the global middleware `RecordWrites`
stores the primary's `pg_current_wal_lsn()` under `ryw:user:{id}` (TTL 5 min).
That user's reads use the replica only once `pg_last_wal_replay_lsn()` on the
replica has reached that position. Why LSN:

- It is exact: it holds however long the lag is. A time window (e.g. "primary
  for 5 s after a write") is wrong exactly when it matters, when the replica
  lags more than the window, and needlessly sends reads to the primary when
  the replica caught up in milliseconds (the usual case here).
- The TTL only cleans the key up. If the replica were more than 5 minutes
  behind, the lag threshold already sends everyone to the primary.
- It covers every write path without touching the services: create order,
  status change, cancel, mark notification(s) read, device register and
  unregister, admin product create, edit and stock.

**The key is stored before the response leaves.** `RecordWrites` does it in
`handle()`, after the controller and before returning the response, never in
`terminate()` (which runs after the response is sent). Otherwise a fast client
could send its next GET before the key exists and read from the replica.
`RecordWritesTest` checks both: the key exists when `handle()` returns, and the
class has no `terminate()`.

**The replica position is checked at most every 200 ms, shared.** The router
keeps `{replay_lsn, lag}` in Redis (`db:replica-status`) and queries the
replica (one `SELECT` on the connection it opens anyway) only when that entry
is older than `REPLICA_STATUS_TTL_MS` (200 ms): a handful of status queries per
second in total, not one per request. Lag counts only while WAL is waiting to
be replayed (`receive_lsn <> replay_lsn`), so an idle replica has no lag.

**The guarantee's limit.** Read-your-writes covers the user's **own** writes.
Writes by others reach a user through the replica after the replication lag:
when an admin changes the status of a customer's order, that customer may see
the old status until the replica has replayed it (eventual consistency). With
the measured lag (below) that is a few milliseconds; with the demo delay it
is 3 s.

#### The Redis cache + replica trap

After a product edit the cache entry is invalidated. If the next request
refills it from a replica that has not replayed the edit yet, the **old** row
is cached again, for the whole TTL (10 minutes for a product). Always
refilling from the primary would avoid it but would send almost every refill
there (95 % of product-detail lookups are misses in our test). Instead:

1. After the commit of any product write, `ProductsCache` stores the primary's
   LSN as `products:last-write-lsn` (no TTL).
2. A refill (cold miss or `flexible`'s background refresh) reads the replica
   only if the replica has replayed past that LSN; otherwise, or if Redis or
   the check fails, it reads the primary (`useWriteConnectionWhenReading`).
   The comparison is against the last product write, so between product edits
   refills go to the replica as before.
3. Write-through for the product detail: after an edit, the fresh row is read
   from the primary and stored in the cache at once (with `flexible`'s
   timestamp), so there is no hole to refill at all. Catalog pages keep the
   version-key invalidation, protected by rule 2.

`ProductsCacheReplicaTest` covers it with a replica that is behind: refills
go to the primary while it is behind the last write, to the replica once it is
past it; without any product write refills never wait; an edit writes the
fresh row through and records its LSN.

#### Proof: the user sees what they just wrote

`docs/proofs/replica-read-your-writes.js`, with `REPLICA_APPLY_DELAY=3s`
(2026-11-10):

```
Read-your-writes proof against http://localhost:3000; replica recovery_min_apply_delay = 3s

1. Customer creates an order, then reads it back at once
   PASS  order created  (201, id 1b0208fc-abe3-4159-bb16-35d2c3961182)
   PASS  GET /orders shows it  (200, 48 ms, reads: primary)
   PASS  GET /orders/{id} shows it  (200, 47 ms, reads: primary)

2. The same order read directly on the replica, right now
   PASS  the replica does NOT have it yet (the lag is real)  (count = 0)
   PASS  after 3500 ms the replica has it  (count = 1)

3. Admin edits a product, then it is read at once
   PASS  price 20.99 -> 21.99 accepted
   PASS  GET /products/{id} with the old ETag: 200, not 304  (200, reads: none)
   PASS  it shows the new price at once  (21.99)
   PASS  with a new ETag
   PASS  while the replica itself still has the old price  (replica: 20.99)

ALL CHECKS PASSED (10 checks)
```

`reads: none` in step 3: the product came from Redis, written through after
the edit; neither database was read. (`reads:` is the `X-DB-Read` header,
present when `PERF_QUERY_LOG=true`.)

**Replica stopped, measured live:** every request answered 200 from the
primary. The first took 1.5 s (resolving the stopped container's name fails
slowly), the next ones ~40 ms, because the 10 s "replica down" key makes every
request skip it.

#### Quick check at 100 req/s

Back to back on the same data and machine state (2026-11-10), each after a
30 s warm-up: **A** without the replica (`DB_READ_HOST=`, every read on the
primary) and **B** with it; then **C**, 30 s with `PERF_QUERY_LOG=true` for the
routing counters. `pg_stat_replication.replay_lag` sampled every 5 s during B.

| Endpoint | A: no replica, p50 / p95 / p99 | B: replica, p50 / p95 / p99 |
|---|---|---|
| `browse_list` | 14.9 / 64.8 / 88.5 | 14.7 / 62.4 / 81.2 |
| `product_detail` | 39.8 / 59.2 / 96.2 | 41.3 / 62.0 / 101.2 |
| `my_orders` | 39.5 / 58.6 / 92.5 | 42.5 / 64.2 / 101.9 |
| `order_detail` | 38.3 / 56.6 / 92.8 | 41.3 / 62.3 / 100.1 |
| `my_notifications` | 34.7 / 51.7 / 87.0 | 37.7 / 57.6 / 90.9 |
| `create_order` | 76.1 / 109.0 / 164.0 | **96.0 / 133.4 / 180.8** |

0 errors and 0 dropped iterations in both.

**Replication lag during B:** median **2.2 ms**, min 1.8 ms, max 14.8 ms
(24 samples). That is the window of eventual consistency for other users'
writes.

**Routing (C):**

| Endpoint | Reads on replica / primary | Routing decision, avg / p95 | Replica connect, avg | Cache refills replica / primary |
|---|---|---|---|---|
| `browse_list` | 79 / 0 | 0.05 / 0.51 ms | 13.4 ms | 79 / 0 |
| `product_detail` | 632 / 0 | 0.45 / 0.78 ms | 12.9 ms | 632 / 0 |
| `my_orders` | 450 / 1 | 2.93 / 6.18 ms | 13.6 ms | — |
| `order_detail` | 361 / 0 | 2.84 / 5.83 ms | 13.4 ms | — |
| `my_notifications` | 391 / 0 | 3.12 / 6.02 ms | 13.2 ms | — |
| `create_order` | 149 / 1 | 2.96 / 5.78 ms | 14.7 ms | — |

(Requests answered entirely from Redis read no database and are not counted;
that is why `browse_list` shows only 79.)

What the numbers say, honestly:

- **The replica does not make anything faster here, and `create_order` got
  ~20 ms slower.** Every read endpoint is within a few ms of A (slightly
  worse). `create_order` now reads the products first, so its first read opens
  a connection to the replica (~13 ms, scram auth), and then the write opens a
  second connection to the primary. Without a replica it opens one. Same cause
  for a smaller share of the read endpoints: the replica connection costs what
  the primary connection cost, so nothing is saved per request.
- **The routing decision itself is cheap:** 0.05–0.45 ms for anonymous
  requests (two Redis lookups, the replica position from the shared 200 ms
  entry), ~3 ms for signed-in requests (one more lookup for the user's write
  key, and they more often refresh the shared position). The replica
  connection (~13 ms) is the real per-request cost, and it is the same as the
  primary connection it replaces.
- **Almost every read went to the replica** (1 862 of 1 864 requests); the 2
  on the primary are reads right after the same user's write that the replica
  had not replayed yet: read-your-writes at work. All 711 cache refills read
  the replica: no product was edited during the run, so the last-write check
  never had to send them to the primary.
- **Why no gain on this machine:** both databases run on the same 12 CPUs and
  the same disk, so moving reads to the replica only moves the load, it does
  not add capacity. The primary was not the bottleneck either (section 5: the
  time per request is mostly Laravel's boot and connection setup, and most
  catalog reads now never reach a database at all, thanks to Redis).
- **When a replica does help:** on separate hardware, when the primary is
  saturated by reads (CPU or I/O), to keep heavy reads (reports, exports,
  analytics) off the primary, and for availability (a standby ready to be
  promoted). Here it is in place and correct (read-your-writes, the cache
  trap, the fallbacks); its gain needs a second machine to show.

### Observed behavior

**Collapse at 120 req/s: queueing close to capacity (2026-11-10).**
After 6.2, two quick runs at 120 req/s fell apart: p50 went from ~50 ms to
5–9 s for **every** endpoint at once, p95 to 12–17 s, with 1 100–1 600 dropped
iterations and no errors. What we checked:

- **Not the code change.** Reverting 6.2 and running again gave the same
  collapse (p50 4.8 s).
- **Not the database state.** No dead rows, no autovacuum running, statistics
  fresh, the expected index used by every query.
- **Not general slowness.** A run at 40 req/s right after was normal (p50
  35–44 ms, better than the baseline).
- **The machine had less CPU to spare.** During the collapse PostgreSQL used
  ~570 % CPU and the backend ~520 %, about all 12 cores, more than during the
  160 req/s step run. A Visual Studio Installer update had started
  downloading in the background (`BackgroundDownload.exe`, started 19:44);
  the last good run ended about 19:40 and the first collapse started about
  19:45. Even at 100 req/s, p50 stayed normal (43–86 ms) but p95 reached
  1.3–1.5 s while it was running.

Why it collapses instead of slowing down gradually: with a fixed arrival rate,
when the server falls even slightly behind, requests start to wait. More
waiting requests mean more PHP threads and PostgreSQL connections working at
the same time, which compete for the same cores, so each request takes longer,
so even more requests wait. Below the ceiling the system recovers from a short
stall; right next to it, one stall tips it into a queue it cannot drain.
120 req/s (~70 % of the 165 req/s ceiling measured on a quiet machine) leaves
too little margin on a laptop that shares its CPUs with everything else.
That is also why p99 already varied 2–4× between the three 120 req/s baseline
runs.

Consequences for the method: quick checks at 100 req/s, heavy programs closed,
laptop on AC power, and before/after measured back to back (see the top of
this section).
