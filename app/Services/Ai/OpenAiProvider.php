<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

/**
 * Otak berbasis OpenAI Chat Completions (via Http bawaan Laravel — TANPA SDK).
 * Memetakan format pesan/alat internal (lihat AiProvider) ke bentuk OpenAI dan
 * sebaliknya. Pilih model & max token disuntik factory dari config/AppSetting.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $base,
        private string $model,
        private int $maxTokens,
    ) {}

    public function chat(array $messages, array $tools): AiTurn
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

        try {
            $res = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout(60)
                ->post(rtrim($this->base, '/').'/chat/completions', $payload);
        } catch (\Throwable $e) {
            throw new AiException('Tak bisa menghubungi OpenAI (jaringan/timeout). Coba lagi sebentar.');
        }

        if (! $res->successful()) {
            throw new AiException($this->explain($res->status(), $res->json('error.message')));
        }

        return $this->parse($res->json('choices.0.message') ?? []);
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
        return match (true) {
            $status === 401 => 'Key OpenAI ditolak — cek OPENAI_API_KEY di .env server.',
            $status === 429 => 'OpenAI lagi sibuk / kena limit. Coba lagi sebentar.',
            $status >= 500 => 'Server OpenAI lagi bermasalah. Coba lagi nanti.',
            default => 'OpenAI menolak permintaan'.($detail ? ": {$detail}" : '.'),
        };
    }
}
