<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'last_message_at', 'last_incoming_at', 'last_message_preview', 'status',
        'ai_draft', 'ai_decision', 'ai_reason',
    ];

    protected $casts = ['last_message_at' => 'datetime', 'last_incoming_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(EcomChatMessage::class, 'conversation_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(EcomChatRead::class, 'conversation_id');
    }

    /**
     * Percakapan yang BELUM DIBACA oleh $user: ada pesan pembeli (last_incoming_at),
     * belum ditutup, dan lebih baru dari last_read_at user itu (atau belum pernah dibuka).
     */
    public function scopeUnreadFor(Builder $query, User $user): Builder
    {
        return $query->leftJoin('ecom_chat_reads', function ($join) use ($user) {
            $join->on('ecom_chat_reads.conversation_id', '=', 'ecom_chat_conversations.id')
                ->where('ecom_chat_reads.user_id', '=', $user->id);
        })
            ->whereNotNull('ecom_chat_conversations.last_incoming_at')
            ->where('ecom_chat_conversations.status', '!=', self::STATUS_CLOSED)
            ->whereRaw("ecom_chat_conversations.last_incoming_at > COALESCE(ecom_chat_reads.last_read_at, '1970-01-01 00:00:00')")
            ->select('ecom_chat_conversations.*');
    }
}
