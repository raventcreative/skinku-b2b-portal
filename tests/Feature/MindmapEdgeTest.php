<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\MindmapEdge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapEdgeTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create([
            'name' => 'o', 'fullname' => 'o', 'username' => 'o', 'email' => 'o@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_edge_crud_dan_validasi_papan(): void
    {
        $owner = $this->owner();
        $map = Mindmap::create(['title' => 'P', 'created_by' => $owner->id]);
        $a = $map->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'created_by' => $owner->id]);
        $b = $map->nodes()->create(['type' => 'sticky', 'x' => 300, 'y' => 0, 'created_by' => $owner->id]);

        // Node dari papan lain — tak boleh disambung.
        $lain = Mindmap::create(['title' => 'Q', 'created_by' => $owner->id]);
        $asing = $lain->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'created_by' => $owner->id]);

        $res = $this->actingAs($owner)->postJson(route('mindmaps.edges.store', $map), [
            'from_node_id' => $a->id, 'to_node_id' => $b->id,
        ])->assertOk()->assertJson(['ok' => true]);
        $edgeId = $res->json('edge.id');

        // Sambung ke node papan lain → 422.
        $this->actingAs($owner)->postJson(route('mindmaps.edges.store', $map), [
            'from_node_id' => $a->id, 'to_node_id' => $asing->id,
        ])->assertStatus(422);

        // Beri label.
        $this->actingAs($owner)->patchJson(route('mindmaps.edges.update', [$map, $edgeId]), ['label' => 'lalu'])
            ->assertOk();
        $this->assertSame('lalu', MindmapEdge::find($edgeId)->label);

        // Hapus.
        $this->actingAs($owner)->deleteJson(route('mindmaps.edges.destroy', [$map, $edgeId]))->assertOk();
        $this->assertNull(MindmapEdge::find($edgeId));
    }
}
