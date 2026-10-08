<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Otak berbasis OpenAI Chat Completions — juga dipakai untuk semua endpoint
 * OpenAI-compatible (OpenRouter, Groq, DeepSeek, Together) karena bentuk
 * payload-nya identik. Tanpa SDK, cukup Http client bawaan Laravel.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(
        private string $apiKey,
        private string $base,
        private string $model,
        private int $maxTokens = 4000,
        private int $timeout = 120,
        private int $connectTimeout = 10,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function chat(array $messages, array $options = []): AiTurn
    {
        $payload = [
            'model' => $this->model,
            'messages' => array_map($this->mapMessage(...), $messages),
            'max_completion_tokens' => (int) ($options['max_tokens'] ?? $this->maxTokens),
        ];
        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        try {
            $res = Http::withToken($this->apiKey)
                ->acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post(rtrim($this->base, '/').'/chat/completions', $payload);
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

    /** Pesan internal → bentuk OpenAI. Konten array dipetakan part demi part. */
    private function mapMessage(array $m): array
    {
        $content = $m['content'] ?? '';
        if (! is_array($content)) {
            return ['role' => $m['role'] ?? 'user', 'content' => (string) $content];
        }

        $parts = [];
        foreach ($content as $part) {
            $parts[] = match ($part['type'] ?? 'text') {
                'image' => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$part['mime'].';base64,'.$part['data']],
                ],
                'file' => [
                    'type' => 'file',
                    'file' => [
                        'filename' => $part['filename'] ?? 'dokumen.pdf',
                        'file_data' => 'data:'.$part['mime'].';base64,'.$part['data'],
                    ],
                ],
                default => ['type' => 'text', 'text' => (string) ($part['text'] ?? '')],
            };
        }

        return ['role' => $m['role'] ?? 'user', 'content' => $parts];
    }

    private function turnFrom(Response $response): AiTurn
    {
        if (! $response->successful()) {
            $status = $response->status();
            $detail = $response->json('error.message');
            throw new AiException($this->explain($status, $detail), transient: $this->isTransient($status, $detail));
        }

        $text = (string) ($response->json('choices.0.message.content') ?? '');
        if (trim($text) === '') {
            throw new AiException("Model {$this->model} membalas kosong.", transient: true);
        }

        return new AiTurn($text, $this->model);
    }

    private function explain(int $status, ?string $detail): string
    {
        $quota = $status === 429 && $this->isQuota($detail);

        return match (true) {
            $status === 401 || $status === 403 => "Key API ditolak untuk model {$this->model} — cek kredensial di .env.",
            $quota => "Kuota/saldo habis untuk model {$this->model} — cek billing.",
            $status === 429 => "Model {$this->model} kena rate limit sementara.",
            $status === 413 => 'Dokumen terlalu besar untuk model — kompres gambar atau pecah PDF.',
            $status >= 500 => "Server penyedia model {$this->model} sedang bermasalah.",
            default => "Permintaan ditolak penyedia model".($detail ? ": {$detail}" : '.'),
        };
    }

    private function isTransient(int $status, ?string $detail): bool
    {
        if ($status === 429) {
            return ! $this->isQuota($detail);
        }

        return in_array($status, [408, 409, 425], true) || $status >= 500;
    }

    private function isQuota(?string $detail): bool
    {
        return $detail !== null && preg_match('/insufficient_quota|quota|billing|credit/i', $detail) === 1;
    }
}
