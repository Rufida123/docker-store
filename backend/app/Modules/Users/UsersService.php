<?php

namespace App\Modules\Users;

use App\Shared\AppError;
use Firebase\JWT\JWT;
use Illuminate\Database\QueryException;

/**
 * Business rules of the users module (validation, hashing, tokens).
 */
class UsersService
{
    private const EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/u';

    public function __construct(private UsersRepository $repo)
    {
    }

    public function register(array $input): array
    {
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        $details = [];
        // Type checks: JSON can also send numbers or arrays, which must be a 400, not a crash.
        if (! is_string($email) || self::length($email) > 255 || ! preg_match(self::EMAIL_RE, $email)) {
            $details[] = ['field' => 'email', 'message' => 'A valid email is required'];
        }
        if (! is_string($password) || self::length($password) < 8) {
            $details[] = ['field' => 'password', 'message' => 'Password must be at least 8 characters'];
        }
        if ($details !== []) {
            throw new AppError(400, 'VALIDATION_ERROR', 'Invalid input', $details);
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Public registration ALWAYS creates a customer. A user can never choose their own role.
            return $this->repo->create(mb_strtolower($email), $passwordHash, 'customer');
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') === '23505') {
                throw new AppError(409, 'EMAIL_TAKEN', 'Email already registered');
            }
            throw $e;
        }
    }

    public function login(array $input): array
    {
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        if (! is_string($email) || ! is_string($password) || $email === '' || $password === '') {
            throw new AppError(400, 'VALIDATION_ERROR', 'Email and password are required');
        }

        $user = $this->repo->findByEmailWithHash(mb_strtolower($email));

        // Same error for unknown email and wrong password, so attackers can't tell which.
        $valid = $user !== null && password_verify($password, $user['password_hash']);
        if (! $valid) {
            throw new AppError(401, 'INVALID_CREDENTIALS', 'Invalid credentials');
        }

        // The role goes inside the token, so every module can check it without a DB query.
        $now = time();
        $token = JWT::encode(
            [
                'sub' => $user['id'],
                'role' => $user['role'],
                'iat' => $now,
                'exp' => $now + self::ttlSeconds((string) config('store.jwt.expires_in', '15m')),
            ],
            (string) config('store.jwt.secret'),
            'HS256',
        );

        return ['token' => $token, 'token_type' => 'Bearer'];
    }

    public function getById(mixed $id): array
    {
        $user = $this->repo->findById($id);

        if ($user === null) {
            throw new AppError(404, 'USER_NOT_FOUND', 'User not found');
        }

        return $user;
    }

    public function listAll(): array
    {
        return $this->repo->list();
    }

    /**
     * @return array{status: string, email: string}
     */
    public function ensureAdmin(): array
    {
        $email = mb_strtolower((string) config('store.admin.email'));
        $password = (string) config('store.admin.password');

        if ($email === '' || $password === '') {
            return ['status' => 'skipped', 'email' => ''];
        }

        if ($this->repo->findByEmailWithHash($email) !== null) {
            return ['status' => 'exists', 'email' => $email];
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $this->repo->create($email, $passwordHash, 'admin');

        return ['status' => 'created', 'email' => $email];
    }

    /**
     * String length in UTF-16 code units, which is what the length rules were
     * originally written against.
     */
    private static function length(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Expiry spec to seconds: "15m", "1h", "7d", or a bare number of
     * milliseconds, like the ms() notation the previous stack used.
     */
    private static function ttlSeconds(string $spec): int
    {
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(ms|s|m|h|d|w|y)?$/i', trim($spec), $m)) {
            throw new \InvalidArgumentException('Invalid JWT_EXPIRES_IN value');
        }

        $value = (float) $m[1];
        $milliseconds = match (strtolower($m[2] ?? '') ?: 'ms') {
            'ms' => $value,
            's' => $value * 1000,
            'm' => $value * 60_000,
            'h' => $value * 3_600_000,
            'd' => $value * 86_400_000,
            'w' => $value * 604_800_000,
            'y' => $value * 31_557_600_000,
        };

        return (int) floor($milliseconds / 1000);
    }
}
