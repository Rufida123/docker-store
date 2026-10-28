-- Safe to run once or repeatedly against an existing docker-store database.
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
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_created
  ON notifications (user_id, created_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread
  ON notifications (user_id, created_at DESC, id DESC) WHERE is_read = FALSE;
