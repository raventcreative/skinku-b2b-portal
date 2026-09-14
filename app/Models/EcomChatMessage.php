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

    protected $fillable = [
        'conversation_id', 'channel', 'external_message_id',
        'sender', 'via', 'text', 'sent_at',
    ];

    protected $casts = ['sent_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EcomChatConversation::class, 'conversation_id');
    }
}
