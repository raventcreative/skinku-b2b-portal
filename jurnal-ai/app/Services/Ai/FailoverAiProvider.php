<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Rantai otak: coba provider pertama, kalau gagal lanjut ke berikutnya.
 * Membaca dokumen itu mahal untuk diulang manual — kalau primary kehabisan
 * kuota atau timeout, user tidak boleh kehilangan hasil uploadnya.
 */
class FailoverAiProvider implements AiProvider
{
    /** @param  non-empty-array<int,AiProvider>  $chain */
    public function __construct(private array $chain) {}

    public function model(): string
    {
        return $this->chain[0]->model();
    }

    public function chat(array $messages, array $options = []): AiTurn
    {
        $last = null;

        foreach ($this->chain as $i => $provider) {
            try {
                return $provider->chat($messages, $options);
            } catch (AiException $e) {
                $last = $e;
                Log::warning('Otak AI gagal, pindah ke cadangan.', [
                    'slot' => $i,
                    'model' => $provider->model(),
                    'transient' => $e->transient,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new AiException(
            'Semua otak AI gagal merespons. Terakhir: '.$last?->getMessage(),
            transient: $last?->transient ?? false,
            previous: $last,
        );
    }
}
