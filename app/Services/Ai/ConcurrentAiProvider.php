<?php

namespace App\Services\Ai;

/**
 * Kemampuan opsional provider untuk menjalankan beberapa giliran independen
 * secara paralel. Provider tanpa kemampuan ini tetap memakai jalur berurutan.
 */
interface ConcurrentAiProvider extends AiProvider
{
    /**
     * @param  array<string,array{messages:array<int,array<string,mixed>>,tools:array<int,array<string,mixed>>}>  $requests
     * @return array<string,AiTurn>
     */
    public function chatMany(array $requests): array;
}
