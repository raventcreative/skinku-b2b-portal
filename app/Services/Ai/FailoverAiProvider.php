<?php

namespace App\Services\Ai;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Rantai otak AI dengan AUTO-SWITCH. Coba provider utama; kalau ia gagal
 * (kuota/billing habis, key ditolak, atau API down setelah retry internalnya),
 * request yang sama otomatis diulang ke provider CADANGAN berikutnya.
 *
 * Failover-nya idempoten: chat/chatMany diulang utuh ke cadangan, jadi aman
 * dipakai untuk panel OKR paralel maupun obrolan Asisten.
 */
class FailoverAiProvider implements ConcurrentAiProvider
{
    /** @param array<int,AiProvider> $providers berurutan: utama dulu, lalu cadangan */
    public function __construct(private array $providers)
    {
        if ($this->providers === []) {
            throw new AiException('Tidak ada provider AI yang dikonfigurasi.');
        }
    }

    public function chat(array $messages, array $tools): AiTurn
    {
        return $this->run(fn (AiProvider $provider) => $provider->chat($messages, $tools));
    }

    /**
     * @param  array<string,array{messages:array<int,array<string,mixed>>,tools:array<int,array<string,mixed>>}>  $requests
     * @return array<string,AiTurn>
     */
    public function chatMany(array $requests): array
    {
        return $this->run(function (AiProvider $provider) use ($requests) {
            if ($provider instanceof ConcurrentAiProvider) {
                return $provider->chatMany($requests);
            }

            // Cadangan tanpa dukungan paralel → jalankan berurutan.
            $out = [];
            foreach ($requests as $key => $request) {
                $out[$key] = $provider->chat($request['messages'], $request['tools']);
            }

            return $out;
        });
    }

    /**
     * Jalankan $call pada tiap provider secara berurutan sampai satu berhasil.
     * Provider terakhir yang gagal melempar error-nya apa adanya.
     */
    private function run(Closure $call): mixed
    {
        $lastKey = array_key_last($this->providers);
        $error = null;

        foreach ($this->providers as $index => $provider) {
            try {
                return $call($provider);
            } catch (AiException $e) {
                $error = $e;
                if ($index === $lastKey) {
                    break;
                }
                // Ada cadangan → catat lalu lanjut. Sengaja failover untuk SEMUA
                // AiException (kuota/billing, key, rate-limit, atau down): provider
                // sudah retry gangguan sementaranya sendiri sebelum melempar.
                Log::warning('AI provider gagal, pindah ke cadangan.', [
                    'urutan' => $index,
                    'transient' => $e->isTransient(),
                    'pesan' => $e->getMessage(),
                ]);
            }
        }

        throw $error ?? new AiException('Semua provider AI gagal melayani permintaan.');
    }
}
