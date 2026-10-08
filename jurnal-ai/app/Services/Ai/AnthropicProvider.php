<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Otak berbasis Anthropic Messages API. Bedanya dari OpenAI: pesan `system`
 * bukan bagian dari array messages tapi field terpisah, dan gambar/PDF dikirim
 * sebagai blok `source` base64 (`image` untuk gambar, `document` untuk PDF).
 */
class AnthropicProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $base,
        private string $model,
        private int $maxTokens = 4000,
        private int $timeout = 120,
        private int $connectTimeout = 10,
        private string $version = '2023-06-01',
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $options = []): AiTurn
    {
        // Anthropic: system dipisah dari percakapan.
        $system = [];
        $turns = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? 'user') === 'system') {
                $system[] = is_array($m['content'] ?? null)
                    ? implode("\n", array_column($m['content'], 'text'))
                    : (string) ($m['content'] ?? '');

                continue;
            }
            $turns[] = $this->mapMessage($m);
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
            'messages' => $turns,
        ];
        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        try {
            $res = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->version,
            ])
                ->acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post(rtrim($this->base, '/').'/messages', $payload);
        } catch (\Throwable $e) {
            Log::warning('Koneksi AI gagal.', ['model' => $this->model, 'error' => $e->getMessage()]);

            throw new AiException(
                "Model {$this->model} tidak merespons dalam batas waktu.",
                transient: true,
                previous: $e,
            );
        }

        return $this->turnFrom($res);
    }

    private function mapMessage(array $m): array
    {
        $content = $m['content'] ?? '';
        if (! is_array($content)) {
            return ['role' => $m['role'] ?? 'user', 'content' => (string) $content];
        }

        $blocks = [];
        foreach ($content as $part) {
            $blocks[] = match ($part['type'] ?? 'text') {
                'image' => [
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $part['mime'], 'data' => $part['data']],
                ],
                'file' => [
                    'type' => 'document',
                    'source' => ['type' => 'base64', 'media_type' => $part['mime'], 'data' => $part['data']],
                ],
                default => ['type' => 'text', 'text' => (string) ($part['text'] ?? '')],
            };
        }

        return ['role' => $m['role'] ?? 'user', 'content' => $blocks];
    }

    private function turnFrom(Response $response): AiTurn
    {
        if (! $response->successful()) {
            $status = $response->status();
            $detail = $response->json('error.message');
            throw new AiException(
                match (true) {
                    $status === 401 || $status === 403 => 'ANTHROPIC_API_KEY ditolak — cek kredensial di .env.',
                    $status === 429 => "Model {$this->model} kena rate limit / kuota habis.",
                    $status >= 500 => 'Server Anthropic sedang bermasalah.',
                    default => 'Anthropic menolak permintaan'.($detail ? ": {$detail}" : '.'),
                },
                transient: $status === 429 || $status >= 500,
            );
        }

        // Balasan = daftar blok; ambil semua blok teks lalu gabung.
        $text = collect($response->json('content') ?? [])
            ->where('type', 'text')->pluck('text')->implode('');

        if (trim($text) === '') {
            throw new AiException("Model {$this->model} membalas kosong.", transient: true);
        }

        return new AiTurn($text, $this->model);
    }
}
