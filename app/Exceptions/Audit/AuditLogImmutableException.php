<?php

namespace App\Exceptions\Audit;

use LogicException;

/**
 * Something tried to change or delete an existing audit record. Audit
 * records are append-only; this is a programming error, never user input.
 */
class AuditLogImmutableException extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("Audit records are append-only; {$operation} is not allowed.");
    }
}
