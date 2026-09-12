<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule failure caught by an Action before any write happens —
 * a stale/mismatched reference (wrong area, wrong Project, unavailable
 * record), not a basic type/shape problem. Distinct from Laravel's own
 * ValidationException, which the MCP layer already turns into a structured
 * error via the Errable contract.
 */
class TodoValidationException extends RuntimeException {}
