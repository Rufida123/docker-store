<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Requests must never be served with a missing or placeholder JWT
        // secret: anyone who knows it can sign an admin token. Console runs
        // (migrations, seeding) are allowed; `php artisan app:check-env` is the
        // startup gate there.
        $secret = (string) config('store.jwt.secret');

        if (! $this->app->runningInConsole()
            && (strlen($secret) < 32 || str_starts_with($secret, 'generate_with'))) {
            throw new RuntimeException('JWT_SECRET is missing or too weak. Generate one with: openssl rand -hex 64');
        }

        // Push notifications react to order events published after commit.
        \App\Modules\Notifications\NotificationsModule::subscribePushListeners();
    }
}
