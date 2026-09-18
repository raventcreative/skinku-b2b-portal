<?php

namespace App\Services\EcomChat;

use App\Contracts\EcomChatSender;
use App\Models\EcomChatConversation;
use App\Services\EcomChatService;

class TikTokChatSender implements EcomChatSender
{
    public function __construct(private EcomChatService $service) {}

    public function send(EcomChatConversation $conv, string $text): array
    {
        return $this->service->sendViaTikTok($conv, $text);
    }
}
