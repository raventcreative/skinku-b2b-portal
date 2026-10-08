<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LearningModule extends Model
{
    protected $table = 'learning_modules';

    protected $fillable = ['title', 'description', 'sort_order', 'is_published', 'created_by'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    /** Modul di halaman Academy untuk user ini: pengelola (manage_learning) semua, lainnya yang terbit saja. */
    public static function terlihatUntuk(User $user): Collection
    {
        $kelola = $user->canDo('manage_learning');

        return self::query()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (self $m) => $kelola || $m->is_published)
            ->values();
    }

    public function lessons()
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('sort_order')->orderByDesc('id');
    }
}
