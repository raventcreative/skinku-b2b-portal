<?php

namespace App\Contracts;

use App\Models\EcomChatConversation;

interface EcomChatSender
{
    /** Kirim teks ke pembeli; kembalikan ['message_id' => string]. */
    public function send(EcomChatConversation $conv, string $text): array;
}
