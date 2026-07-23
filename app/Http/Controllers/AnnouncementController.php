<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\CommunityLink;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImageService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kelola Pengumuman dashboard. Satu layar: daftar SEMUA pengumuman (bisa
 * difilter per role) + form tambah/edit. Banyak pengumuman per role diizinkan.
 * Di balik permission manage_announcements.
 */
class AnnouncementController extends Controller
{
    public function __construct(private ImageService $images) {}

    /** Role yang bisa diberi pengumuman — semua kecuali super_admin (dia yang mengatur). */
    private function roles(): array
    {
        return array_values(array_filter(Permissions::roleNames(), fn ($r) => $r !== User::ROLE_SUPER_ADMIN));
    }

    public function manage(Request $request)
    {
        $roles = $this->roles();
        $filter = in_array($request->query('role'), $roles, true) ? $request->query('role') : null;

        $items = Announcement::query()
            ->when($filter, fn ($q, $r) => $q->where('role', $r))
            ->orderBy('role')->orderBy('sort_order')->orderBy('id')
            ->get();

        // Item yang sedang diedit (?item=id), atau form kosong untuk tambah baru.
        $editing = $request->query('item') ? Announcement::find($request->query('item')) : null;
        $editing ??= new Announcement(['role' => $filter ?: ($roles[0] ?? null)]);

        // Komunitas WA per role (satu baris per role) — panel di halaman yang sama.
        $communities = CommunityLink::all()->keyBy('role');

        return view('announcements.manage', [
            'roles' => $roles,
            'filter' => $filter,
            'items' => $items,
            'editing' => $editing,
            'communities' => $communities,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:announcements,id'],
            'role' => ['required', Rule::in($this->roles())],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'note_title' => ['nullable', 'string', 'max:150'],
            'note_body' => ['nullable', 'string', 'max:5000'],
            'note_link' => ['nullable', 'url', 'max:255'],
            'note_link_label' => ['nullable', 'string', 'max:60'],
            'banner_link' => ['nullable', 'url', 'max:500'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:12288'],
        ]);

        $announcement = ! empty($data['id']) ? Announcement::findOrFail($data['id']) : new Announcement;
        $announcement->fill([
            'role' => $data['role'],
            'sort_order' => $data['sort_order'] ?? 0,
            'note_enabled' => $request->boolean('note_enabled'),
            'note_title' => $data['note_title'] ?? null,
            'note_body' => $data['note_body'] ?? null,
            'note_link' => $data['note_link'] ?? null,
            'note_link_label' => $data['note_link_label'] ?? null,
            'banner_enabled' => $request->boolean('banner_enabled'),
            'banner_link' => $data['banner_link'] ?? null,
        ]);
        $announcement->save();

        if ($request->boolean('remove_banner') || $request->hasFile('banner')) {
            $announcement->filesIn(Announcement::BANNER)->get()->each->delete();
        }
        if ($request->hasFile('banner')) {
            $this->images->attach($announcement, $request->file('banner'), Announcement::BANNER, 1600);
        }

        AuditService::log(
            action: 'save_announcement',
            targetType: 'announcement',
            targetId: $announcement->id,
            after: ['role' => $announcement->role, 'note' => $announcement->note_enabled, 'banner' => $announcement->banner_enabled],
        );

        return redirect()->route('announcements.manage', array_filter(['role' => $announcement->role]))
            ->with('status', 'Pengumuman disimpan.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $role = $announcement->role;
        $id = $announcement->id;
        $announcement->files()->get()->each->delete();   // hapus berkas banner juga
        $announcement->delete();

        AuditService::log(action: 'delete_announcement', targetType: 'announcement', targetId: $id, after: ['role' => $role]);

        return redirect()->route('announcements.manage', ['role' => $role])->with('status', 'Pengumuman dihapus.');
    }

    /**
     * Simpan link Komunitas WA untuk satu role (upsert per role). Link wajib bila
     * diaktifkan; gambar QR opsional (di-resize via ImageService). Tanpa "hapus
     * baris" — cukup nonaktifkan bila tak dipakai.
     */
    public function saveCommunity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in($this->roles())],
            'label' => ['nullable', 'string', 'max:60'],
            'link' => ['nullable', 'url', 'max:500', 'required_if:enabled,1'],
            'qr' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ], [
            'link.required_if' => 'Link grup WA wajib diisi bila komunitas diaktifkan.',
        ]);

        $community = CommunityLink::firstOrNew(['role' => $data['role']]);
        $community->fill([
            'enabled' => $request->boolean('enabled'),
            'label' => $data['label'] ?? null,
            'link' => $data['link'] ?? null,
        ]);
        $community->save();

        if ($request->boolean('remove_qr') || $request->hasFile('qr')) {
            $community->filesIn(CommunityLink::QR)->get()->each->delete();
        }
        if ($request->hasFile('qr')) {
            $this->images->attach($community, $request->file('qr'), CommunityLink::QR, 800);
        }

        AuditService::log(
            action: 'save_community_link',
            targetType: 'community_link',
            targetId: $community->id,
            after: ['role' => $community->role, 'enabled' => $community->enabled],
        );

        return redirect()->route('announcements.manage')->withFragment('komunitas')
            ->with('status', 'Komunitas WA disimpan.');
    }
}
