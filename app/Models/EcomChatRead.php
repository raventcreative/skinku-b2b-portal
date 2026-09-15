<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EcomChatRead extends Model
{
    protected $fillable = ['user_id', 'conversation_id', 'last_read_at'];

    protected $casts = ['last_read_at' => 'datetime'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EcomChatConversation::class, 'conversation_id');
    }
}
