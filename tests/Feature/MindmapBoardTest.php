<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapBoardTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_akses_buka_dan_kelola_papan(): void
    {
        $owner = $this->user('own');
        $viewer = $this->user('vie');
        $orang_lain = $this->user('lain');
        $map = Mindmap::create(['title' => 'Papan', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);

        // Buka: owner & anggota bisa, orang lain 403.
        $this->actingAs($owner)->get(route('mindmaps.show', $map))->assertOk();
        $this->actingAs($viewer)->get(route('mindmaps.show', $map))->assertOk();
        $this->actingAs($orang_lain)->get(route('mindmaps.show', $map))->assertForbidden();

        // Rename: owner boleh, anggota tidak.
        $this->actingAs($viewer)->patch(route('mindmaps.update', $map), ['title' => 'X'])->assertForbidden();
        $this->actingAs($owner)->patch(route('mindmaps.update', $map), ['title' => 'Baru'])->assertRedirect();
        $this->assertSame('Baru', $map->fresh()->title);

        // Tambah anggota: owner boleh.
        $baru = $this->user('anggotabaru');
        $this->actingAs($owner)->post(route('mindmaps.members.store', $map), ['user_id' => $baru->id, 'can_edit' => true])->assertRedirect();
        $this->assertTrue($map->canEdit($baru->fresh()));

        // Hapus anggota: owner boleh.
        $this->actingAs($owner)->delete(route('mindmaps.members.destroy', [$map, $baru]))->assertRedirect();
        $this->assertFalse($map->fresh()->canView($baru));

        // Hapus papan: anggota tak boleh, owner boleh.
        $this->actingAs($viewer)->delete(route('mindmaps.destroy', $map))->assertForbidden();
        $this->actingAs($owner)->delete(route('mindmaps.destroy', $map))->assertRedirect();
        $this->assertNull(Mindmap::find($map->id));
    }
}
