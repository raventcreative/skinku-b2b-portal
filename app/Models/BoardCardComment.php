<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardCardComment extends Model
{
    protected $fillable = ['card_id', 'user_id', 'body'];

    public function card()
    {
        return $this->belongsTo(BoardCard::class, 'card_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
