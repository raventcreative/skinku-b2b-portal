<?php

namespace App\Services\Discovery;

use Illuminate\Support\Facades\Http;

/**
 * Pencarian web via Tavily (https://tavily.com) — TANPA SDK, cukup Http bawaan.
 * Dipilih karena hasilnya sudah ringkas/AI-ready & ada free tier. Key WAJIB di
 * .env server (TAVILY_API_KEY) — jangan commit.
 */
class TavilyProvider implements WebSearchProvider
{
    public function __construct(
        private string $apiKey,
        private string $base = 'https://api.tavily.com',
        private int $timeout = 30,
        private int $connectTimeout = 10,
    ) {}

    public function search(string $query, int $maxResults = 6): array
    {
        if ($this->apiKey === '') {
            throw new DiscoveryException('TAVILY_API_KEY belum diisi di .env server.');
        }

        try {
            $res = Http::acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post(rtrim($this->base, '/').'/search', [
                    'api_key' => $this->apiKey,
                    'query' => $query,
                    'max_results' => max(1, min(20, $maxResults)),
                    'search_depth' => 'basic',
                    'include_answer' => false,
                ]);
        } catch (\Throwable $e) {
            throw new DiscoveryException('Tavily tidak merespons dalam batas waktu.', previous: $e);
        }

        if (! $res->successful()) {
            throw new DiscoveryException($res->status() === 401
                ? 'Key Tavily ditolak — cek TAVILY_API_KEY di .env server.'
                : 'Tavily menolak permintaan (HTTP '.$res->status().').');
        }

        // Buang hasil tanpa URL: link sumber wajib supaya kandidat/tren bisa
        // diverifikasi manual (dan biar AI tak punya bahan untuk mengarang).
        return collect($res->json('results') ?? [])
            ->map(fn ($r) => [
                'title' => (string) ($r['title'] ?? ''),
                'url' => (string) ($r['url'] ?? ''),
                'content' => (string) ($r['content'] ?? ''),
            ])
            ->filter(fn ($r) => $r['url'] !== '')
            ->values()
            ->all();
    }
}
