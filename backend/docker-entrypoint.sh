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

php artisan migrate --force
php artisan db:seed --force

# Refuses to serve with a missing, short or placeholder JWT secret, and
# announces the push notification status.
php artisan app:check-env

echo "API listening on port 3000"
exec frankenphp run --config /app/Caddyfile
