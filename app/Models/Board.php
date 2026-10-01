<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Board extends Model
{
    use SoftDeletes;

    /** Kolom bawaan papan baru — bisa diubah/ditambah bebas setelahnya. */
    public const DEFAULT_COLUMNS = ['To Do', 'Proses', 'Selesai'];

    protected $fillable = ['name', 'created_by'];

    /** Papan baru + kolom default (To Do/Proses/Selesai). Dipakai menu Kanban & Susun OKR. */
    public static function createWithDefaultColumns(string $name, int $userId): self
    {
        return DB::transaction(function () use ($name, $userId) {
            $board = self::create(['name' => $name, 'created_by' => $userId]);
            foreach (self::DEFAULT_COLUMNS as $i => $col) {
                $board->columns()->create(['name' => $col, 'position' => $i]);
            }

            return $board;
        });
    }

    public function columns()
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
