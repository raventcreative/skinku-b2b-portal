<?php

namespace App\Services\Discovery;

/**
 * Bikin WebSearchProvider aktif dari config — satu-satunya tempat yang tahu
 * "lagi pakai mesin pencari apa". Tambah Serper/Brave cukup tambah cabang match.
 */
class WebSearchFactory
{
    public static function make(): WebSearchProvider
    {
        $provider = (string) config('services.discovery.provider', 'tavily');

        return match ($provider) {
            default => new TavilyProvider(
                (string) config('services.discovery.tavily.key'),
                (string) config('services.discovery.tavily.base', 'https://api.tavily.com'),
                (int) config('services.discovery.tavily.timeout', 30),
                (int) config('services.discovery.connect_timeout', 10),
                (string) config('services.discovery.tavily.depth', 'advanced'),
            ),
        };
    }

    /** Discovery siap dipakai? (ada key) — untuk sembunyikan menu/tombol & tampilkan notice. */
    public static function configured(): bool
    {
        return filled(config('services.discovery.tavily.key'));
    }
}
