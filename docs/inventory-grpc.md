# Inventory over gRPC

Stock management lives in its own service. The main app (Laravel) keeps the
catalog, orders, users and notifications; the **inventory service** (Node.js)
owns quantities and reservations, in its **own database**, and the two talk
over **gRPC** only.

## Architecture

```mermaid
flowchart LR
    subgraph Client
        FE[Frontend React/nginx]
    end
    subgraph Main app
        BE[Laravel API backend:3000]
        SCH[scheduler: php artisan schedule:work]
        DB[(PostgreSQL docker_store)]
    end
    subgraph Inventory service
        INV[Node gRPC server inventory:50051]
        IDB[(PostgreSQL inventory)]
    end

    FE -- HTTP/JSON --> BE
    BE -- SQL --> DB
    SCH -- SQL (inventory_outbox) --> DB
    BE -- "gRPC (h2c)" --> INV
    SCH -- "gRPC ReleaseStock" --> INV
    INV -- SQL --> IDB
```

- The contract is `proto/inventory/v1/inventory.proto` — the single source of
  truth. The Node service loads it at runtime (@grpc/proto-loader); the PHP
  message classes in `backend/generated/` are generated from it by protoc.
- The PHP side has no official gRPC extension for this platform, so the client
  (`app/Modules/Inventory/GrpcChannel.php`) speaks the gRPC wire protocol
  directly: HTTP/2 prior knowledge over curl, `application/grpc`
  length-prefixed protobuf frames, `grpc-timeout`, and `grpc-status` /
  `grpc-message` read from the HTTP/2 trailers (or headers, for trailers-only
  errors). Interop was proven both ways against @grpc/grpc-js.
- The main app reaches the inventory only through `InventoryModule`
  (`getStock`, `reserve`, `release`, `adjust`); the architecture test
  (`php artisan test --testsuite=Architecture`) enforces that boundary.

## The contract

```proto
service Inventory {
  rpc GetStock(GetStockRequest) returns (GetStockResponse);         // quantities, batched
  rpc ReserveStock(ReserveStockRequest) returns (ReserveStockResponse); // all-or-nothing
  rpc ReleaseStock(ReleaseStockRequest) returns (ReleaseStockResponse); // idempotent
}
service InventoryAdmin {
  rpc AdjustStock(AdjustStockRequest) returns (AdjustStockResponse); // admin restocking
}
```

Principles:

- **Refusals are data, not errors.** A shortage, an already-released id or a
  below-zero restock comes back as a normal response with a status enum and
  the numbers (`product_id`, `requested`, `available`). gRPC status codes are
  reserved for real failures: `INVALID_ARGUMENT` for bad input, and the
  transport's `UNAVAILABLE` / `DEADLINE_EXCEEDED`.
- **The caller names the reservation.** `reservation_id` is the order's UUID,
  generated before the call: it is the idempotency key and later the handle
  for the release.
- **Deadlines everywhere.** The client sends `grpc-timeout`
  (`INVENTORY_TIMEOUT_MS`, default 2000 ms; connect `INVENTORY_CONNECT_TIMEOUT_MS`,
  500 ms), so no user ever waits on a hung inventory.

## Flows

### Product details (and the product list)

```mermaid
sequenceDiagram
    participant FE as Frontend
    participant BE as Laravel
    participant INV as Inventory

    FE->>BE: GET /api/v1/products/:id
    BE->>BE: load the product row
    BE->>INV: GetStock([id])  (one batched call per response)
    alt inventory answers
        INV-->>BE: levels: [{id, available}]
        BE-->>FE: 200 {..., stock: N, stock_status: "available"}
    else down or too slow
        BE-->>FE: 200 {..., stock: null, stock_status: "unavailable"}
    end
```

The catalog **never fails because of the inventory**: a page of products makes
exactly one `GetStock` with all its ids, and when that call fails every product
is still returned with `stock: null` and `stock_status: "unavailable"`.

### Create order (reserve → save → compensate)

```mermaid
sequenceDiagram
    participant FE as Frontend
    participant BE as Laravel
    participant INV as Inventory
    participant DB as Main DB

    FE->>BE: POST /api/v1/orders {items}
    BE->>BE: validate, price the lines (404 for unknown products)
    BE->>BE: orderId = new UUID (also the reservation id)
    BE->>INV: ReserveStock(orderId, items)   [ONE call, no DB transaction open]
    alt REFUSED (shortage)
        BE-->>FE: 409 INSUFFICIENT_STOCK {product_id, requested, available}
    else UNAVAILABLE
        BE-->>FE: 503 INVENTORY_UNAVAILABLE (fast: connect timeout)
    else DEADLINE_EXCEEDED
        BE->>DB: outbox += release(orderId)
        BE-->>FE: 504 INVENTORY_TIMEOUT (~2 s)
    else RESERVED
        BE->>DB: one transaction: order + items + history + notification
        alt save fails
            BE->>INV: ReleaseStock(orderId)  (compensation; outbox if it fails too)
            BE-->>FE: 500 INTERNAL_ERROR
        else saved
            BE-->>FE: 201 (order.created published after commit)
        end
    end
```

### Cancel

```mermaid
sequenceDiagram
    participant BE as Laravel
    participant DB as Main DB
    participant INV as Inventory

    BE->>DB: BEGIN; lock order row FOR UPDATE
    BE->>DB: check transition, write status/history/notification
    BE->>DB: INSERT inventory_outbox(reservation_id) ON CONFLICT DO NOTHING
    BE->>DB: COMMIT
    BE->>INV: ReleaseStock(orderId)
    alt delivered
        BE->>DB: DELETE the outbox row
    else failed
        Note over BE,DB: the row stays; the scheduler retries every minute
    end
```

Two admins cancelling at once: the row lock serialises them, the loser sees
`cancelled` and gets `409 INVALID_STATUS_TRANSITION`; only the winner wrote the
outbox row, and releasing is idempotent anyway — stock comes back exactly once.

### Restock (admin)

```mermaid
sequenceDiagram
    participant FE as Admin UI
    participant BE as Laravel
    participant INV as Inventory

    FE->>BE: PATCH /api/v1/products/:id/stock {change: 25}
    BE->>BE: validate (non-zero integer), 404 for unknown products
    BE->>INV: AdjustStock(id, change)   [InventoryAdmin]
    alt APPLIED
        INV-->>BE: available
        BE-->>FE: 200 {..., stock: available}
    else REFUSED (would go below zero)
        BE-->>FE: 409 INSUFFICIENT_STOCK {available}
    else UNAVAILABLE / DEADLINE_EXCEEDED
        BE-->>FE: 503 INVENTORY_UNAVAILABLE / 504 INVENTORY_TIMEOUT
    end
```

- The change is **relative** (`+25`, `-3`), applied by the inventory in one
  `UPDATE`, so restocking is safe while customers are reserving.
- Creating a product with `stock > 0` calls `AdjustStock` inside the product's
  DB transaction: if the inventory is down or slow, the product isn't created
  (503 / 504) and the uploaded image is removed.
- `AdjustStock` has **no idempotency key**. After a 504 the change may or may
  not have landed, so check the product's stock before retrying.

## Why it can never oversell, leak or double-release

- **Race on the last unit**: the inventory decrements with
  `UPDATE stock SET available = available - q WHERE product_id = $1 AND available >= q`
  inside one transaction. The row lock serialises concurrent reserves; the
  condition re-checks the latest value; the `CHECK (available >= 0)` constraint
  is the last line of defence. Multi-item reserves update rows in sorted
  product_id order, so opposite-order orders can't deadlock.
- **All-or-nothing**: the first shortage rolls the whole reservation back and
  is reported; stock taken for earlier lines in the same request comes back.
- **Idempotency**: the reservation row is keyed by the caller's UUID.
  Replaying a reserve that already succeeded returns `RESERVED` without taking
  stock again; releasing twice returns `ALREADY_RELEASED` and changes nothing.
- **Tombstones**: releasing an id that was never reserved records a
  `released` row (`NOT_FOUND` is returned). If the app timed out and gave up
  while the reserve was still in flight, the reserve that lands later runs
  into that tombstone and is answered `ALREADY_RELEASED` — it can never take
  stock for an order that doesn't exist.
- **The outbox**: every release that must happen but couldn't be delivered (a
  cancel with the inventory down, a compensation after a failed save, a
  timed-out reserve) is recorded in `inventory_outbox` — for a cancel, inside
  the same transaction as the status change, while the row is locked.
  `php artisan inventory:flush-outbox` (scheduled every minute) retries until
  the release lands; a release that already happened answers
  `ALREADY_RELEASED` and the row is dropped. This is **eventual consistency**:
  the moment something fails, stock may be reserved a little longer than
  necessary, but it always comes back, exactly once.

## Data model

Inventory database (`inventory-service/migrations/001_schema.sql`, applied by
the service on start):

| Table | Purpose |
|---|---|
| `stock` | `product_id` → `available`, with `CHECK (available >= 0)` |
| `reservations` | one row per order id, `status` = `reserved` or `released`; a `released` row with no items is a tombstone |
| `reservation_items` | the lines of a reservation (`product_id`, `quantity > 0`), what a release gives back |

Main database: the `products` table has **no stock column** any more (dropped
by `0001_01_02_000001_move_stock_to_inventory_service.php`). The same migration
creates `inventory_outbox` (`reservation_id` primary key, `action = 'release'`,
`created_at`, `last_attempt_at`): releases still waiting to be delivered.

## Running the proofs (demo)

Everything lives in `docs/proofs/`; plain Node, no dependencies, each prints a
clear PASS/FAIL table.

1. **Race on the last unit** — 20 customers, one unit, four API instances:

       # four dev servers (in Docker, point all bases at http://localhost:3000)
       php artisan serve --port=3001 & ... & php artisan serve --port=3004
       set ADMIN_EMAIL / ADMIN_PASSWORD, then:
       node docs/proofs/race-last-unit.js

   Expected: exactly `1 × 201`, `19 × 409 INSUFFICIENT_STOCK`, final stock 0.

2. **Inventory stopped**:

       node docs/proofs/inventory-down.js baseline     # note the stock
       <stop the inventory service>                    # docker compose stop inventory
       node docs/proofs/inventory-down.js stopped

   Expected: product details still 200 with `stock_status: "unavailable"`;
   `POST /orders` → 503 `INVENTORY_UNAVAILABLE` in well under a second; no
   order created.

3. **Inventory slow** (deadline demo):

       <run the service with SIMULATE_DELAY_MS=5000>   # or: docker compose pause inventory
       node docs/proofs/inventory-down.js slow
       <restore the service>                           # docker compose unpause inventory
       node docs/proofs/inventory-down.js recovered <baseline stock>

   Expected: 504 `INVENTORY_TIMEOUT` after ~2 s; no order created; once the
   service is back the stock is unchanged (the late reservation was released
   or blocked by its tombstone; `inventory:flush-outbox` clears the parked row).

Screenshots of the frontend in each state are in `docs/proofs/screenshots/`.

## Configuration

| Variable | Where | Default | Meaning |
|---|---|---|---|
| `INVENTORY_GRPC_ADDR` | backend | `localhost:50051` (`inventory:50051` in Docker) | address of the inventory service |
| `INVENTORY_TIMEOUT_MS` | backend | `2000` | deadline of every call (`grpc-timeout`) |
| `INVENTORY_CONNECT_TIMEOUT_MS` | backend | `500` | how long to wait for the connection; a stopped service fails this fast |
| `DATABASE_URL` | inventory | — | the inventory's own PostgreSQL database |
| `INVENTORY_LISTEN_ADDR` | inventory | `0.0.0.0:50051` | listen address of the gRPC server |
| `SIMULATE_DELAY_MS` | inventory | `0` | demo only: delays every call, to show the deadline |
| `TEST_DATABASE_URL` | inventory tests | `DATABASE_URL` with the database renamed `inventory_test` | database the test suite truncates |

The backend reads its values through `config/store.php` (`store.inventory.*`).

## Changing the contract

1. Edit `proto/inventory/v1/inventory.proto`. Keep it backwards compatible:
   add new fields with **new** numbers, never renumber or reuse a field, and
   keep `STATUS_UNSPECIFIED = 0` first in every enum.
2. Regenerate the PHP classes (they are committed):

       protoc --php_out=backend/generated --proto_path=proto proto/inventory/v1/inventory.proto

3. The Node service needs no build step: it loads the `.proto` at start-up.
   Implement the change in `inventory-service/src/handlers.js` /
   `service.js`, and in `backend/app/Modules/Inventory/InventoryModule.php`.
4. Run both test suites (below).

A change that breaks the wire format (renaming a field's number, changing its
type) goes into a new package, `inventory.v2`, next to `v1`.

## Calling it by hand (grpcurl)

Port 50051 is published by `docker-compose.yml` for demos. The server has no
reflection, so pass the `.proto`:

    grpcurl -plaintext -import-path proto -proto inventory/v1/inventory.proto \
      -d '{"product_ids": ["<product uuid>"]}' \
      localhost:50051 inventory.v1.Inventory/GetStock

    grpcurl -plaintext -import-path proto -proto inventory/v1/inventory.proto \
      -d '{"reservation_id": "<new uuid>", "items": [{"product_id": "<product uuid>", "quantity": 1}]}' \
      localhost:50051 inventory.v1.Inventory/ReserveStock

    grpcurl -plaintext -import-path proto -proto inventory/v1/inventory.proto \
      -d '{"reservation_id": "<same uuid>"}' \
      localhost:50051 inventory.v1.Inventory/ReleaseStock

An empty `GetStock` (`-d '{}'`) is a cheap health probe. Don't reserve with
the id of a real order: you'd hold its stock outside the app's bookkeeping.

## Tests

- **Inventory service** — `cd inventory-service && npm test`: integration
  tests against a real PostgreSQL and the real gRPC server on a random port
  (all-or-nothing, idempotent replay, double release, tombstones, 10 parallel
  reserves for one unit, opposite-order multi-item reserves without deadlock,
  `INVALID_ARGUMENT` for bad input). They always use a separate
  `inventory_test` database.
- **Backend** — `cd backend && php artisan test`:
  - `Feature/OrderCompensationTest.php`: a failed order save releases the
    reservation; a failed release is parked in the outbox and flushed later;
    a cancel parks and delivers its release.
  - `Architecture/ModuleBoundariesTest.php`
    (`--testsuite=Architecture`): modules only use each other's public class,
    so nothing reaches the inventory except through `InventoryModule`.
