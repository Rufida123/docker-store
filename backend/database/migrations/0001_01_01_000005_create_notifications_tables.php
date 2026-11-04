<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Notifications belong to a customer and are created in the same transaction as
// the order event that caused them. order_id/status also form a natural
// idempotency key for a particular order event.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notifications (
              id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id     UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              order_id    UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
              type        VARCHAR(40) NOT NULL
                          CHECK (type IN ('order_created', 'order_status_changed')),
              status      VARCHAR(20) NOT NULL
                          CHECK (status IN ('pending', 'confirmed', 'shipped', 'delivered', 'cancelled')),
              title       VARCHAR(200) NOT NULL,
              message     TEXT NOT NULL,
              metadata    JSONB NOT NULL DEFAULT '{}'::jsonb,
              is_read     BOOLEAN NOT NULL DEFAULT FALSE,
              read_at     TIMESTAMPTZ,
              created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              CHECK ((is_read AND read_at IS NOT NULL) OR (NOT is_read AND read_at IS NULL)),
              UNIQUE (order_id, type, status)
            )
        SQL);

        // The list query is scoped to one user and sorted newest first.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notifications_user_created
              ON notifications (user_id, created_at DESC, id DESC)
        SQL);
        // Lets unread counts and unread-only future views avoid scanning read rows.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
              ON notifications (user_id, created_at DESC, id DESC) WHERE is_read = FALSE
        SQL);

        // Browsers that receive push notifications (Firebase Cloud Messaging) for a
        // user. The FCM token identifies one browser. If another user signs in on
        // that browser, the token moves to the new user, so pushes never reach the
        // previous account's owner.
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS notification_devices (
              token         TEXT PRIMARY KEY,
              user_id       UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
              user_agent    VARCHAR(300),
              created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
              last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS idx_notification_devices_user ON notification_devices (user_id)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS notification_devices');
        DB::statement('DROP TABLE IF EXISTS notifications');
    }
};
