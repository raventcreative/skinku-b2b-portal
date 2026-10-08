<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        /** Gagal sementara (timeout/rate limit/5xx) → layak dicoba ke otak cadangan. */
        public readonly bool $transient = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
