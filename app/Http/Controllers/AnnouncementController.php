<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImageService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kelola Pengumuman dashboard PER ROLE. Di balik permission manage_announcements
 * (default super admin; bisa diberikan ke role lain lewat Manajemen Hak Akses).
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
        $role = $request->query('role');
        if (! in_array($role, $roles, true)) {
            $role = $roles[0] ?? null;
        }

        return view('announcements.manage', [
            'roles' => $roles,
            'role' => $role,
            'announcement' => $role ? Announcement::firstOrNew(['role' => $role]) : new Announcement,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in($this->roles())],
            'note_title' => ['nullable', 'string', 'max:150'],
            'note_body' => ['nullable', 'string', 'max:5000'],
            'banner_link' => ['nullable', 'url', 'max:500'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:12288'],
        ]);

        $announcement = Announcement::firstOrNew(['role' => $data['role']]);
        $announcement->fill([
            'note_enabled' => $request->boolean('note_enabled'),
            'note_title' => $data['note_title'] ?? null,
            'note_body' => $data['note_body'] ?? null,
            'banner_enabled' => $request->boolean('banner_enabled'),
            'banner_link' => $data['banner_link'] ?? null,
        ]);
        $announcement->save();

        // Hapus banner lama bila diminta ATAU akan diganti gambar baru.
        if ($request->boolean('remove_banner') || $request->hasFile('banner')) {
            $announcement->filesIn(Announcement::BANNER)->get()->each->delete();
        }
        if ($request->hasFile('banner')) {
            $this->images->attach($announcement, $request->file('banner'), Announcement::BANNER, 1600);
        }

        AuditService::log(
            action: 'update_announcement',
            targetType: 'announcement',
            targetId: $announcement->id,
            after: [
                'role' => $announcement->role,
                'note' => $announcement->note_enabled,
                'banner' => $announcement->banner_enabled,
            ],
        );

        return redirect()->route('announcements.manage', ['role' => $announcement->role])
            ->with('status', "Pengumuman untuk role \"{$announcement->role}\" disimpan.");
    }
}
