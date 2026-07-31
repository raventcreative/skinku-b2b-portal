<?php

namespace App\Http\Controllers;

use App\Models\Mindmap;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MindmapController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();
        $maps = Mindmap::query()
            ->where('created_by', $user->id)
            ->orWhereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->with('creator:id,fullname,name')
            ->orderByDesc('updated_at')
            ->get();

        return view('mindmaps.index', ['maps' => $maps]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $map = Mindmap::create(['title' => $data['title'], 'created_by' => $request->user()->id]);

        AuditService::log(action: 'create_mindmap', targetType: 'mindmap', targetId: $map->id,
            after: ['judul' => $map->title]);

        return redirect()->route('mindmaps.show', $map);
    }
}
