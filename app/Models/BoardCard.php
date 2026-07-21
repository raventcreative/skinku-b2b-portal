<?php

namespace App\Models;

use App\Models\Concerns\HasFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class BoardCard extends Model
{
    use HasFiles, SoftDeletes;

    /** Koleksi lampiran gambar kartu (mockup, tangkapan layar, referensi). */
    public const ATTACHMENT = 'card_attachment';

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

    /**
     * Lampiran gambar kartu, terurut. Menyaring relasi `files` yang sudah
     * di-eager-load (bukan query baru) supaya papan berisi banyak kartu tak
     * memicu N+1. Kembalian: Collection<File>, bukan relasi.
     */
    public function attachments(): Collection
    {
        return $this->files
            ->where('collection', self::ATTACHMENT)
            ->sortBy('sort_order')
            ->values();
    }
}
