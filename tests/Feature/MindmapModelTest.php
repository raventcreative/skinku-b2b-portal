<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\MindmapMember;
use App\Models\MindmapNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapModelTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_akses_owner_anggota_dan_cascade(): void
    {
        $owner = $this->user(User::ROLE_ADMIN, 'owner');
        $editor = $this->user(User::ROLE_ADMIN, 'editor');
        $viewer = $this->user(User::ROLE_ADMIN, 'viewer');
        $orangLain = $this->user(User::ROLE_ADMIN, 'lain');

        $map = Mindmap::create(['title' => 'Papan', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $editor->id, 'can_edit' => true]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);

        // Owner: semua.
        $this->assertTrue($map->isOwner($owner));
        $this->assertTrue($map->canEdit($owner));
        // Editor: lihat + edit.
        $this->assertFalse($map->isOwner($editor));
        $this->assertTrue($map->canEdit($editor));
        // Viewer: lihat saja.
        $this->assertTrue($map->canView($viewer));
        $this->assertFalse($map->canEdit($viewer));
        // Orang lain: tidak bisa.
        $this->assertFalse($map->canView($orangLain));

        // Hapus node → garisnya ikut (cascade FK).
        $a = $map->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'created_by' => $owner->id]);
        $b = $map->nodes()->create(['type' => 'sticky', 'x' => 300, 'y' => 0, 'created_by' => $owner->id]);
        $map->edges()->create(['from_node_id' => $a->id, 'to_node_id' => $b->id]);
        $this->assertSame(1, $map->edges()->count());
        $a->delete();
        $this->assertSame(0, $map->edges()->count());

        // Hapus papan → node & anggota ikut.
        $map->delete();
        $this->assertSame(0, MindmapNode::count());
        $this->assertSame(0, MindmapMember::count());
    }
}
