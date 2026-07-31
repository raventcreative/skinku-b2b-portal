<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Otak berbasis OpenAI Chat Completions (via Http bawaan Laravel — TANPA SDK).
 * Memetakan format pesan/alat internal (lihat AiProvider) ke bentuk OpenAI dan
 * sebaliknya. Pilih model & max token disuntik factory dari config/AppSetting.
 */
class OpenAiProvider implements ConcurrentAiProvider
{
    public function __construct(
        private string $apiKey,
        private string $base,
        private string $model,
        private int $maxTokens,
        private int $timeout = 45,
        private int $connectTimeout = 10,
        // Beberapa endpoint (mis. router self-hosted) kewalahan menerima banyak
        // request paralel sekaligus. Mode sekuensial menjalankan chatMany satu
        // per satu (lebih lambat tapi jauh lebih tahan).
        private bool $sequential = false,
    ) {}

    public function chat(array $messages, array $tools): AiTurn
    {
        try {
            $res = Http::withToken($this->apiKey)
                ->acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->endpoint(), $this->payload($messages, $tools));
        } catch (\Throwable $e) {
            $this->logConnectionFailure('single', $e);

            throw new AiException(
                'OpenAI tidak merespons dalam batas waktu. Sistem dapat memakai hasil panel/fallback bila tersedia.',
                transient: true,
                previous: $e,
            );
        }

        return $this->turnFromResponse($res);
    }

    public function chatMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        // Mode sekuensial: satu per satu lewat chat(). Dipakai untuk endpoint
        // cadangan yang tak kuat menerima banyak request paralel.
        if ($this->sequential) {
            $turns = [];
            foreach ($requests as $key => $request) {
                $turns[$key] = $this->chat($request['messages'] ?? [], $request['tools'] ?? []);
            }

            return $turns;
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($requests) {
                $pending = [];
                foreach ($requests as $key => $request) {
                    $pending[] = $pool->as($key)
                        ->withToken($this->apiKey)
                        ->acceptJson()
                        ->connectTimeout($this->connectTimeout)
                        ->timeout($this->timeout)
                        ->post($this->endpoint(), $this->payload(
                            $request['messages'] ?? [],
                            $request['tools'] ?? [],
                        ));
                }

                return $pending;
            });
        } catch (\Throwable $e) {
            $this->logConnectionFailure('parallel', $e);

            throw new AiException(
                'Panel OpenAI tidak merespons dalam batas waktu. Sistem akan memakai fallback berbasis data yang tersedia.',
                transient: true,
                previous: $e,
            );
        }

        $turns = [];
        foreach (array_keys($requests) as $key) {
            $response = $responses[$key] ?? null;
            if (! $response instanceof Response) {
                throw new AiException(
                    "Panel AI {$key} gagal menerima respons dari OpenAI.",
                    transient: true,
                );
            }
            $turns[$key] = $this->turnFromResponse($response);
        }

        return $turns;
    }

    private function payload(array $messages, array $tools): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->mapMessages($messages),
            'max_tokens' => $this->maxTokens,
        ];
        if ($tools !== []) {
            $payload['tools'] = $this->mapTools($tools);
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    private function endpoint(): string
    {
        return rtrim($this->base, '/').'/chat/completions';
    }

    private function turnFromResponse(Response $response): AiTurn
    {
        if (! $response->successful()) {
            $status = $response->status();
            $detail = $response->json('error.message');
            throw new AiException(
                $this->explain($status, $detail),
                transient: $this->isTransientResponse($status, $detail),
            );
        }

        return $this->parse($response->json('choices.0.message') ?? []);
    }

    /** Pesan internal → format OpenAI. */
    private function mapMessages(array $messages): array
    {
        return array_map(function (array $m): array {
            // Assistant yang minta alat (dipakai saat replay riwayat loop).
            if (($m['role'] ?? null) === 'assistant' && ! empty($m['tool_calls'])) {
                return [
                    'role' => 'assistant',
                    'content' => $m['content'] ?? null,
                    'tool_calls' => array_map(fn (array $tc): array => [
                        'id' => $tc['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['name'],
                            'arguments' => json_encode($tc['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                        ],
                    ], $m['tool_calls']),
                ];
            }

            // Hasil eksekusi alat.
            if (($m['role'] ?? null) === 'tool') {
                return [
                    'role' => 'tool',
                    'tool_call_id' => $m['tool_call_id'] ?? '',
                    'content' => (string) ($m['content'] ?? ''),
                ];
            }

            // system / user / assistant biasa.
            return [
                'role' => $m['role'] ?? 'user',
                'content' => (string) ($m['content'] ?? ''),
            ];
        }, $messages);
    }

    /** Skema alat internal → format OpenAI (function calling). */
    private function mapTools(array $tools): array
    {
        return array_map(fn (array $t): array => [
            'type' => 'function',
            'function' => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'parameters' => $t['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
            ],
        ], $tools);
    }

    /** Pesan balasan OpenAI → AiTurn netral. */
    private function parse(array $msg): AiTurn
    {
        if (! empty($msg['tool_calls'])) {
            $calls = [];
            foreach ($msg['tool_calls'] as $tc) {
                $fn = $tc['function'] ?? [];
                $args = json_decode($fn['arguments'] ?? '{}', true);
                $calls[] = [
                    'id' => $tc['id'] ?? '',
                    'name' => $fn['name'] ?? '',
                    'arguments' => is_array($args) ? $args : [],
                ];
            }

            return new AiTurn(toolCalls: $calls);
        }

        return new AiTurn(text: (string) ($msg['content'] ?? ''));
    }

    /** Pesan error yang enak dibaca sesuai status HTTP. */
    private function explain(int $status, ?string $detail): string
    {
        $quotaProblem = $status === 429 && $this->isQuotaProblem($detail);

        return match (true) {
            $status === 401 => 'Key OpenAI ditolak — cek OPENAI_API_KEY di .env server.',
            $quotaProblem => 'Kuota/saldo OpenAI tidak tersedia — periksa Billing dan Usage Limits.',
            $status === 429 => 'OpenAI terkena rate limit sementara. Sistem dapat memakai hasil panel/fallback yang tersedia.',
            $status >= 500 => 'Server OpenAI lagi bermasalah. Coba lagi nanti.',
            default => 'OpenAI menolak permintaan'.($detail ? ": {$detail}" : '.'),
        };
    }

    private function isTransientResponse(int $status, ?string $detail): bool
    {
        if ($status === 429) {
            return ! $this->isQuotaProblem($detail);
        }

        return in_array($status, [408, 409, 425], true) || $status >= 500;
    }

    private function isQuotaProblem(?string $detail): bool
    {
        return $detail !== null
            && preg_match('/insufficient_quota|quota|billing|credit/i', $detail) === 1;
    }

    private function logConnectionFailure(string $stage, \Throwable $e): void
    {
        Log::warning('Koneksi OpenAI gagal.', [
            'stage' => $stage,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
