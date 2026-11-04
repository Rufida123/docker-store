<?php

namespace App\Shared;

use RuntimeException;

/**
 * The inventory service could not be reached (connection refused, DNS, reset,
 * or it reported UNAVAILABLE). Shared so every module can react to it.
 */
class InventoryUnavailable extends RuntimeException
{
}
