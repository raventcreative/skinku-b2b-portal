<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OkrTask extends Model
{
    protected $fillable = [
        'okr_key_result_id', 'title', 'description', 'assignee_user_id',
        'board_column_id', 'due_date', 'position', 'board_card_id',
    ];

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function keyResult()
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function column()
    {
        return $this->belongsTo(BoardColumn::class, 'board_column_id');
    }

    public function card()
    {
        return $this->belongsTo(BoardCard::class, 'board_card_id');
    }

    public function isCompleted(): bool
    {
        return $this->card?->isCompleted() ?? false;
    }
}
