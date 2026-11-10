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
