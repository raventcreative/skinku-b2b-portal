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
        // OpenAI terbaru memakai max_completion_tokens; mayoritas endpoint
        // OpenAI-compatible masih max_tokens. Default dipilih yang paling luas
        // diterima, dan chat() tetap menukar otomatis kalau ditolak.
        private string $tokenParam = 'max_tokens',
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
            $this->tokenParam => (int) ($options['max_tokens'] ?? $this->maxTokens),
        ];
        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // Endpoint OpenAI-compatible (OpenRouter/9router/Groq/DeepSeek) tidak
        // semuanya menerima parameter opsional yang sama. Daripada gagal total,
        // buang parameter yang ditolak lalu ulangi — maksimal sekali per
        // parameter supaya tidak berputar.
        $dropped = [];
        while (true) {
            $response = $this->send($payload);

            if ($response->successful()) {
                return $this->turnFrom($response);
            }

            $offending = $this->unsupportedParameter($response, $payload);
            if ($offending === null || isset($dropped[$offending])) {
                return $this->turnFrom($response); // biarkan turnFrom melempar pesan aslinya
            }

            $dropped[$offending] = true;
            Log::info('Parameter ditolak endpoint AI, dicoba ulang tanpa parameter itu.', [
                'model' => $this->model,
                'parameter' => $offending,
            ]);

            if ($offending === $this->tokenParam) {
                // Tukar ke penamaan satunya, jangan dibuang: tanpa batas token
                // sebagian endpoint memotong balasan di tengah JSON.
                $alternatif = $this->tokenParam === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens';
                $payload[$alternatif] = $payload[$offending];
            }
            unset($payload[$offending]);
        }
    }

    private function send(array $payload)
    {
        try {
            return Http::withToken($this->apiKey)
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
    }

    /**
     * Nama parameter opsional yang ditolak endpoint, atau null kalau errornya
     * bukan soal parameter. Hanya parameter yang memang aman dibuang/ditukar
     * yang dikenali — error lain (key salah, kuota habis) harus tetap dilempar.
     */
    private function unsupportedParameter($response, array $payload): ?string
    {
        if ($response->status() !== 400) {
            return null;
        }

        $pesan = strtolower((string) (
            $response->json('error.message') ?? $response->json('message') ?? $response->body()
        ));

        foreach (['response_format', 'max_completion_tokens', 'max_tokens'] as $param) {
            if (! isset($payload[$param]) || ! str_contains($pesan, $param)) {
                continue;
            }
            // "unsupported", "unrecognized", "not supported", "invalid parameter", dsb.
            if (preg_match('/unsupported|unrecogni|not supported|unknown|invalid|unexpected/', $pesan) === 1) {
                return $param;
            }
        }

        return null;
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
