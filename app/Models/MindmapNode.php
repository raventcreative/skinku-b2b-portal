<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapNode extends Model
{
    protected $fillable = ['mindmap_id', 'type', 'x', 'y', 'width', 'height', 'text', 'color', 'created_by'];

    /** Default di memori supaya node baru langsung punya ukuran/warna (bukan hanya default DB). */
    protected $attributes = [
        'type' => 'sticky',
        'width' => 200,
        'height' => 120,
        'color' => 'kuning',
    ];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float'];
    }
}
