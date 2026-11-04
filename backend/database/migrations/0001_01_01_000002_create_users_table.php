<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS users (
              id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              email         VARCHAR(255) UNIQUE NOT NULL,
              password_hash VARCHAR(255) NOT NULL,
              role          VARCHAR(20) NOT NULL DEFAULT 'customer'
                            CHECK (role IN ('customer', 'admin')),
              created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS users');
    }
};
