-- ===================== users module =====================
CREATE TABLE IF NOT EXISTS users (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  email         VARCHAR(255) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          VARCHAR(20) NOT NULL DEFAULT 'customer'
                CHECK (role IN ('customer', 'admin')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ===================== products module =====================
-- Owned by the products module. The orders module only uses id, name, price and stock,
-- and only through products/index.js.
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
);

CREATE INDEX IF NOT EXISTS idx_products_category ON products (category);

-- ===================== orders module =====================
-- No foreign keys to users or products: each module owns its own tables and reaches
-- another module's data only through that module's index.js. This keeps the modules
-- independent, so one of them can later move to its own service and database.
CREATE TABLE IF NOT EXISTS orders (
  id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id     UUID NOT NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'pending'
              CHECK (status IN ('pending', 'confirmed', 'shipped', 'delivered', 'cancelled')),
  total       NUMERIC(14, 2) NOT NULL CHECK (total >= 0),
  created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
  updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- "My orders, newest first" and the admin status filter.
CREATE INDEX IF NOT EXISTS idx_orders_user_created ON orders (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders (status, created_at DESC);

-- Name and price are copied at order time, so later product edits never change past orders.
CREATE TABLE IF NOT EXISTS order_items (
  id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  order_id      UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
  product_id    UUID NOT NULL,
  product_name  VARCHAR(200) NOT NULL,
  unit_price    NUMERIC(10, 2) NOT NULL CHECK (unit_price >= 0),
  quantity      INTEGER NOT NULL CHECK (quantity > 0),
  UNIQUE (order_id, product_id)
);

-- Every status change, who made it and when (order tracking + audit trail).
CREATE TABLE IF NOT EXISTS order_status_history (
  id           BIGSERIAL PRIMARY KEY,
  order_id     UUID NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
  from_status  VARCHAR(20),
  to_status    VARCHAR(20) NOT NULL,
  changed_by   UUID NOT NULL,
  changed_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_order_status_history_order ON order_status_history (order_id, changed_at);

-- ===================== sample data =====================
-- Fixed ids so the Postman collection can order them. Safe to remove once the
-- products API can create products.
INSERT INTO products (id, name, category, price, stock) VALUES
  ('00000000-0000-4000-8000-000000000001', 'Wireless Mouse',        'electronics', 19.99, 500),
  ('00000000-0000-4000-8000-000000000002', 'Mechanical Keyboard',   'electronics', 89.50, 300),
  ('00000000-0000-4000-8000-000000000003', 'Cotton T-Shirt',        'clothing',     12.00, 1000),
  ('00000000-0000-4000-8000-000000000004', 'Limited Edition Watch', 'accessories', 249.00, 3)
ON CONFLICT (id) DO NOTHING;
