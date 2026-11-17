<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Every list is sorted by (created_at DESC, id DESC), but the old indexes had
// only created_at, so PostgreSQL added an Incremental Sort for the id
// tie-breaker and sorted every row it skipped with OFFSET. With id in the
// index the order comes straight from it (docs/performance.md).
//
// Plain CREATE INDEX (it takes seconds on the perf dataset). On a large live
// table, build it with CREATE INDEX CONCURRENTLY outside a transaction instead.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_created_id ON products (created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_category_created_id ON products (category, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_user_created_id ON orders (user_id, created_at DESC, id DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_status_created_id ON orders (status, created_at DESC, id DESC)');

        // The new indexes cover every query the old ones served.
        DB::statement('DROP INDEX IF EXISTS idx_products_created');
        DB::statement('DROP INDEX IF EXISTS idx_products_category_created');
        DB::statement('DROP INDEX IF EXISTS idx_orders_user_created');
        DB::statement('DROP INDEX IF EXISTS idx_orders_status_created');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_created ON products (created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_category_created ON products (category, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders (user_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders (status, created_at DESC)');

        DB::statement('DROP INDEX IF EXISTS idx_products_created_id');
        DB::statement('DROP INDEX IF EXISTS idx_products_category_created_id');
        DB::statement('DROP INDEX IF EXISTS idx_orders_user_created_id');
        DB::statement('DROP INDEX IF EXISTS idx_orders_status_created_id');
    }
};
