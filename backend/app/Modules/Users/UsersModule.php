<?php

namespace App\Modules\Users;

/**
 * Public face of the users module.
 * Other code may only use this class, never the classes next to it.
 */
class UsersModule
{
    public function __construct(private UsersService $service)
    {
    }

    /**
     * Creates the first admin from ADMIN_EMAIL / ADMIN_PASSWORD when both are
     * set and no such user exists yet.
     *
     * @return array{status: string, email: string} status is created|exists|skipped
     */
    public function ensureAdmin(): array
    {
        return $this->service->ensureAdmin();
    }
}
