<?php

declare(strict_types=1);

namespace Reflexometr\Http;

use RuntimeException;
use Throwable;

/**
 * Thrown by controllers/services to signal a structured API error. Caught centrally by the
 * front controller and turned into the standard error-response shape — never rendered with a
 * hardcoded English string (CR-UI-02).
 */
final class ApiException extends RuntimeException
{
    /** @param array<string,mixed>|null $details Structural data only — never free English text. */
    public function __construct(
        private readonly string $errorCode,
        private readonly int $status = 400,
        private readonly ?array $details = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string,mixed>|null */
    public function details(): ?array
    {
        return $this->details;
    }
}
