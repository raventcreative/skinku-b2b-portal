<?php

namespace Tests\Feature;

use App\Models\Mindmap;
use App\Models\MindmapEdge;
use App\Models\User;
use App\Services\Ai\Tools\BuatMindmapTool;
use App\Services\Ai\Tools\RingkasMindmapTool;
use App\Services\Ai\Tools\TambahMindmapTool;
use App\Services\Ai\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat asisten Mindmaps: ringkas_mindmap (baca, per-papan), buat_mindmap &
 * tambah_mindmap (tulis). Akses ketat per izin + per papan.
 */
class MindmapAiToolTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $u): User
    {
        return User::create([
            'name' => $u, 'fullname' => $u, 'username' => $u, 'email' => $u.'@skinku.test',
            'password' => Hash::make('secret123'), 'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    public function test_alat_hanya_muncul_untuk_yang_punya_izin(): void
    {
        $staf = $this->user(User::ROLE_ADMIN, 'adm');
        $mitra = $this->user(User::ROLE_DISTRIBUTOR, 'dist');

        $stafTools = collect(app(ToolRegistry::class)->forUser($staf))->map->name();
        foreach (['ringkas_mindmap', 'buat_mindmap', 'tambah_mindmap'] as $t) {
            $this->assertContains($t, $stafTools, "staf harus punya alat {$t}");
        }

        $mitraTools = collect(app(ToolRegistry::class)->forUser($mitra))->map->name();
        foreach (['ringkas_mindmap', 'buat_mindmap', 'tambah_mindmap'] as $t) {
            $this->assertNotContains($t, $mitraTools, "mitra TIDAK boleh punya alat {$t}");
        }
    }

    public function test_ringkas_hanya_papan_yang_boleh_diakses(): void
    {
        $a = $this->user(User::ROLE_ADMIN, 'a');
        $b = $this->user(User::ROLE_ADMIN, 'b');
        $milikA = Mindmap::create(['title' => 'Punya A', 'created_by' => $a->id]);
        $diikutiA = Mindmap::create(['title' => 'Diikuti A', 'created_by' => $b->id]);
        $diikutiA->members()->create(['user_id' => $a->id, 'can_edit' => false]);
        Mindmap::create(['title' => 'Rahasia B', 'created_by' => $b->id]); // A bukan anggota

        // Isi struktur ke Punya A.
        $n1 = $milikA->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'text' => 'Induk', 'created_by' => $a->id]);
        $n2 = $milikA->nodes()->create(['type' => 'sticky', 'x' => 300, 'y' => 0, 'text' => 'Anak', 'created_by' => $a->id]);
        $milikA->edges()->create(['from_node_id' => $n1->id, 'to_node_id' => $n2->id]);

        $daftar = (new RingkasMindmapTool)->run([], $a);
        $judul = collect($daftar['papan'])->pluck('judul');
        $this->assertTrue($judul->contains('Punya A'));
        $this->assertTrue($judul->contains('Diikuti A'));
        $this->assertFalse($judul->contains('Rahasia B'), 'papan orang lain tak boleh bocor');

        $struktur = (new RingkasMindmapTool)->run(['papan' => 'Punya A'], $a);
        $this->assertSame('Punya A', $struktur['papan']);
        $this->assertCount(2, $struktur['sticky']);
        $this->assertSame('Induk', $struktur['garis'][0]['dari']);
        $this->assertSame('Anak', $struktur['garis'][0]['ke']);

        // Papan rahasia B tak bisa dibaca A.
        $this->assertArrayHasKey('catatan', (new RingkasMindmapTool)->run(['papan' => 'Rahasia B'], $a));
    }

    public function test_buat_papan_bercabang_milik_user(): void
    {
        $a = $this->user(User::ROLE_ADMIN, 'a');
        $tool = new BuatMindmapTool;

        $this->assertTrue($tool->isWrite());
        $this->assertNotNull($tool->validate(['judul' => 'X', 'node' => []], $a));       // node kosong ditolak
        $this->assertNotNull($tool->validate(['node' => [['teks' => 'x']]], $a));         // tanpa judul ditolak
        $this->assertStringContainsString('Struktur', $tool->previewText(['judul' => 'Struktur', 'node' => [['teks' => 'R']]], $a));

        $out = $tool->run([
            'judul' => 'Struktur Channel',
            'node' => [['teks' => 'Channel'], ['teks' => 'TikTok', 'induk' => 0], ['teks' => 'Shopee', 'induk' => 0]],
        ], $a);
        $this->assertTrue($out['ok']);

        $map = Mindmap::where('title', 'Struktur Channel')->firstOrFail();
        $this->assertSame($a->id, $map->created_by);
        $this->assertSame(3, $map->nodes()->count());
        $this->assertSame(2, $map->edges()->count());   // TikTok & Shopee bercabang dari Channel
        $root = $map->nodes()->where('text', 'Channel')->firstOrFail();
        $this->assertSame(2, MindmapEdge::where('from_node_id', $root->id)->count());
    }

    public function test_tambah_butuh_izin_edit_dan_bisa_menyambung(): void
    {
        $owner = $this->user(User::ROLE_ADMIN, 'own');
        $viewer = $this->user(User::ROLE_ADMIN, 'vie');   // punya mindmap.view, tapi anggota lihat-saja
        $map = Mindmap::create(['title' => 'Papan Tim', 'created_by' => $owner->id]);
        $map->members()->create(['user_id' => $viewer->id, 'can_edit' => false]);
        $akar = $map->nodes()->create(['type' => 'sticky', 'x' => 0, 'y' => 0, 'text' => 'Akar', 'created_by' => $owner->id]);

        $tool = new TambahMindmapTool;

        // Viewer (tak boleh edit) → ditolak di validate.
        $this->assertNotNull($tool->validate(['papan' => 'Papan Tim', 'node' => [['teks' => 'x']]], $viewer));

        // Owner boleh + menyambung dari sticky lama "Akar".
        $out = $tool->run([
            'papan' => 'Papan Tim',
            'node' => [['teks' => 'Cabang'], ['teks' => 'Sub', 'induk' => 0]],
            'sambung_dari' => 'Akar',
        ], $owner);
        $this->assertTrue($out['ok']);
        $this->assertSame(3, $map->nodes()->count());   // Akar + Cabang + Sub

        $cabang = $map->nodes()->where('text', 'Cabang')->firstOrFail();
        // Garis: Akar -> Cabang (sambung_dari) dan Cabang -> Sub (induk).
        $this->assertSame(1, MindmapEdge::where('from_node_id', $akar->id)->where('to_node_id', $cabang->id)->count());
        $this->assertSame(1, MindmapEdge::where('from_node_id', $cabang->id)->count());
    }
}
