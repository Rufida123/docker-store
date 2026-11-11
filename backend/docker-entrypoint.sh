#!/bin/sh
set -e

# Laravel only needs APP_KEY for encryption features; give this run a key if
# none was configured, without storing or printing it.
if [ -z "$APP_KEY" ]; then
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

echo "Waiting for the database..."
until php -r '
    $host = getenv("DB_HOST") ?: "db";
    $port = getenv("DB_PORT") ?: "5432";
    $name = getenv("DB_DATABASE") ?: "";
    new PDO("pgsql:host={$host};port={$port};dbname={$name}", getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
' >/dev/null 2>&1; do
    echo "Database is not ready yet, retrying in 2s..."
    sleep 2
done

# Migrations and seeders run on the primary only: with DB_READ_HOST empty
# Laravel has a single connection, so the migrations table and the seeder's
# checks are never read from a replica that may lag (docs/performance.md, 6.6).
DB_READ_HOST= php artisan migrate --force
DB_READ_HOST= php artisan db:seed --force

# Refuses to serve with a missing, short or placeholder JWT secret, and
# announces the push notification status.
php artisan app:check-env

echo "API listening on port 3000"
exec frankenphp run --config /app/Caddyfile
