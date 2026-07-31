<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MindmapCanvasRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanvas_render_untuk_yang_berhak(): void
    {
        $owner = User::create([
            'name' => 'o', 'fullname' => 'o', 'username' => 'o', 'email' => 'o@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
        $map = Mindmap::create(['title' => 'Papan Uji', 'created_by' => $owner->id]);

        $this->actingAs($owner)->get(route('mindmaps.show', $map))
            ->assertOk()
            ->assertSee('Papan Uji')
            ->assertSee('id="mmCanvas"', false)   // root kanvas
            ->assertSee('id="mmWorld"', false)    // layer pan/zoom
            ->assertSee('api(R.state', false);    // JS mewirekan route state (poll)
    }
}
