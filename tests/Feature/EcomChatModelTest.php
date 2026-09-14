<?php

namespace Tests\Feature;

use App\Models\EcomChatConversation;
use App\Models\EcomChatMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EcomChatModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_percakapan_punya_banyak_pesan(): void
    {
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1',
            'buyer_name' => 'Budi', 'status' => EcomChatConversation::STATUS_OPEN,
        ]);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'M1',
            'sender' => EcomChatMessage::SENDER_BUYER, 'via' => EcomChatMessage::VIA_BUYER, 'text' => 'Halo',
        ]);

        $this->assertSame(1, $conv->messages()->count());
        $this->assertSame('Budi', $conv->messages->first()->conversation->buyer_name);
    }

    public function test_message_id_unik_per_channel(): void
    {
        $conv = EcomChatConversation::create([
            'channel' => 'tiktok', 'external_conversation_id' => 'CONV1', 'status' => 'open',
        ]);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'DUP',
            'sender' => 'buyer', 'via' => 'buyer', 'text' => 'a',
        ]);

        $this->expectException(QueryException::class);
        $conv->messages()->create([
            'channel' => 'tiktok', 'external_message_id' => 'DUP',
            'sender' => 'buyer', 'via' => 'buyer', 'text' => 'b',
        ]);
    }
}
