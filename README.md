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
        notifications/    routes -> service -> repository  (in-app notifications + Firebase push)

Rule: a module only imports another module through that module's `index.js`.

## Setup

1. Copy `.env.example` to `.env` and fill it in. JWT_SECRET must be a real random value
   (`openssl rand -hex 64`): the server refuses to start with a missing, short (< 32 characters)
   or placeholder secret, because anyone knowing the secret could sign an admin token.
2. Run:

       docker compose up --build -d
       docker compose logs -f backend

The API is at http://localhost:3000/api/v1

Interactive API documentation is at http://localhost:3000/api-docs. The standalone OpenAPI 3.1
document is [`docs/openapi.yaml`](docs/openapi.yaml). To call protected endpoints in Swagger UI,
first use `POST /api/v1/auth/login`, copy its `token`, click **Authorize**, and enter the token
in the Bearer authentication dialog. Public registration creates customer accounts; admin accounts
come from `ADMIN_EMAIL` and `ADMIN_PASSWORD` at startup.

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

`db/init.sql` runs only when PostgreSQL initializes an empty data directory. For an existing volume,
apply the non-destructive migrations instead (works in PowerShell and bash), then rebuild:

    Get-Content db/migrations/001_notifications.sql | docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    Get-Content db/migrations/002_notification_devices.sql | docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
    docker compose up --build -d

Do not use `docker compose down -v` merely to add this feature.

### Push notifications (Firebase Cloud Messaging)

Optional. Without Firebase everything works the same; notifications are only shown in the app
(the bell refreshes every 30 s). With Firebase, the customer also gets a browser notification when
an order changes, even when no tab of the store is open.

How it works:

1. The customer clicks **Turn on** on the Notifications page; the browser asks for permission.
2. The front end gets this browser's FCM token from Firebase and sends it to
   `POST /api/v1/notifications/devices`. Signing out calls `DELETE /api/v1/notifications/devices/:token`.
3. When an order is created or changes status, the orders module publishes an event **after its
   transaction committed**; the notifications module sends the stored notification to all of the
   customer's browsers through the Firebase Admin SDK, and deletes tokens Firebase rejects.
4. The service worker (`frontend/public/firebase-messaging-sw.js`) shows it: a toast in a focused
   tab, otherwise a system notification; clicking it opens the order.

Setup (Firebase console, https://console.firebase.google.com):

1. Create a project, then **Project settings > General > Your apps > Add app > Web**.
   Copy the config values into `.env`: `VITE_FIREBASE_API_KEY`, `VITE_FIREBASE_AUTH_DOMAIN`,
   `VITE_FIREBASE_PROJECT_ID`, `VITE_FIREBASE_MESSAGING_SENDER_ID`, `VITE_FIREBASE_APP_ID`.
2. **Project settings > Cloud Messaging > Web Push certificates > Generate key pair**:
   copy the key into `VITE_FIREBASE_VAPID_KEY`.
3. **Project settings > Service accounts > Generate new private key**: save the downloaded file as
   `secrets/firebase-service-account.json`. It is a private key: the `secrets` folder is ignored
   by git and by the Docker build, and the file is mounted read-only into the backend container.
4. Rebuild: `docker compose up --build -d`. The backend log says
   `Push notifications are on (Firebase project: ...)`.

Browsers only allow push on `https://` or `http://localhost` (not `http://<LAN IP>`).
For local development without Docker, put the `VITE_FIREBASE_*` values in `frontend/.env.local`
and set `FIREBASE_SERVICE_ACCOUNT_FILE` to the key's path when starting the backend.

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
- folder "9. Notifications": list, filters, read / read-all, another customer's notification
  (404), push device registration

## Useful commands

    docker compose down            # stop (data kept)
    docker compose down -v         # stop and delete DB data (needed after editing db/init.sql)
    docker compose exec db psql -U appuser -d appdb
