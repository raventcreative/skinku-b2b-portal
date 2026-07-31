<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\MindmapNode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapNodeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_node_crud_dan_gerbang_edit(): void
    {
        $owner = $this->user('own');
        $viewer = $this->user('vie');
        $map = Mindmap::create(['title' => 'P', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);

        // Buat node (owner).
        $res = $this->actingAs($owner)->postJson(route('mindmaps.nodes.store', $map), [
            'type' => 'sticky', 'x' => 40, 'y' => 60, 'text' => 'Ide',
        ])->assertOk()->assertJson(['ok' => true]);
        $nodeId = $res->json('node.id');
        $this->assertNotNull($nodeId);

        // Viewer tak boleh buat/edit.
        $this->actingAs($viewer)->postJson(route('mindmaps.nodes.store', $map), ['type' => 'sticky', 'x' => 0, 'y' => 0])
            ->assertForbidden();

        // Update posisi.
        $this->actingAs($owner)->patchJson(route('mindmaps.nodes.update', [$map, $nodeId]), ['x' => 200, 'y' => 120])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(200.0, (float) MindmapNode::find($nodeId)->x);

        // State: viewer boleh baca.
        $this->actingAs($viewer)->getJson(route('mindmaps.state', $map))
            ->assertOk()->assertJsonStructure(['nodes', 'edges', 'updated_at'])
            ->assertJsonFragment(['id' => $nodeId]);

        // Hapus node.
        $this->actingAs($owner)->deleteJson(route('mindmaps.nodes.destroy', [$map, $nodeId]))
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertNull(MindmapNode::find($nodeId));

        // Non-anggota tak bisa baca state.
        $this->actingAs($this->user('lain'))->getJson(route('mindmaps.state', $map))->assertForbidden();
    }
}
