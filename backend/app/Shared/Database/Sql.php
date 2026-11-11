<?php

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;

/**
 * Writes that return rows (INSERT/UPDATE ... RETURNING).
 *
 * Laravel treats every select() as a read, so with a read replica configured
 * an "UPDATE ... RETURNING" run through select() would go to the replica, which
 * refuses it ("read-only transaction"), and it would not mark the connection
 * as written, so "sticky" would not keep this request's next reads on the
 * primary. This runs it on the primary and marks the write.
 */
final class Sql
{
    /** @return array<int, object> */
    public static function returning(string $query, array $bindings = []): array
    {
        $connection = DB::connection();
        $rows = $connection->selectFromWriteConnection($query, $bindings);
        $connection->recordsHaveBeenModified();

        return $rows;
    }
}
