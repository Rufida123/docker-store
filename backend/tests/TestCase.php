<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Safety net: the suite migrates and truncates freely, so it must
        // never touch anything but a dedicated *_test database. This runs
        // before any trait (RefreshDatabase included) does its work.
        $this->afterApplicationCreated(function (): void {
            $database = (string) DB::connection()->getDatabaseName();
            if (! str_ends_with($database, '_test')) {
                fwrite(STDERR, "Refusing to run tests against \"{$database}\": the database name must end with _test.\n");
                exit(1);
            }
        });

        parent::setUp();
    }
}
