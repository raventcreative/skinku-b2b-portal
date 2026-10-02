<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcomChatMessage extends Model
{
    public const SENDER_BUYER = 'buyer';

    public const SENDER_SELLER = 'seller';

    public const SENDER_SYSTEM = 'system';

    public const VIA_AI = 'ai';

    public const VIA_STAFF = 'staff';

    public const VIA_BUYER = 'buyer';

    /** Balasan otomatis chatbot/sistem TikTok sendiri (bukan AI SKINKU, bukan staf). */
    public const VIA_BOT = 'bot';

    /** Teks pengganti kartu TikTok yang isinya tak dikirim API (sambutan, konfirmasi pesanan, dll). */
    public const BOT_CARD_TEXT = '🧩 Kartu otomatis TikTok (sambutan / konfirmasi pesanan / notifikasi) — isinya hanya terlihat di Seller Center';

    protected $fillable = [
        'conversation_id', 'channel', 'external_message_id',
        'sender', 'via', 'type', 'text', 'meta', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime', 'meta' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EcomChatConversation::class, 'conversation_id');
    }
}
