<?php

namespace App\Services\Discovery;

/**
 * Sumber pencarian web yang bisa diganti (Tavily → Serper/Brave nanti).
 * Mengembalikan hasil TERNORMALISASI sehingga AiDiscoveryService tak perlu tahu
 * provider mana yang dipakai.
 */
interface WebSearchProvider
{
    /**
     * @return array<int,array{title:string,url:string,content:string}>
     *
     * @throws DiscoveryException bila key kosong / API menolak / respons tak terbaca
     */
    public function search(string $query, int $maxResults = 6): array;
}
