<?php

namespace Tests\Feature;

use App\Models\Board;
use App\Models\BoardCard;
use App\Models\BoardColumn;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Tools\BuatKartuKanbanTool;
use App\Services\Ai\Tools\RingkasDashboardTool;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Alat asisten: ringkas_dashboard (baca) & buat_kartu_kanban (tulis).
 */
class AiToolsTest extends TestCase
{
    use RefreshDatabase;

    private function super(): User
    {
        return User::create([
            'name' => 'sa', 'fullname' => 'SA', 'username' => 'sa', 'email' => 'sa@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function tool(): RingkasDashboardTool
    {
        return new RingkasDashboardTool(app(ReportService::class));
    }

    public function test_db_kosong_balikin_struktur_dan_nol(): void
    {
        $out = $this->tool()->run([], $this->super());

        // Struktur lengkap + aman saat data kosong (bukan crash).
        foreach (['bulan', 'penjualan_total', 'jumlah_po', 'po_selesai', 'mitra_aktif', 'stok_hq_unit', 'distribusi_status_po', 'stok_menipis'] as $k) {
            $this->assertArrayHasKey($k, $out);
        }
        $this->assertSame(0, $out['jumlah_po']);
        $this->assertSame(0, $out['stok_menipis']['jumlah']);
        $this->assertNotEmpty($out['bulan']);
    }

    public function test_stok_menipis_terdeteksi(): void
    {
        $sa = $this->super();
        $p = Product::create(['name' => 'Serum X', 'sku' => 'SRX-1', 'status' => Product::STATUS_ACTIVE, 'hq_stock' => 5]);
        Inventory::create(['user_id' => $sa->id, 'product_id' => $p->id, 'quantity' => 2, 'minimum_stock' => 10]);

        $out = $this->tool()->run([], $sa);

        $this->assertSame(1, $out['stok_menipis']['jumlah']);
        $this->assertSame('Serum X', $out['stok_menipis']['contoh'][0]['produk']);
        $this->assertSame(2, $out['stok_menipis']['contoh'][0]['sisa']);
    }

    public function test_bulan_tertentu_diterima(): void
    {
        $out = $this->tool()->run(['bulan' => '2026-06'], $this->super());
        $this->assertStringContainsString('2026', $out['bulan']);
    }

    public function test_bukan_alat_tulis(): void
    {
        $this->assertFalse($this->tool()->isWrite());
        $this->assertSame('ringkas_dashboard', $this->tool()->name());
    }

    /** Penerima bukan user portal → kartu TETAP dibuat di kolomnya (tanpa assignee). */
    public function test_kartu_penerima_bukan_user_tetap_dibuat(): void
    {
        $sa = $this->super();
        $board = Board::create(['name' => 'Task SKINKU Management', 'created_by' => $sa->id]);
        $col = BoardColumn::create(['board_id' => $board->id, 'name' => 'To Do List Billy', 'position' => 0]);
        $tool = new BuatKartuKanbanTool;
        $args = ['papan' => 'Task SKINKU Management', 'kolom' => 'To Do List Billy', 'judul' => 'Buat konten', 'penerima' => 'Billy'];

        $this->assertNull($tool->validate($args, $sa));   // TIDAK diblokir
        $res = $tool->run($args, $sa);

        $this->assertTrue($res['ok']);
        $card = BoardCard::first();
        $this->assertSame('Buat konten', $card->title);
        $this->assertSame($col->id, $card->column_id);
        $this->assertNull($card->assignee_user_id);       // tanpa penanggung jawab
        $this->assertStringContainsString('tanpa penanggung jawab', $res['pesan']);
    }

    /** "Tiar" cocok sebagian dengan user "Bahtiar Tiar" → di-assign. */
    public function test_kartu_penerima_cocok_sebagian(): void
    {
        $sa = $this->super();
        $tiar = User::create([
            'name' => 'Bahtiar', 'fullname' => 'Bahtiar Tiar', 'username' => 'kol_tiar', 'email' => 'tiar@skinku.test',
            'password' => Hash::make('secret123'), 'role' => 'kol_specialist', 'status' => User::STATUS_ACTIVE,
        ]);
        $board = Board::create(['name' => 'Papan', 'created_by' => $sa->id]);
        BoardColumn::create(['board_id' => $board->id, 'name' => 'To Do', 'position' => 0]);
        $tool = new BuatKartuKanbanTool;
        $args = ['papan' => 'Papan', 'kolom' => 'To Do', 'judul' => 'Tugas', 'penerima' => 'Tiar'];

        $this->assertNull($tool->validate($args, $sa));
        $tool->run($args, $sa);

        $this->assertSame($tiar->id, BoardCard::first()->assignee_user_id);
    }

    /** Papan/kolom tak ada TETAP diblokir (harus benar). */
    public function test_kartu_kolom_salah_tetap_diblokir(): void
    {
        $sa = $this->super();
        Board::create(['name' => 'Papan', 'created_by' => $sa->id]);
        $tool = new BuatKartuKanbanTool;

        $this->assertNotNull($tool->validate(['papan' => 'Papan', 'kolom' => 'Kolom Hantu', 'judul' => 'X'], $sa));
        $this->assertNotNull($tool->validate(['papan' => 'Papan Hantu', 'kolom' => 'To Do', 'judul' => 'X'], $sa));
    }
}
