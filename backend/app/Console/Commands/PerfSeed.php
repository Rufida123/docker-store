<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dev-only: fills the database with a large, deterministic dataset for load
 * tests (users, products, orders with items and status history, notifications).
 *
 * Everything is generated in PostgreSQL with generate_series, so a full run
 * takes seconds to minutes. "Random" values come from md5 of (seed, row
 * number), so the same options always produce the same rows.
 *
 * Generated ids are valid UUID v4 values that carry a marker in their 4th
 * group, so they can be found and removed again (--fresh) without touching
 * the normal seed data:
 *   products 8f00, users 8f01, orders 8f02, notifications 8f03, order items 8f04
 *   e.g. product n = 12  ->  0000000c-0000-4000-8f00-000000000000
 * The k6 test and the inventory script (inventory-service/src/seed-perf.js)
 * use the same formula.
 *
 * Never called by db:seed, DatabaseSeeder or the tests. Runs only when
 * APP_ENV is local or perf.
 */
class PerfSeed extends Command
{
    protected $signature = 'perf:seed
        {--users=5000 : Customers to create}
        {--products=50000 : Products to create}
        {--orders=200000 : Orders to create}
        {--seed=42 : Seed for the generated values}
        {--anchor=2026-11-01T00:00:00Z : "Now" for the generated dates (fixed, so runs are identical)}
        {--password=PerfPass123! : Password of every perf user}
        {--chunk=20000 : Orders per transaction}
        {--fresh : Remove previously generated perf data first}';

    protected $description = 'Generate a large dataset for performance tests (dev only)';

    private const ALLOWED_ENVS = ['local', 'perf'];

    // Marker in the 4th UUID group of every generated row.
    public const KIND_PRODUCT = '8f00';

    public const KIND_USER = '8f01';

    public const KIND_ORDER = '8f02';

    public const KIND_NOTIFICATION = '8f03';

    public const KIND_ORDER_ITEM = '8f04';

    // Customers 1..HEAVY get most orders (200+ each); the rest get few.
    private const MAX_HEAVY_USERS = 300;

    private float $startedAt;

    public function handle(): int
    {
        $env = (string) app()->environment();
        if (! in_array($env, self::ALLOWED_ENVS, true)) {
            $this->error("perf:seed only runs when APP_ENV is one of: ".implode(', ', self::ALLOWED_ENVS)." (current: {$env}).");

            return self::FAILURE;
        }

        $users = $this->intOption('users', 1);
        $products = $this->intOption('products', 10);
        $orders = $this->intOption('orders', 0);
        $seed = $this->intOption('seed', 0);
        $chunk = $this->intOption('chunk', 1000);
        if ($users === null || $products === null || $orders === null || $seed === null || $chunk === null) {
            return self::FAILURE;
        }

        $anchor = (string) $this->option('anchor');
        if (strtotime($anchor) === false) {
            $this->error('--anchor must be a date, e.g. 2026-11-01T00:00:00Z');

            return self::FAILURE;
        }

        $this->startedAt = microtime(true);
        $this->line("perf:seed (APP_ENV={$env}, database ".DB::connection()->getDatabaseName().')');

        $this->createHelpers();

        $existing = DB::selectOne("SELECT COUNT(*)::int AS n FROM users WHERE pg_temp.perf_kind(id) = '".self::KIND_USER."'")->n;
        if ($existing > 0) {
            if (! $this->option('fresh')) {
                $this->error("Perf data is already present ({$existing} perf users). Run again with --fresh to replace it.");

                return self::FAILURE;
            }
            $this->removePerfData();
        }

        $heavy = min(self::MAX_HEAVY_USERS, max(1, intdiv($users * 6, 100)));
        $light = $users - $heavy;
        $heavyOrders = $light > 0 ? min(intdiv($orders * 45, 100), $heavy * 300) : $orders;

        $hash = password_hash((string) $this->option('password'), PASSWORD_BCRYPT, ['cost' => 12]); // once, reused
        $this->seedUsers($users, $seed, $anchor, $hash);
        $this->seedProducts($products, $seed, $anchor);
        $this->seedOrders($orders, $products, $heavy, $light, $heavyOrders, $seed, $anchor, $chunk);

        $this->step('vacuum analyze (fresh statistics after the bulk load)');
        foreach (['users', 'products', 'orders', 'order_items', 'order_status_history', 'notifications'] as $table) {
            DB::statement("VACUUM ANALYZE {$table}");
        }

        $this->summary($heavy);

        return self::SUCCESS;
    }

    private function intOption(string $name, int $min): ?int
    {
        $value = $this->option($name);
        if (! is_numeric($value) || (int) $value != $value || (int) $value < $min) {
            $this->error("--{$name} must be an integer >= {$min}");

            return null;
        }

        return (int) $value;
    }

    private function step(string $message): void
    {
        $this->line(sprintf('[%6.1fs] %s', microtime(true) - $this->startedAt, $message));
    }

    /**
     * Session-local SQL helpers (pg_temp disappears with the connection).
     */
    private function createHelpers(): void
    {
        // Deterministic number in [0, 1) from a text key.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pg_temp.prand(k text) RETURNS double precision
            LANGUAGE sql IMMUTABLE AS $$
              SELECT ('x' || substr(md5(k), 1, 8))::bit(32)::bigint / 4294967296.0
            $$;

            -- Valid v4 UUID: n in the 1st group, version 4, variant 8, kind marker.
            CREATE OR REPLACE FUNCTION pg_temp.perf_uuid(kind text, n bigint) RETURNS uuid
            LANGUAGE sql IMMUTABLE AS $$
              SELECT (lpad(to_hex(n), 8, '0') || '-0000-4000-' || kind || '-000000000000')::uuid
            $$;

            CREATE OR REPLACE FUNCTION pg_temp.perf_kind(id uuid) RETURNS text
            LANGUAGE sql IMMUTABLE AS $$
              SELECT substr(id::text, 20, 4)
            $$;
        SQL);
    }

    private function removePerfData(): void
    {
        $user = self::KIND_USER;
        $product = self::KIND_PRODUCT;

        // Orders have no FK to users: remove every order of a perf user,
        // including the ones the load test placed. Items, history and
        // notifications go with them (ON DELETE CASCADE).
        $this->step('--fresh: removing perf orders');
        $n = DB::delete("DELETE FROM orders WHERE pg_temp.perf_kind(user_id) = '{$user}'");
        $this->step("  {$n} orders removed");

        $this->step('--fresh: removing perf users and products');
        DB::delete("DELETE FROM users WHERE pg_temp.perf_kind(id) = '{$user}'");
        DB::delete("DELETE FROM products WHERE pg_temp.perf_kind(id) = '{$product}'");

        foreach (['users', 'products', 'orders', 'order_items', 'order_status_history', 'notifications'] as $table) {
            DB::statement("VACUUM {$table}");
        }
    }

    private function seedUsers(int $users, int $seed, string $anchor, string $hash): void
    {
        $this->step("users: 1 perf admin + {$users} customers");
        $kind = self::KIND_USER;

        DB::transaction(function () use ($users, $seed, $anchor, $hash, $kind): void {
            DB::insert(
                "INSERT INTO users (id, email, password_hash, role, created_at)
                 VALUES (pg_temp.perf_uuid('{$kind}', 0), 'perf-admin@example.test', ?, 'admin', ?::timestamptz - interval '730 days')",
                [$hash, $anchor],
            );
            DB::insert(
                "INSERT INTO users (id, email, password_hash, role, created_at)
                 SELECT pg_temp.perf_uuid('{$kind}', n),
                        'perf-user-' || n || '@example.test',
                        ?, 'customer',
                        ?::timestamptz - interval '365 days' - pg_temp.prand('{$seed}:u:' || n) * interval '365 days'
                   FROM generate_series(1, {$users}) AS n",
                [$hash, $anchor],
            );
        });
    }

    private function seedProducts(int $products, int $seed, string $anchor): void
    {
        $this->step("products: {$products}");
        $kind = self::KIND_PRODUCT;

        // One noun list per category (same length: PostgreSQL 2-D arrays are rectangular).
        DB::insert(
            <<<SQL
            WITH words AS (
              SELECT
                ARRAY['electronics','clothing','accessories','home','books','sports','toys','beauty','garden','grocery'] AS cats,
                ARRAY[
                  ['Headphones','Charger','Speaker','Webcam','Monitor','Router'],
                  ['T-Shirt','Hoodie','Jacket','Jeans','Scarf','Socks'],
                  ['Watch','Wallet','Backpack','Sunglasses','Belt','Keychain'],
                  ['Lamp','Pillow','Blanket','Mug','Vase','Clock'],
                  ['Notebook','Novel','Cookbook','Atlas','Journal','Planner'],
                  ['Yoga Mat','Dumbbell','Water Bottle','Jump Rope','Helmet','Ball'],
                  ['Puzzle','Robot','Doll','Kite','Blocks','Board Game'],
                  ['Lotion','Shampoo','Perfume','Brush','Soap','Face Mask'],
                  ['Planter','Hose','Shovel','Gloves','Seeds','Bird Feeder'],
                  ['Coffee','Tea','Honey','Olive Oil','Granola','Chocolate']
                ] AS nouns,
                ARRAY['Classic','Compact','Deluxe','Ergonomic','Eco','Smart','Vintage','Premium','Portable','Handmade','Modern','Rustic'] AS adjs,
                ARRAY['Steel','Cotton','Bamboo','Leather','Ceramic','Wooden','Aluminum','Wool','Glass','Recycled'] AS mats
            )
            INSERT INTO products (id, name, description, category, price, image_url, created_at, updated_at)
            SELECT pg_temp.perf_uuid('{$kind}', g.n),
                   w.adjs[r.a] || ' ' || w.mats[r.m] || ' ' || w.nouns[r.c][r.k] || ' #' || g.n,
                   CASE WHEN r.d < 0.1 THEN NULL
                        ELSE repeat('A ' || lower(w.adjs[r.a]) || ' ' || lower(w.nouns[r.c][r.k]) || ' made of '
                                    || lower(w.mats[r.m]) || ', built for everyday use. ', 1 + floor(r.d * 4)::int)
                   END,
                   w.cats[r.c],
                   round((1 + 999 * power(r.p, 3))::numeric, 2),
                   NULL,
                   r.ts, r.ts
              FROM generate_series(1, {$products}) AS g(n)
             CROSS JOIN words w
             CROSS JOIN LATERAL (
               SELECT pg_temp.prand('{$seed}:pc:' || g.n) AS c,
                      pg_temp.prand('{$seed}:pk:' || g.n) AS k,
                      pg_temp.prand('{$seed}:pa:' || g.n) AS a,
                      pg_temp.prand('{$seed}:pm:' || g.n) AS m,
                      pg_temp.prand('{$seed}:pd:' || g.n) AS d,
                      pg_temp.prand('{$seed}:pp:' || g.n) AS p,
                      pg_temp.prand('{$seed}:pt:' || g.n) AS t
             ) r0
             CROSS JOIN LATERAL (
               SELECT 1 + floor(r0.c * 10)::int AS c,
                      1 + floor(r0.k * 6)::int AS k,
                      1 + floor(r0.a * 12)::int AS a,
                      1 + floor(r0.m * 10)::int AS m,
                      r0.d AS d, r0.p AS p,
                      ?::timestamptz - r0.t * interval '730 days' AS ts
             ) r
            SQL,
            [$anchor],
        );
    }

    private function seedOrders(int $orders, int $products, int $heavy, int $light, int $heavyOrders, int $seed, string $anchor, int $chunk): void
    {
        if ($orders === 0) {
            return;
        }

        $this->step("orders: {$orders} ({$heavyOrders} spread over {$heavy} heavy users, the rest over {$light} light users)");

        $user = self::KIND_USER;
        $order = self::KIND_ORDER;
        $product = self::KIND_PRODUCT;
        $item = self::KIND_ORDER_ITEM;
        $notification = self::KIND_NOTIFICATION;

        // The plan of every order: owner, status path and the hour offset of each step.
        // Status mix: delivered 60%, shipped 10%, confirmed 10%, pending 10%, cancelled 10%
        // (half cancelled while pending, half after confirmation).
        // Light orders: half spread over all light users, half over the first
        // 20% of them, so most light users have a handful and some have more.
        $lightOwner = $light > 0
            ? "{$heavy} + 1 + floor(CASE WHEN r.m < 0.5 THEN {$light} ELSE greatest(1, {$light} / 5) END * r.u)::int"
            : "1 + floor({$heavy} * r.u)::int";
        DB::statement('DROP TABLE IF EXISTS perf_orders');
        DB::statement(
            <<<SQL
            CREATE TEMP TABLE perf_orders AS
            SELECT g.n,
                   pg_temp.perf_uuid('{$order}', g.n) AS id,
                   pg_temp.perf_uuid('{$user}', CASE WHEN g.n <= {$heavyOrders}
                                                     THEN 1 + floor({$heavy} * r.u)::int
                                                     ELSE {$lightOwner} END) AS user_id,
                   ?::timestamptz - r.t * interval '365 days' AS created_at,
                   1 + floor(r.k * 5)::int AS item_count,
                   floor({$products} * power(r.b, 2))::int AS first_product,
                   CASE WHEN r.s < 0.60 THEN ARRAY['pending','confirmed','shipped','delivered']
                        WHEN r.s < 0.70 THEN ARRAY['pending','confirmed','shipped']
                        WHEN r.s < 0.80 THEN ARRAY['pending','confirmed']
                        WHEN r.s < 0.90 THEN ARRAY['pending']
                        WHEN r.s < 0.95 THEN ARRAY['pending','cancelled']
                        ELSE ARRAY['pending','confirmed','cancelled'] END AS path,
                   CASE WHEN r.s < 0.60 THEN ARRAY[0, h.c, h.c + h.sh, h.c + h.sh + h.d]
                        WHEN r.s < 0.70 THEN ARRAY[0, h.c, h.c + h.sh]
                        WHEN r.s < 0.80 THEN ARRAY[0, h.c]
                        WHEN r.s < 0.90 THEN ARRAY[0]
                        WHEN r.s < 0.95 THEN ARRAY[0, h.x]
                        ELSE ARRAY[0, h.c, h.c + h.x] END AS offsets
              FROM generate_series(1, {$orders}) AS g(n)
             CROSS JOIN LATERAL (
               SELECT pg_temp.prand('{$seed}:ou:' || g.n) AS u,
                      pg_temp.prand('{$seed}:om:' || g.n) AS m,
                      pg_temp.prand('{$seed}:ot:' || g.n) AS t,
                      pg_temp.prand('{$seed}:ok:' || g.n) AS k,
                      pg_temp.prand('{$seed}:ob:' || g.n) AS b,
                      pg_temp.prand('{$seed}:os:' || g.n) AS s,
                      pg_temp.prand('{$seed}:oh:' || g.n) AS hr
             ) r
             CROSS JOIN LATERAL (
               SELECT 1 + floor(r.hr * 23)::int AS c,          -- confirmed after 1-24 h
                      12 + floor(r.hr * 60)::int AS sh,        -- shipped 12-72 h later
                      24 + floor(r.hr * 96)::int AS d,         -- delivered 1-5 days later
                      1 + floor((1 - r.hr) * 47)::int AS x     -- cancelled 1-48 h later
             ) h
            SQL,
            [$anchor],
        );
        DB::statement('CREATE INDEX ON perf_orders (n)');

        // One row per status step (history + notification), never later than the anchor.
        DB::statement('DROP TABLE IF EXISTS perf_steps');
        DB::statement(
            <<<SQL
            CREATE TEMP TABLE perf_steps AS
            SELECT o.n, o.id AS order_id, o.user_id, s.seq,
                   o.path[s.seq - 1] AS from_status,
                   o.path[s.seq] AS to_status,
                   LEAST(?::timestamptz, o.created_at + o.offsets[s.seq] * interval '1 hour') AS at,
                   CASE WHEN s.seq = 1 THEN o.user_id ELSE pg_temp.perf_uuid('{$user}', 0) END AS changed_by
              FROM perf_orders o
             CROSS JOIN LATERAL generate_subscripts(o.path, 1) AS s(seq)
            SQL,
            [$anchor],
        );
        DB::statement('CREATE INDEX ON perf_steps (n)');

        for ($from = 1; $from <= $orders; $from += $chunk) {
            $to = min($orders, $from + $chunk - 1);

            DB::transaction(function () use ($from, $to, $products, $seed, $anchor, $order, $product, $item, $notification): void {
                DB::insert(
                    "INSERT INTO orders (id, user_id, status, total, created_at, updated_at)
                     SELECT o.id, o.user_id, o.path[array_length(o.path, 1)], 0, o.created_at,
                            (SELECT max(s.at) FROM perf_steps s WHERE s.n = o.n)
                       FROM perf_orders o
                      WHERE o.n BETWEEN {$from} AND {$to}",
                );

                // 1-5 different products per order (stride 7919 keeps them apart),
                // popular products more often. Name and price copied from the product.
                DB::insert(
                    "INSERT INTO order_items (id, order_id, product_id, product_name, unit_price, quantity)
                     SELECT pg_temp.perf_uuid('{$item}', o.n * 8 + j), o.id, p.id, p.name, p.price,
                            1 + floor(pg_temp.prand('{$seed}:iq:' || o.n || ':' || j) * 3)::int
                       FROM perf_orders o
                      CROSS JOIN LATERAL generate_series(0, o.item_count - 1) AS j
                       JOIN products p
                         ON p.id = pg_temp.perf_uuid('{$product}', 1 + (o.first_product + j * 7919) % {$products})
                      WHERE o.n BETWEEN {$from} AND {$to}
                     ON CONFLICT (order_id, product_id) DO NOTHING",
                );

                DB::update(
                    "UPDATE orders o
                        SET total = t.total
                       FROM (SELECT order_id, SUM(unit_price * quantity) AS total
                               FROM order_items
                              WHERE order_id BETWEEN pg_temp.perf_uuid('{$order}', {$from}) AND pg_temp.perf_uuid('{$order}', {$to})
                              GROUP BY order_id) t
                      WHERE o.id = t.order_id",
                );

                DB::insert(
                    "INSERT INTO order_status_history (order_id, from_status, to_status, changed_by, changed_at)
                     SELECT order_id, from_status, to_status, changed_by, at
                       FROM perf_steps
                      WHERE n BETWEEN {$from} AND {$to}
                      ORDER BY n, seq",
                );

                // Same texts as NotificationsService. Older notifications are mostly read.
                DB::insert(
                    "INSERT INTO notifications (id, user_id, order_id, type, status, title, message, metadata, is_read, read_at, created_at)
                     SELECT pg_temp.perf_uuid('{$notification}', s.n * 8 + s.seq), s.user_id, s.order_id,
                            CASE WHEN s.seq = 1 THEN 'order_created' ELSE 'order_status_changed' END,
                            s.to_status,
                            CASE s.to_status
                              WHEN 'pending' THEN 'Order placed'
                              WHEN 'confirmed' THEN 'Order confirmed'
                              WHEN 'shipped' THEN 'Order shipped'
                              WHEN 'delivered' THEN 'Order delivered'
                              ELSE 'Order cancelled' END,
                            format(CASE s.to_status
                              WHEN 'pending' THEN 'Order %s was placed and is waiting for confirmation.'
                              WHEN 'confirmed' THEN 'Order %s was confirmed.'
                              WHEN 'shipped' THEN 'Order %s is on its way.'
                              WHEN 'delivered' THEN 'Order %s was delivered.'
                              ELSE 'Order %s was cancelled.' END, '#' || substr(s.order_id::text, 1, 8)),
                            jsonb_build_object('order_id', s.order_id, 'status', s.to_status),
                            rd.is_read,
                            CASE WHEN rd.is_read THEN LEAST(?::timestamptz, s.at + interval '2 hours') END,
                            s.at
                       FROM perf_steps s
                      CROSS JOIN LATERAL (
                        SELECT pg_temp.prand('{$seed}:nr:' || s.n || ':' || s.seq)
                               < CASE WHEN s.at < ?::timestamptz - interval '30 days' THEN 0.9 ELSE 0.4 END AS is_read
                      ) rd
                      WHERE s.n BETWEEN {$from} AND {$to}",
                    [$anchor, $anchor],
                );
            });

            $this->step(sprintf('  orders %d-%d of %d', $from, $to, $orders));
        }

        DB::statement('DROP TABLE perf_steps');
        DB::statement('DROP TABLE perf_orders');
    }

    private function summary(int $heavy): void
    {
        $user = self::KIND_USER;
        $counts = DB::selectOne(
            "SELECT
               (SELECT COUNT(*) FROM users WHERE pg_temp.perf_kind(id) = '{$user}')::int AS users,
               (SELECT COUNT(*) FROM products WHERE pg_temp.perf_kind(id) = '".self::KIND_PRODUCT."')::int AS products,
               (SELECT COUNT(*) FROM orders WHERE pg_temp.perf_kind(user_id) = '{$user}')::int AS orders,
               (SELECT COUNT(*) FROM order_items i JOIN orders o ON o.id = i.order_id WHERE pg_temp.perf_kind(o.user_id) = '{$user}')::int AS items,
               (SELECT COUNT(*) FROM order_status_history h JOIN orders o ON o.id = h.order_id WHERE pg_temp.perf_kind(o.user_id) = '{$user}')::int AS history,
               (SELECT COUNT(*) FROM notifications WHERE pg_temp.perf_kind(user_id) = '{$user}')::int AS notifications"
        );
        $perUser = DB::selectOne(
            "SELECT min(c)::int AS min, max(c)::int AS max, percentile_cont(0.5) WITHIN GROUP (ORDER BY c)::int AS median
               FROM (SELECT COUNT(*) AS c FROM orders WHERE pg_temp.perf_kind(user_id) = '{$user}' GROUP BY user_id) t"
        );

        $this->newLine();
        $this->table(['users (incl. perf admin)', 'products', 'orders', 'order_items', 'status history', 'notifications'], [[
            $counts->users, $counts->products, $counts->orders, $counts->items, $counts->history, $counts->notifications,
        ]]);
        $this->line(sprintf(
            'Orders per customer: min %s, median %s, max %s. Heavy users: perf-user-1 .. perf-user-%d.',
            $perUser->min ?? 0, $perUser->median ?? 0, $perUser->max ?? 0, $heavy,
        ));
        $this->step('done');
    }
}
