<?php

namespace App\Services\Ai;

/**
 * Otak AI yang bisa diganti. Implementasi (OpenAiProvider, nanti
 * AnthropicProvider) memetakan format internal ke API masing-masing.
 *
 * Format pesan internal (netral-provider), tiap item salah satu dari:
 *   ['role' => 'system'|'user'|'assistant', 'content' => string]
 *   ['role' => 'assistant', 'tool_calls' => [['id','name','arguments'=>array], ...]]
 *   ['role' => 'tool', 'tool_call_id' => string, 'content' => string]
 *
 * Format alat internal:
 *   ['name' => string, 'description' => string, 'parameters' => array (JSON Schema)]
 */
interface AiProvider
{
    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<int,array<string,mixed>>  $tools
     *
     * @throws AiException bila key kosong / API menolak / respons tak terbaca
     */
    public function chat(array $messages, array $tools): AiTurn;
}
