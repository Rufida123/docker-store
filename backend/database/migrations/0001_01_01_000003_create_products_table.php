<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS products (
              id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              name        VARCHAR(200) NOT NULL,
              description TEXT,
              category    VARCHAR(100),
              price       NUMERIC(10, 2) NOT NULL CHECK (price >= 0),
              -- Last line of defence against overselling: the database itself refuses negative stock.
              stock       INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
              image_url   VARCHAR(500),
              created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        // Product listing: newest first, optionally filtered by category.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_created ON products (created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_products_category_created ON products (category, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS products');
    }
};
