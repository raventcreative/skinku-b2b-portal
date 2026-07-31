<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapNode extends Model
{
    protected $fillable = ['mindmap_id', 'type', 'x', 'y', 'width', 'height', 'text', 'color', 'created_by'];

    protected function casts(): array
    {
        return ['x' => 'float', 'y' => 'float', 'width' => 'float', 'height' => 'float'];
    }
}
