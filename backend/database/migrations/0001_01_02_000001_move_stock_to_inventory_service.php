<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Stock now lives in the inventory service's own database and the app asks
// for it over gRPC. The products table keeps everything else.
//
// inventory_outbox holds ReleaseStock deliveries that could not reach the
// inventory service yet (a cancelled order, or a compensation after a failed
// save or a timed-out reserve). `php artisan inventory:flush-outbox` retries
// them; releases are idempotent on the inventory side, so retrying is safe.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS stock');

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS inventory_outbox (
              reservation_id  UUID PRIMARY KEY,
              action          TEXT NOT NULL CHECK (action IN ('release')),
              created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              last_attempt_at TIMESTAMPTZ
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS inventory_outbox');
        DB::statement('ALTER TABLE products ADD COLUMN IF NOT EXISTS stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0)');
    }
};
