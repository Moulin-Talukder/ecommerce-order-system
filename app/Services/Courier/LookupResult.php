<?php

namespace App\Services\Courier;

/**
 * Result of a status lookup. $status is the provider's raw status string.
 */
final readonly class LookupResult
{
    public const FOUND = 'found';

    public const NOT_FOUND = 'not_found';

    public const FAILED = 'failed';

    /**
     * @param  'found'|'not_found'|'failed'  $result
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public string $result,
        public ?string $status = null,
        public string $message = '',
        public ?array $response = null,
    ) {}

    public function found(): bool
    {
        return $this->result === self::FOUND;
    }

    public function notFound(): bool
    {
        return $this->result === self::NOT_FOUND;
    }
}
