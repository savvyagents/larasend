<?php

namespace App\Services\Providers;

use RuntimeException;
use Throwable;

/**
 * Automatic provider-side domain onboarding failed, but the domain can still
 * be verified manually. Carries the manual verification records so callers
 * can create the domain anyway while telling the user exactly what failed.
 */
class DomainOnboardingException extends RuntimeException
{
    /**
     * @param  array<int, array{type: string, name: string, value: string, status: string}>  $fallbackRecords
     */
    public function __construct(
        string $message,
        public readonly array $fallbackRecords,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
