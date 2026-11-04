<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// No foreign keys to users or products: each module owns its own tables and
// reaches another module's data only through that module's public class. This
// keeps the modules independent, so one of them can later move to its own
// service and database.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS orders (
              id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id     UUID NOT NULL,
              status      VARCHAR(20) NOT NULL DEFAULT 'pending'
                          CHECK (status IN ('pending', 'confirmed', 'shipped', 'delivered', 'cancelled')),
              total       NUMERIC(14, 2) NOT NULL CHECK (total >= 0),
              created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        // "My orders, newest first" and the admin status filter.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders (user_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders (status, created_at DESC)');

        // Name and price are copied at order time, so later product edits never change past orders.
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS order_items (
              id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              order_id      UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
              product_id    UUID NOT NULL,
              product_name  VARCHAR(200) NOT NULL,
              unit_price    NUMERIC(10, 2) NOT NULL CHECK (unit_price >= 0),
              quantity      INTEGER NOT NULL CHECK (quantity > 0),
              UNIQUE (order_id, product_id)
            )
        SQL);

        // Every status change, who made it and when (order tracking + audit trail).
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS order_status_history (
              id           BIGSERIAL PRIMARY KEY,
              order_id     UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
              from_status  VARCHAR(20),
              to_status    VARCHAR(20) NOT NULL,
              changed_by   UUID NOT NULL,
              changed_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS idx_order_status_history_order ON order_status_history (order_id, changed_at)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS order_status_history');
        DB::statement('DROP TABLE IF EXISTS order_items');
        DB::statement('DROP TABLE IF EXISTS orders');
    }
};
