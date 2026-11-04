<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Startup guard. Anyone who knows the JWT secret can sign a token with role
 * "admin", so refuse to start with a missing, short, or placeholder secret
 * (the one in .env.example is public).
 */
class CheckEnv extends Command
{
    protected $signature = 'app:check-env';

    protected $description = 'Verify required settings before serving the API';

    public function handle(): int
    {
        $secret = (string) config('store.jwt.secret');

        if (strlen($secret) < 32 || str_starts_with($secret, 'generate_with')) {
            fwrite(STDERR, "JWT_SECRET is missing or too weak. Generate one with: openssl rand -hex 64\n");

            return 1;
        }

        // Announces whether Firebase push is on or off for this deployment.
        $this->line(app(\App\Modules\Notifications\NotificationsModule::class)->pushStatusLine());

        return 0;
    }
}
