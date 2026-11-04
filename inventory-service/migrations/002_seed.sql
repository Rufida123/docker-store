-- The stock of the sample products the main app seeds, same fixed ids and
-- quantities. Idempotent: an existing row is left alone.
INSERT INTO stock (product_id, available) VALUES
  ('00000000-0000-4000-8000-000000000001', 500),
  ('00000000-0000-4000-8000-000000000002', 300),
  ('00000000-0000-4000-8000-000000000003', 1000),
  ('00000000-0000-4000-8000-000000000004', 3)
ON CONFLICT (product_id) DO NOTHING;
