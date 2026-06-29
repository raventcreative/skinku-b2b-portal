<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function lessons()
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('sort_order')->orderByDesc('id');
    }
}
