<?php

declare(strict_types=1);

namespace Rosua\Migrations\Exception;

use RuntimeException;

final class AbortMigration extends RuntimeException implements ControlException
{
}
