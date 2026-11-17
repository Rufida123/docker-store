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

    /**
     * The email address of a user, or null when there is no such user.
     * Read from the primary (used by the mail worker).
     */
    public function emailOf(string $userId): ?string
    {
        return $this->service->emailOf($userId);
    }
}