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
        products/         routes -> service -> repository  (finished, + images)
        notifications/    empty for now

Rule: a module only imports another module through that module's `index.js`.

## Setup

1. Copy `.env.example` to `.env` and fill it in. JWT_SECRET must be a real random value
   (`openssl rand -hex 64`): the server refuses to start with a missing, short (< 32 characters)
   or placeholder secret, because anyone knowing the secret could sign an admin token.
2. Run:

       docker compose up --build -d
       docker compose logs -f backend

The API is at http://localhost:3000/api/v1

**After pulling a change to `db/init.sql`** (new tables), reset the database once, otherwise the
new tables are not created:

    docker compose down -v
    docker compose up --build -d

`init.sql` also inserts 4 sample products (fixed ids) used by the Postman tests.
Uploaded product images are kept in the `uploads` Docker volume.

## Endpoints

Every URL is versioned under `/api/v1` (except `/health`).

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
| GET    | /uploads/products/<file>     | public (product images) |
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

## API documentation and tests

Postman collection: `docs/docker-store.postman_collection.json`
(import it in Postman, then run the whole collection with the Collection Runner).

For the two image-upload requests, set Postman's working directory (Settings > General) to
`docs/test-files`, or pick `docs/test-files/sample-product.png` by hand in the `image` field.

It registers fresh accounts on every run and tests every endpoint:

- folder "4. Access control (proof)": reading another customer's order, changing order status or
  products as a customer, ordering as admin, and missing or forged tokens are all rejected
- folder "8. Login attempt limit": 5 failed logins for one email lock that email (from that IP)
  for 15 minutes, even with the right password; other accounts are not affected

## Useful commands

    docker compose down            # stop (data kept)
    docker compose down -v         # stop and delete DB data (needed after editing db/init.sql)
    docker compose exec db psql -U appuser -d appdb
