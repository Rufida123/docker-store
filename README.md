# JWT Auth (modular monolith): Node.js + Express + PostgreSQL + Docker

Two containers: `db` (PostgreSQL) and `backend` (Express API).

## Structure

    backend/src/
      index.js            starts the server (creates the admin user first)
      app.js              builds the Express app and mounts the modules under /api/v1
      shared/             code used by every module
        db.js               PostgreSQL connection pool
        transaction.js      withTransaction(fn): run several queries as one transaction
        validation.js       UUID check, pagination
        events.js           in-process event bus between modules (publish / subscribe)
        errors.js           AppError + asyncHandler
        middleware/         authenticate, authorize, error handler
      modules/
        users/            routes -> service -> repository  (finished)
        orders/           routes -> service -> repository  (finished)
        products/         stock functions used by orders; the products API is not built yet
        notifications/    routes -> service -> repository (persistent in-app notifications)

Rule: a module only imports another module through that module's `index.js`.

## Setup

1. Copy `.env.example` to `.env` and fill it in (JWT_SECRET: `openssl rand -hex 64`).
2. Run:

       docker compose up --build -d
       docker compose logs -f backend

The API is at http://localhost:3000/api/v1

**After pulling a change to `db/init.sql`** (new tables), reset the database once, otherwise the
new tables are not created:

    docker compose down -v
    docker compose up --build -d

`init.sql` also inserts 4 sample products (fixed ids) so orders can be tested before the products API exists.

## Endpoints

Every URL is versioned under `/api/v1` (except `/health`).

| Method | URL                          | Access |
|--------|------------------------------|--------|
| POST   | /api/v1/auth/register        | public (creates a customer) |
| POST   | /api/v1/auth/login           | public, 5 failed attempts / 15 min per IP + email |
| GET    | /api/v1/auth/me              | any logged-in user |
| GET    | /api/v1/users                | admin only |
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

`db/init.sql` runs only when PostgreSQL initializes an empty data directory. For an existing volume,
apply the non-destructive migration, then rebuild the backend:

    Get-Content db/migrations/001_notifications.sql | docker compose exec -T db psql -U $env:POSTGRES_USER -d $env:POSTGRES_DB

Do not use `docker compose down -v` merely to add this feature.

## API documentation and tests

Postman collection: `docs/docker-store.postman_collection.json`
(import it in Postman, then run the whole collection with the Collection Runner).

It registers fresh customers, tests every orders endpoint, and its folder "3. Access control (proof)"
shows that reading another customer's order, changing status as a customer, ordering as admin,
and missing or forged tokens are all rejected.

## Useful commands

    docker compose down            # stop (data kept)
    docker compose down -v         # stop and delete DB data (needed after editing db/init.sql)
    docker compose exec db psql -U appuser -d appdb
