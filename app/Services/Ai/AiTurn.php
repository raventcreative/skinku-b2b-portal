<?php

namespace App\Services\Ai;

/**
 * Satu giliran jawaban dari otak AI: TEKS final, ATAU permintaan memanggil
 * alat. Bentuk ini netral-provider — OpenAI/Anthropic dipetakan ke sini oleh
 * masing-masing AiProvider, jadi AiAgentService tak perlu tahu providernya.
 */
final class AiTurn
{
    /**
     * @param  array<int,array{id:string,name:string,arguments:array<string,mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly ?string $text = null,
        public readonly array $toolCalls = [],
    ) {}

    /** Model minta panggil alat (bukan jawaban final). */
    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
