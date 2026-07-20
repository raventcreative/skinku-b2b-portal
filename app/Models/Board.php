<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Board extends Model
{
    use SoftDeletes;

    /** Kolom bawaan papan baru — bisa diubah/ditambah bebas setelahnya. */
    public const DEFAULT_COLUMNS = ['To Do', 'Proses', 'Selesai'];

    protected $fillable = ['name', 'created_by'];

    public function columns()
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
