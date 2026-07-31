<?php

namespace App\Http\Controllers;

use App\Models\Mindmap;
use App\Models\MindmapEdge;
use App\Models\MindmapMember;
use App\Models\MindmapNode;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function show(Mindmap $mindmap): View
    {
        abort_unless($mindmap->canView(auth()->user()), 403, 'Tidak punya akses ke papan ini.');
        $mindmap->load(['members.user:id,fullname,name', 'creator:id,fullname,name']);

        return view('mindmaps.show', [
            'map' => $mindmap,
            'canEdit' => $mindmap->canEdit(auth()->user()),
            'isOwner' => $mindmap->isOwner(auth()->user()),
            'staffOptions' => $this->staffOptions(),
        ]);
    }

    public function update(Request $request, Mindmap $mindmap): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa mengubah.');
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);
        $mindmap->update(['title' => $data['title']]);

        return back()->with('status', 'Judul papan diperbarui.');
    }

    public function destroy(Request $request, Mindmap $mindmap): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa menghapus.');
        $mindmap->delete();
        AuditService::log(action: 'delete_mindmap', targetType: 'mindmap', targetId: $mindmap->id);

        return redirect()->route('mindmaps.index')->with('status', 'Papan dihapus.');
    }

    public function addMember(Request $request, Mindmap $mindmap): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa mengatur anggota.');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'can_edit' => ['sometimes', 'boolean'],
        ]);
        if ($data['user_id'] === $mindmap->created_by) {
            return back(); // owner sudah otomatis akses penuh
        }
        MindmapMember::updateOrCreate(
            ['mindmap_id' => $mindmap->id, 'user_id' => $data['user_id']],
            ['can_edit' => (bool) ($data['can_edit'] ?? true)],
        );

        return back()->with('status', 'Anggota papan diperbarui.');
    }

    public function removeMember(Request $request, Mindmap $mindmap, User $user): RedirectResponse
    {
        abort_unless($mindmap->isOwner($request->user()), 403, 'Hanya pemilik papan yang bisa mengatur anggota.');
        $mindmap->members()->where('user_id', $user->id)->delete();

        return back()->with('status', 'Anggota dikeluarkan.');
    }

    public function state(Mindmap $mindmap): JsonResponse
    {
        abort_unless($mindmap->canView(auth()->user()), 403);

        return response()->json([
            'nodes' => $mindmap->nodes()->get(['id', 'type', 'x', 'y', 'width', 'height', 'text', 'color']),
            'edges' => $mindmap->edges()->get(['id', 'from_node_id', 'to_node_id', 'label']),
            'updated_at' => $mindmap->updated_at?->toIso8601String(),
        ]);
    }

    public function storeNode(Request $request, Mindmap $mindmap): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['sticky', 'text'])],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'width' => ['sometimes', 'numeric', 'min:60', 'max:2000'],
            'height' => ['sometimes', 'numeric', 'min:40', 'max:2000'],
            'text' => ['nullable', 'string', 'max:5000'],
            'color' => ['sometimes', Rule::in(Mindmap::COLORS)],
        ]);
        $node = $mindmap->nodes()->create($data + ['created_by' => $request->user()->id]);
        $mindmap->touch();

        return response()->json(['ok' => true, 'node' => $node->only(['id', 'type', 'x', 'y', 'width', 'height', 'text', 'color'])]);
    }

    public function updateNode(Request $request, Mindmap $mindmap, MindmapNode $node): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($node->mindmap_id === $mindmap->id, 404);
        $data = $request->validate([
            'x' => ['sometimes', 'numeric'],
            'y' => ['sometimes', 'numeric'],
            'width' => ['sometimes', 'numeric', 'min:60', 'max:2000'],
            'height' => ['sometimes', 'numeric', 'min:40', 'max:2000'],
            'text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'color' => ['sometimes', Rule::in(Mindmap::COLORS)],
        ]);
        $node->update($data);
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }

    public function destroyNode(Request $request, Mindmap $mindmap, MindmapNode $node): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($node->mindmap_id === $mindmap->id, 404);
        $node->delete(); // garis terkait ikut (cascade FK)
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }

    public function storeEdge(Request $request, Mindmap $mindmap): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        $data = $request->validate([
            'from_node_id' => ['required', 'integer'],
            'to_node_id' => ['required', 'integer', 'different:from_node_id'],
            'label' => ['nullable', 'string', 'max:255'],
        ]);
        // Kedua node WAJIB milik papan ini.
        $milik = $mindmap->nodes()->whereIn('id', [$data['from_node_id'], $data['to_node_id']])->count();
        if ($milik !== 2) {
            return response()->json(['ok' => false, 'error' => 'Node bukan bagian papan ini.'], 422);
        }
        $edge = $mindmap->edges()->create($data);
        $mindmap->touch();

        return response()->json(['ok' => true, 'edge' => $edge->only(['id', 'from_node_id', 'to_node_id', 'label'])]);
    }

    public function updateEdge(Request $request, Mindmap $mindmap, MindmapEdge $edge): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($edge->mindmap_id === $mindmap->id, 404);
        $data = $request->validate(['label' => ['nullable', 'string', 'max:255']]);
        $edge->update($data);
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }

    public function destroyEdge(Request $request, Mindmap $mindmap, MindmapEdge $edge): JsonResponse
    {
        abort_unless($mindmap->canEdit($request->user()), 403);
        abort_unless($edge->mindmap_id === $mindmap->id, 404);
        $edge->delete();
        $mindmap->touch();

        return response()->json(['ok' => true]);
    }

    /** Staf internal aktif (kandidat anggota papan). */
    private function staffOptions()
    {
        return User::query()
            ->whereIn('role', [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN, User::ROLE_GUDANG])
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('fullname')
            ->get(['id', 'fullname', 'name']);
    }
}
