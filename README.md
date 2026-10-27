# JWT Auth (modular monolith): Node.js + Express + PostgreSQL + Docker

Two containers: `db` (PostgreSQL) and `backend` (Express API).

## Structure

    backend/src/
      index.js            starts the server (creates the admin user first)
      app.js              builds the Express app and mounts the modules
      shared/             code used by every module (db, errors, auth middleware)
      modules/
        users/            routes -> service -> repository  (finished)
        products/         empty for now
        orders/           empty for now
        notifications/    empty for now

Rule: a module only imports another module through that module's `index.js`.

## Setup

1. Copy `.env.example` to `.env` and fill it in (JWT_SECRET: `openssl rand -hex 64`).
2. Run:

       docker compose up --build -d
       docker compose logs -f backend

The API is at http://localhost:3000

## Endpoints

| Method | URL            | Access          |
|--------|----------------|-----------------|
| POST   | /auth/register | public (creates a customer) |
| POST   | /auth/login    | public, 5 failed attempts / 15 min per IP + email |
| GET    | /auth/me       | any logged-in user |
| GET    | /users         | admin only |
| GET    | /health        | public |

Errors always look like: `{ "error": { "code": "...", "message": "...", "details": [...] } }`

## Useful commands

    docker compose down            # stop (data kept)
    docker compose down -v         # stop and delete DB data (needed after editing db/init.sql)
    docker compose exec db psql -U appuser -d appdb
