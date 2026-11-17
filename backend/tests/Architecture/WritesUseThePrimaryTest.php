<?php

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * With a read replica, DB::select() is a READ: Laravel may send it to the
 * replica, which refuses writes ("read-only transaction"). A write that
 * returns rows (INSERT/UPDATE/DELETE ... RETURNING) must use
 * App\Shared\Database\Sql::returning(), which runs it on the primary and
 * keeps the request's next reads there (docs/performance.md, 6.6).
 * Found the hard way: a product edit failed with the replica on.
 */
class WritesUseThePrimaryTest extends TestCase
{
    public function test_no_write_statement_goes_through_a_read_method(): void
    {
        $appDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
        $offenders = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));
        foreach ($files as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            // DB::select( / DB::selectOne( / ->select( followed by a string
            // that starts with a write statement.
            if (preg_match_all('/(?:DB::|->)select(?:One)?\(\s*[\'"]\s*(INSERT|UPDATE|DELETE)\b/i', $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$match, $offset]) {
                    $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                    $offenders[] = $file->getPathname().':'.$line.'  '.trim($match);
                }
            }
        }

        $this->assertSame([], $offenders, "Writes through a read method (use Sql::returning):\n".implode("\n", $offenders));
    }
}
