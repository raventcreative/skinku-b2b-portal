<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * Error khusus lapisan AI (key kosong, API nolak/limit, model ngaco). Pesannya
 * sudah ramah-user (Bahasa Indonesia) dan aman ditampilkan di chat — bukan 500.
 */
class AiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $transient = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }
}
