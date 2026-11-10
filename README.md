# Docker Store: Laravel + PostgreSQL + a gRPC inventory service + Docker

Containers: `db` (PostgreSQL), `backend` (Laravel API on FrankenPHP), `scheduler`
(same image, delivers parked inventory releases), `inventory` (Node gRPC service
that owns product stock), `inventory_db` (its own PostgreSQL) and `frontend` (nginx).

Stock lives in the **inventory service**, an independent gRPC server with its own
database. The contract is [`proto/inventory/v1/inventory.proto`](proto/inventory/v1/inventory.proto)
(three operations: get the quantity, reserve it, release it — plus an admin
restock call). The full design, the failure handling and the demo walkthrough are
in [`docs/inventory-grpc.md`](docs/inventory-grpc.md).

## Structure

    backend/
      bootstrap/app.php     builds the app: routing, global middleware, the one exception renderer
      routes/app.php        /health, /api-docs, /uploads/products/<file>
      routes/api.php        mounts every module's routes under /api/v1
      app/Shared/           code used by every module
        AppError.php          one error type for the whole project
        Validation.php        UUID check, pagination
        Text.php, Time.php    string/number semantics, UTC timestamp format
        Events.php            in-process event bus between modules (publish / subscribe)
        Http/ApiResponse.php  one JSON envelope for data and errors
        Http/Middleware/      authenticate, authorize, security headers, JSON body check
      app/Modules/
        Users/              routes -> controller -> service -> repository
        Products/           routes -> controller -> service -> repository  (+ images)
        Orders/             routes -> controller -> service -> repository
        Notifications/      in-app notifications + Firebase push
      database/migrations/  schema (raw SQL, one file per module)
      database/seeders/     sample products + first admin user

Rule: a module only uses another module through that module's single public class
(`UsersModule`, `ProductsModule`, ...). `php artisan test --testsuite=Architecture`
fails the build when anything else is referenced across module borders.

## Setup (Docker)

1. Copy `.env.example` to `.env` and fill it in. JWT_SECRET must be a real random value
   (`openssl rand -hex 64`): the server refuses to start with a missing, short (< 32 characters)
   or placeholder secret, because anyone knowing the secret could sign an admin token.
2. Run:

       docker compose up --build -d
       docker compose logs -f backend

The API is at http://localhost:3000/api/v1

On every start the backend waits for the database, runs the migrations
(`php artisan migrate --force`) and the seeder (`php artisan db:seed --force`,
which inserts the 4 fixed-id sample products and creates the admin account from
`ADMIN_EMAIL` / `ADMIN_PASSWORD`), then serves on port 3000. Uploaded product
images are kept in the `uploads_laravel` Docker volume.

A healthy start prints, in `docker compose logs backend`:

    Push notifications are on (Firebase project: <id>).
    (or: Push notifications are off: no Firebase service account file (see README).)
    API listening on port 3000

The backend logs to stderr, so runtime errors also show up in `docker compose logs backend`.
`APP_KEY` may stay empty in `.env`: nothing in this API encrypts, and the container
generates a throwaway key for each run.

Interactive API documentation is at http://localhost:3000/api-docs. The standalone OpenAPI 3.1
document is [`docs/openapi.yaml`](docs/openapi.yaml). To call protected endpoints in Swagger UI,
first use `POST /api/v1/auth/login`, copy its `token`, click **Authorize**, and enter the token
in the Bearer authentication dialog. Public registration creates customer accounts; admin accounts
come from `ADMIN_EMAIL` and `ADMIN_PASSWORD` at startup.

## Running without Docker (Windows)

Requirements: PHP 8.4 with pdo_pgsql/pgsql/intl/mbstring/curl (with HTTP/2),
composer, Node.js 20+, PostgreSQL 16. Three processes run side by side:

PostgreSQL, then the inventory service:

    cd inventory-service
    npm install
    copy .env.example .env   # DATABASE_URL of its own database (e.g. "inventory")
    node src/server.js       # applies its migrations, listens on 0.0.0.0:50051

Then the API:

    cd backend
    composer install
    copy .env.example .env   # fill in DB_*, JWT_SECRET, ADMIN_*, INVENTORY_GRPC_ADDR
    php artisan migrate --force
    php artisan db:seed --force
    php artisan app:check-env
    php artisan serve --port=3000

`SIMULATE_DELAY_MS` in the inventory service's `.env` is a demo-only setting: it
delays every call by that many milliseconds, to show the client deadline
(`INVENTORY_TIMEOUT_MS`, default 2000 ms) in action. Leave it 0.

### Regenerating the PHP protobuf classes

The classes in `backend/generated/` are committed. After changing the contract,
regenerate them with protoc (https://github.com/protocolbuffers/protobuf/releases):

    protoc --php_out=backend/generated --proto_path=proto proto/inventory/v1/inventory.proto

## Performance

Load tests and the baseline numbers (dataset seeder, k6 scenarios, results and
the problems to fix next) are in [`docs/performance.md`](docs/performance.md).

### Read replica

`db_replica` is a read-only hot standby of `db` (PostgreSQL streaming
replication), built with the official image only. The API sends reads to it
when that is safe and everything else to `db` (`docs/performance.md`, 6.6).
The API never depends on it: with the replica stopped, every read uses `db`.

**First start.** Add the replication login to `.env` (any values; see
`.env.example`), then start everything as usual:

    REPLICATION_USER=replicator
    REPLICATION_PASSWORD=<a long random value>

On its first start `db_replica` creates that role on `db` and copies the
primary with `pg_basebackup` (a few seconds on the perf dataset). Check it:

    docker compose exec db psql -U <POSTGRES_USER> -d <POSTGRES_DB> -c "select client_addr, state, replay_lag from pg_stat_replication"

`state` must be `streaming`.

**Demo: artificial lag.** `REPLICA_APPLY_DELAY` delays replay on the replica
(`recovery_min_apply_delay`), to show that users still see their own writes:

    $env:REPLICA_APPLY_DELAY="3s"; docker compose up -d db_replica       # on
    Remove-Item Env:REPLICA_APPLY_DELAY; docker compose up -d db_replica # off (0)
    node docs/proofs/replica-read-your-writes.js

**Run without the replica.** `DB_READ_HOST=` (empty) gives the backend a single
connection to `db`:

    $env:DB_READ_HOST=""; docker compose up -d backend

**Reset the replica** (to rebuild it from scratch, e.g. after its slot was
invalidated, see below):

    docker compose rm -sf db_replica
    docker volume rm docker-store_replica_pgdata_laravel
    docker compose up -d db_replica

**Replication slot and disk space.** The replica uses a replication slot
(`replica_slot`), so `db` keeps the WAL the replica has not received yet: a
replica that is stopped for a while catches up when it comes back. The cost is
that a stopped replica makes `db` keep WAL on disk. `db` runs with
`max_slot_wal_keep_size=1GB`: beyond that the slot is invalidated
(`wal_status = lost` in `pg_replication_slots`), `db` frees the WAL, and the
replica can no longer catch up. Then reset it as above. Its first start also
drops a leftover inactive slot. Migrations, seeders, the scheduler and
`perf:seed` always run on `db`.

## Endpoints

Every URL is versioned under `/api/v1` (except `/health`, `/api-docs` and `/uploads`).

| Method | URL                          | Access |
|--------|------------------------------|--------|
| POST   | /api/v1/auth/register        | public (creates a customer) |
| POST   | /api/v1/auth/login           | public, 5 failed attempts / 15 min per IP + email |
| GET    | /api/v1/auth/me              | any logged-in user |
| GET    | /api/v1/users                | admin only |
| GET    | /api/v1/products             | public |
| GET    | /api/v1/products/:id         | public |
| POST   | /api/v1/products             | admin only (multipart, optional image) |
| PATCH  | /api/v1/products/:id         | admin only |
| PATCH  | /api/v1/products/:id/stock   | admin only |
| POST   | /api/v1/orders               | customer only |
| GET    | /api/v1/orders               | customer: own orders only, admin: all orders |
| GET    | /api/v1/orders/:id           | customer: own order only, admin: any order |
| PATCH  | /api/v1/orders/:id/status    | admin only |
| GET    | /api/v1/notifications         | any logged-in user, own notifications |
| GET    | /api/v1/notifications/:id     | any logged-in user, own notification |
| PATCH  | /api/v1/notifications/:id/read | any logged-in user, own notification |
| PATCH  | /api/v1/notifications/read-all | any logged-in user, own notifications |
| GET    | /health                      | public |

Errors always look like: `{ "error": { "code": "...", "message": "...", "details": [...] } }`

### Products

Create (admin), as `multipart/form-data`:

    POST /api/v1/products
    name=Desk Lamp  price=24.50  stock=10  category=Home  description=...  image=<file>

- `image` is optional: JPEG, PNG or WebP, at most 2 MB. The type is checked from the file's
  content (a renamed text file or an SVG is refused with `400 INVALID_IMAGE`), and the file is
  saved under a new random name. Its URL is returned in `image_url`.
- Categories are stored in lower case. A JSON body (without image) also works.

List: `GET /api/v1/products?category=electronics&page=1&limit=20` (newest first).

Edit (admin): `PATCH /api/v1/products/:id` with any of `name`, `description`, `category`, `price`.

Stock (admin): `PATCH /api/v1/products/:id/stock` with `{ "change": 25 }` (or `-3`).
The change is relative and done in one SQL statement, so it never overwrites stock taken by
orders placed at the same moment. Going below zero returns `409 INSUFFICIENT_STOCK`.

### Orders

Create (customer):

    POST /api/v1/orders
    { "items": [ { "product_id": "00000000-0000-4000-8000-000000000001", "quantity": 2 } ] }

- Prices come from the database; any price or user_id in the body is ignored.
- Stock is reserved in the same transaction as the order: if one product is missing or short,
  nothing is saved (`404 PRODUCT_NOT_FOUND` / `409 INSUFFICIENT_STOCK`).
- Concurrent orders can't oversell: each product row is locked while its stock is taken.

List: `GET /api/v1/orders?page=1&limit=20&status=pending` (admins can add `&user_id=<uuid>`).
Response: `{ "data": [...], "pagination": { "page", "limit", "total", "total_pages" } }`

Status (admin): `PATCH /api/v1/orders/:id/status` with `{ "status": "confirmed" }`

    pending -> confirmed -> shipped -> delivered
       |           |
       +-----------+--> cancelled   (stock is given back)

Any other change returns `409 INVALID_STATUS_TRANSITION`. Every change is kept in the order's `history`.

A customer asking for someone else's order gets `404 ORDER_NOT_FOUND`, the same answer as for an
order that does not exist, so order ids can't be probed.

### Notifications

Creating an order and changing its status create persistent notifications for the order's customer
inside the same database transaction. Notifications are never addressed to the administrator who
changed an order. List with `GET /api/v1/notifications?page=1&limit=20`; the response is
`{ "data": [...], "pagination": { ... }, "unread_count": 2 }`. A notification can be read with
`PATCH /api/v1/notifications/:id/read`, or all of the caller's unread notifications with
`PATCH /api/v1/notifications/read-all`.

Filters: `?is_read=false` (unread only) and `?type=order_created|order_status_changed`.
`total` counts the filtered list; `unread_count` is always all unread (for the bell badge).
In the front end: a bell with the unread count (customers), and a Notifications page with filters.

### Push notifications (Firebase Cloud Messaging)

Optional. Without Firebase everything works the same; notifications are only shown in the app
(the bell refreshes every 30 s). With Firebase, the customer also gets a browser notification:

1. Put the service account JSON key in `secrets/firebase-service-account.json` (never commit it).
2. Fill in the `VITE_FIREBASE_*` values in `.env` (public web app config) and rebuild the frontend.

Dead device tokens reported by Firebase are deleted automatically.

## Tests

Tests NEVER run against the app's databases: each suite uses its own `*_test`
database and refuses to start otherwise (the suites migrate and truncate freely).

- Backend (phpunit): uses `docker_store_test` (set in `phpunit.xml`; connection
  details in a gitignored `backend/.env.testing`). In Docker, create it once on
  the `db` container and run the suite inside `backend`:

      docker compose exec db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "CREATE DATABASE docker_store_test"'
      docker compose exec -e DB_DATABASE=docker_store_test backend php artisan test

  It includes the compensation tests: a failed order save releases the
  reservation, and a failed release is parked in `inventory_outbox` and
  delivered by `php artisan inventory:flush-outbox`.
- Inventory service (node:test): uses `inventory_test` (TEST_DATABASE_URL, or
  the configured database name swapped for `inventory_test`):

      docker compose exec inventory_db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "CREATE DATABASE inventory_test"'
      cd inventory-service && npm test

- API: run the Postman collection `docs/docker-store.postman_collection.json` against
  http://localhost:3000 with the inventory service running (set the working
  directory to `docs/test-files` for the image uploads, and pass your
  `ADMIN_PASSWORD` as the `adminPassword` variable).
- Architecture: `php artisan test --testsuite=Architecture` enforces the module borders.
- API examples + HTML report: with the API and the inventory service running,

      cd docs/tools && npm install && npm run save-examples

  runs the full collection and regenerates
  `docs/docker-store.postman_collection.with-examples.json` (the collection with
  a real, saved example per request — import it in Postman to browse them) and
  `docs/reports/api-run.html` (request/response + pass/fail per call). The
  admin password is read from `backend/.env`; tokens and passwords are replaced
  with `<redacted>` in both outputs. The original collection is not modified.
- Demo proofs (race on the last unit, inventory down/slow): see `docs/proofs/`
  and the walkthrough in `docs/inventory-grpc.md`.
