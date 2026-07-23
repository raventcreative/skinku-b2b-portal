<?php

namespace Tests\Support;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTurn;

/**
 * Otak palsu buat uji loop agent TANPA jaringan. Balikin AiTurn yang di-skrip
 * berurutan; simpan tiap payload yang "dikirim" biar bisa di-assert.
 */
class FakeAiProvider implements AiProvider
{
    private int $i = 0;

    /** @var array<int,array{messages:array,tools:array}> */
    public array $sent = [];

    /** @param  array<int,AiTurn>  $turns */
    public function __construct(private array $turns) {}

    public function chat(array $messages, array $tools): AiTurn
    {
        $this->sent[] = ['messages' => $messages, 'tools' => $tools];

        return $this->turns[$this->i++] ?? new AiTurn(text: '(tak ada giliran lagi)');
    }
}
