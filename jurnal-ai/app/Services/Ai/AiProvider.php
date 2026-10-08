<?php

namespace App\Services\Ai;

/**
 * Otak AI yang bisa diganti tanpa menyentuh kode pemanggil.
 *
 * Format pesan internal (netral-provider):
 *   ['role' => 'system'|'user'|'assistant', 'content' => string]
 *   ['role' => 'user', 'content' => [ <part>, <part>, … ]]
 *
 * Bentuk <part> — tiap provider memetakannya ke format API masing-masing:
 *   ['type' => 'text',  'text' => string]
 *   ['type' => 'image', 'mime' => 'image/jpeg', 'data' => <base64>]
 *   ['type' => 'file',  'mime' => 'application/pdf', 'data' => <base64>, 'filename' => string]
 *
 * Opsi: ['json' => bool] minta balasan JSON, ['max_tokens' => int] batas keluaran.
 */
interface AiProvider
{
    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array{json?:bool,max_tokens?:int}  $options
     *
     * @throws AiException
     */
    public function chat(array $messages, array $options = []): AiTurn;

    /** Nama model aktif — dicatat di dokumen untuk jejak audit. */
    public function model(): string;
}
