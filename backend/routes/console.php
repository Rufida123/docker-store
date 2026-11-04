<?php

use Illuminate\Support\Facades\Schedule;

// Undelivered inventory releases are retried until they land.
Schedule::command('inventory:flush-outbox')->everyMinute();
