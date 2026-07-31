<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MindmapEdge extends Model
{
    protected $fillable = ['mindmap_id', 'from_node_id', 'to_node_id', 'label'];
}
