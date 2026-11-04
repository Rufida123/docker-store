<?php

namespace Database\Seeders;

use App\Modules\Users\UsersModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(UsersModule $users): void
    {
        // Sample products with fixed ids so the API collection can order them.
        // Their stock lives in the inventory service, which seeds the same ids.
        DB::statement(<<<'SQL'
            INSERT INTO products (id, name, category, price) VALUES
              ('00000000-0000-4000-8000-000000000001', 'Wireless Mouse',        'electronics', 19.99),
              ('00000000-0000-4000-8000-000000000002', 'Mechanical Keyboard',   'electronics', 89.50),
              ('00000000-0000-4000-8000-000000000003', 'Cotton T-Shirt',        'clothing',     12.00),
              ('00000000-0000-4000-8000-000000000004', 'Limited Edition Watch', 'accessories', 249.00)
            ON CONFLICT (id) DO NOTHING
        SQL);

        $result = $users->ensureAdmin();

        match ($result['status']) {
            'created' => $this->command->line('Admin user created: '.$result['email']),
            'skipped' => $this->command->line('ADMIN_EMAIL / ADMIN_PASSWORD not set: skipping admin creation'),
            default => null,
        };
    }
}
