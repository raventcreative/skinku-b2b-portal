<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_mitra_terblokir_staf_bisa_dan_buat_papan(): void
    {
        // Mitra terblokir (internal + izin).
        $this->actingAs($this->user(User::ROLE_DISTRIBUTOR, 'dist'))
            ->get(route('mindmaps.index'))->assertForbidden();

        // Staf bisa buka daftar + buat papan.
        $admin = $this->user(User::ROLE_ADMIN, 'adm');
        $this->actingAs($admin)->get(route('mindmaps.index'))->assertOk();
        $this->actingAs($admin)->post(route('mindmaps.store'), ['title' => 'Rencana Q4'])->assertRedirect();

        $map = Mindmap::firstOrFail();
        $this->assertSame('Rencana Q4', $map->title);
        $this->assertSame($admin->id, $map->created_by);
    }

    public function test_daftar_hanya_papan_milik_atau_diikuti(): void
    {
        $a = $this->user(User::ROLE_ADMIN, 'a');
        $b = $this->user(User::ROLE_ADMIN, 'b');
        $milikA = Mindmap::create(['title' => 'Punya A', 'created_by' => $a->id]);
        $milikB = Mindmap::create(['title' => 'Punya B', 'created_by' => $b->id]);
        $milikB->members()->create(['user_id' => $a->id, 'can_edit' => false]); // A diundang ke B

        $this->actingAs($a)->get(route('mindmaps.index'))
            ->assertOk()->assertSee('Punya A')->assertSee('Punya B');

        $c = $this->user(User::ROLE_ADMIN, 'c');
        $this->actingAs($c)->get(route('mindmaps.index'))
            ->assertOk()->assertDontSee('Punya A')->assertDontSee('Punya B');
    }
}
