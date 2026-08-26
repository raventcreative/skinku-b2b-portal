<?php

namespace Tests\Support;

use App\Services\Discovery\DiscoveryException;
use App\Services\Discovery\WebSearchProvider;

/**
 * Mesin pencari palsu buat uji AiDiscoveryService TANPA jaringan. Balikin hasil
 * yang di-skrip; simpan tiap query yang "dicari" biar bisa di-assert. Set
 * $throw untuk mensimulasikan key kosong / API down.
 */
class FakeWebSearchProvider implements WebSearchProvider
{
    /** @var array<int,string> */
    public array $queries = [];

    /** @param  array<int,array{title:string,url:string,content:string}>  $results */
    public function __construct(private array $results = [], private ?string $throw = null) {}

    public function search(string $query, int $maxResults = 6): array
    {
        $this->queries[] = $query;

        if ($this->throw !== null) {
            throw new DiscoveryException($this->throw);
        }

        return $this->results;
    }
}
