# Backend (Laravel)

The API of the Docker Store project. See the [project README](../README.md) for
the full documentation: endpoints, Docker setup, and how to run it locally.

Quick start (local, without Docker):

    composer install
    copy .env.example .env    # fill in DB_*, JWT_SECRET, ADMIN_*
    php artisan migrate --force
    php artisan db:seed --force
    php artisan app:check-env
    php artisan serve --port=3000

Tests:

    php artisan test --testsuite=Architecture
