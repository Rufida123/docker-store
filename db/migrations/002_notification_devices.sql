-- Safe to run once or repeatedly against an existing docker-store database.
-- Adds the table of browsers that receive push notifications (Firebase Cloud Messaging).
CREATE TABLE IF NOT EXISTS notification_devices (
  token         TEXT PRIMARY KEY,
  user_id       UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
  user_agent    VARCHAR(300),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_notification_devices_user ON notification_devices (user_id);
