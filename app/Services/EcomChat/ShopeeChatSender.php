<?php

namespace App\Services\EcomChat;

use App\Contracts\EcomChatSender;
use App\Models\EcomChatConversation;
use App\Services\ShopeeClient;
use App\Services\ShopeeSyncService;
use RuntimeException;

class ShopeeChatSender implements EcomChatSender
{
    public function __construct(private ShopeeClient $client, private ShopeeSyncService $sync) {}

    public function send(EcomChatConversation $conv, string $text): array
    {
        $conn = $this->sync->connection();
        if (! $conn) {
            throw new RuntimeException('Toko Shopee belum terhubung.');
        }
        $res = $this->client->sendChatMessage($this->sync->freshToken($conn), (string) $conn->shop_id, (int) $conv->buyer_id, $text);
        if (($res['error'] ?? '') !== '') {
            throw new RuntimeException('Shopee tolak kirim: '.($res['message'] ?? $res['error']));
        }

        return ['message_id' => (string) ($res['response']['message_id'] ?? ('local-'.uniqid()))];
    }
}
