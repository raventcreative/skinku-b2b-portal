<?php

namespace App\Services\Ai;

/**
 * Hasil satu giliran asisten yang siap ditampilkan ke UI:
 *  - text    : jawaban biasa
 *  - confirm : usulan aksi TULIS yang menunggu klik "Ya" (pending = tool+args+preview)
 *  - error   : gagal yang ramah (key kosong, API mati)
 */
final class AgentResult
{
    private function __construct(
        public readonly string $type,
        public readonly string $text = '',
        public readonly ?array $pending = null,
    ) {}

    public static function text(string $text): self
    {
        return new self('text', $text);
    }

    public static function error(string $text): self
    {
        return new self('error', $text);
    }

    public static function confirm(string $preview, string $tool, array $args): self
    {
        return new self('confirm', $preview, ['tool' => $tool, 'args' => $args, 'preview' => $preview]);
    }
}
