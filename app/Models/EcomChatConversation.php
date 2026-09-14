<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EcomChatConversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_NEEDS_STAFF = 'needs_staff';

    public const STATUS_REPLIED = 'replied';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'channel', 'external_conversation_id', 'buyer_name', 'buyer_id',
        'last_message_at', 'last_message_preview', 'status',
        'ai_draft', 'ai_decision', 'ai_reason',
    ];

    protected $casts = ['last_message_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(EcomChatMessage::class, 'conversation_id');
    }
}
