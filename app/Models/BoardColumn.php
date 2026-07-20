<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoardColumn extends Model
{
    protected $fillable = ['board_id', 'name', 'position'];

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function cards()
    {
        return $this->hasMany(BoardCard::class, 'column_id')->orderBy('position')->orderBy('id');
    }
}
