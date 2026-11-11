<?php

namespace App\Modules\Users;

use App\Shared\Database\Sql;
use App\Shared\Time;
use Illuminate\Support\Facades\DB;

/**
 * The only class in the users module that talks to the database.
 */
class UsersRepository
{
    public function create(string $email, string $passwordHash, string $role): array
    {
        $rows = Sql::returning(
            'INSERT INTO users (email, password_hash, role)
             VALUES (?, ?, ?)
             RETURNING id, email, role, created_at',
            [$email, $passwordHash, $role],
        );

        return self::row($rows[0]);
    }

    public function findByEmailWithHash(string $email): ?array
    {
        $rows = DB::select(
            'SELECT id, email, role, password_hash FROM users WHERE email = ?',
            [$email],
        );

        return $rows === [] ? null : (array) $rows[0];
    }

    public function findById(mixed $id): ?array
    {
        $rows = DB::select(
            'SELECT id, email, role, created_at FROM users WHERE id = ?',
            [$id],
        );

        return $rows === [] ? null : self::row($rows[0]);
    }

    public function list(): array
    {
        $rows = DB::select(
            'SELECT id, email, role, created_at FROM users ORDER BY created_at DESC',
        );

        return array_map(self::row(...), $rows);
    }

    private static function row(object $row): array
    {
        $user = (array) $row;
        $user['created_at'] = Time::iso($user['created_at']);

        return $user;
    }
}
