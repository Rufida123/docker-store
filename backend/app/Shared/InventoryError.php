<?php

namespace App\Shared;

use RuntimeException;

/**
 * Any other failure talking to the inventory service (unexpected gRPC status,
 * malformed response, misbuilt transport).
 */
class InventoryError extends RuntimeException
{
}
