<?php

namespace App\Shared;

use RuntimeException;

/**
 * The inventory service did not answer within the configured deadline
 * (DEADLINE_EXCEEDED or a transport timeout).
 */
class InventoryTimeout extends RuntimeException
{
}
