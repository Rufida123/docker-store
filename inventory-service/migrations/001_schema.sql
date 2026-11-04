-- The stock a product has right now. The CHECK is the last line of defence:
-- the quantity can never go below zero, whatever the code does.
CREATE TABLE IF NOT EXISTS stock (
  product_id  UUID PRIMARY KEY,
  available   INTEGER NOT NULL CHECK (available >= 0),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- One reservation per order. The row doubles as the idempotency record:
-- a repeated reserve finds it, and a release flips it to 'released'.
-- A 'released' row with no items is a tombstone: a release that arrived
-- before (or instead of) its reserve, blocking that id forever.
CREATE TABLE IF NOT EXISTS reservations (
  id          UUID PRIMARY KEY,
  status      TEXT NOT NULL CHECK (status IN ('reserved', 'released')),
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  released_at TIMESTAMPTZ
);

CREATE TABLE IF NOT EXISTS reservation_items (
  reservation_id UUID NOT NULL REFERENCES reservations (id) ON DELETE CASCADE,
  product_id     UUID NOT NULL,
  quantity       INTEGER NOT NULL CHECK (quantity > 0),
  PRIMARY KEY (reservation_id, product_id)
);
