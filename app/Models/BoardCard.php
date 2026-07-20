<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardCard extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'column_id', 'title', 'description', 'assignee_user_id', 'due_date', 'position', 'created_by',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function column()
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** Urut kronologis (tertua dulu) — dibaca seperti percakapan. */
    public function comments()
    {
        return $this->hasMany(BoardCardComment::class, 'card_id')->orderBy('created_at')->orderBy('id');
    }
}
