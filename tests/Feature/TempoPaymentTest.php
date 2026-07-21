<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tempo/cicilan PO — case nyata: customer minta bayar bertahap tapi barang
 * tetap diproses. Kuncinya: tempo = pintu TERKONTROL (per-PO, oleh admin,
 * tercatat), bukan pelonggaran gerbang pembayaran untuk semua.
 */
class TempoPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'A', 'fullname' => 'Admin', 'username' => "tpadm{$n}", 'email' => "tp{$n}@skinku.test",
            'password' => Hash::make('secret123'), 'role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function po(float $total = 2_980_000): PurchaseOrder
    {
        static $n = 0;
        $n++;

        return PurchaseOrder::create([
            'po_number' => "SKN-PO-TEMPO-{$n}", 'created_by' => 1, 'user_id' => 1,
            'company_name' => 'SKINKU BALI (erin)', 'user_role' => 'distributor',
            'status' => PurchaseOrder::STATUS_APPROVED, 'total_amount' => $total,
            'payment_status' => PurchaseOrder::PAYMENT_UNPAID,
        ]);
    }

    /** Gerbang untuk PO BIASA tetap terkunci — tempo tidak melonggarkannya untuk semua. */
    public function test_po_biasa_tetap_terkunci_po_tempo_boleh_diproses(): void
    {
        $svc = app(PurchaseOrderService::class);

        $biasa = $this->po();
        // Jangan pakai $this->fail() di dalam try yang menangkap RuntimeException:
        // AssertionFailedError PHPUnit ADALAH turunan RuntimeException, jadi
        // fail()-nya ikut tertangkap dan pesannya (yang memuat "belum lunas")
        // membuat assertion lulus walau gate-nya rusak — percaya diri palsu.
        $ditolak = false;
        try {
            $svc->updateStatus($biasa, PurchaseOrder::STATUS_PROCESSING);
        } catch (\RuntimeException $e) {
            $ditolak = true;
            $this->assertStringContainsString('belum lunas', $e->getMessage());
        }
        $this->assertTrue($ditolak, 'PO biasa yang belum dibayar harus ditolak diproses.');
        // Bukti kedua yang tak bisa ditipu pesan: status TIDAK bergeser.
        $this->assertSame(PurchaseOrder::STATUS_APPROVED, $biasa->fresh()->status);

        $tempo = $this->po();
        $svc->setTempo($tempo, true, 'cicil 3x sesuai kesepakatan WA', '2026-08-31');

        $svc->updateStatus($tempo->fresh(), PurchaseOrder::STATUS_PROCESSING);
        $this->assertSame(PurchaseOrder::STATUS_PROCESSING, $tempo->fresh()->status);
        // Status bayar TIDAK berubah — tetap kelihatan belum lunas.
        $this->assertSame(PurchaseOrder::PAYMENT_UNPAID, $tempo->fresh()->payment_status);
        $this->assertNotNull(AuditLog::where('action', 'set_po_tempo')->first());
    }

    public function test_cicilan_tercatat_sisa_benar_dan_lunas_otomatis(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();
        $po = $this->po(2_980_000);
        $svc->setTempo($po, true, null, null);

        $svc->recordPayment($po->fresh(), 1_000_000, '2026-07-21', 'cicilan 1', $admin->id);
        $this->assertEqualsWithDelta(1_980_000, $po->fresh()->remaining(), 0.01);
        $this->assertSame(PurchaseOrder::PAYMENT_UNPAID, $po->fresh()->payment_status);

        $svc->recordPayment($po->fresh(), 1_500_000, '2026-07-25', 'cicilan 2', $admin->id);
        $this->assertEqualsWithDelta(480_000, $po->fresh()->remaining(), 0.01);

        // Cicilan terakhir menutup sisa → LUNAS otomatis.
        $svc->recordPayment($po->fresh(), 480_000, '2026-07-30', 'pelunasan', $admin->id);
        $this->assertSame(PurchaseOrder::PAYMENT_PAID, $po->fresh()->payment_status);
        $this->assertEqualsWithDelta(0, $po->fresh()->remaining(), 0.01);
        $this->assertSame(3, $po->payments()->count());
        $this->assertSame(3, AuditLog::where('action', 'record_po_payment')->count());
    }

    /** Bayar melebihi sisa = hampir pasti salah ketik → DITOLAK, piutang tak kacau. */
    public function test_cicilan_melebihi_sisa_ditolak(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();
        $po = $this->po(1_000_000);
        $svc->setTempo($po, true, null, null);
        $svc->recordPayment($po->fresh(), 800_000, '2026-07-21', null, $admin->id);

        try {
            $svc->recordPayment($po->fresh(), 300_000, '2026-07-22', null, $admin->id);
            $this->fail('Cicilan melebihi sisa harusnya ditolak.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('melebihi sisa', $e->getMessage());
        }

        $this->assertSame(1, $po->payments()->count());
        $this->assertSame(PurchaseOrder::PAYMENT_UNPAID, $po->fresh()->payment_status);
    }

    public function test_detail_po_menampilkan_tempo_sisa_dan_form_cicilan(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();
        $po = $this->po(2_980_000);
        $svc->setTempo($po, true, 'cicil 3x', '2026-08-31');
        $svc->recordPayment($po->fresh(), 1_000_000, '2026-07-21', 'cicilan 1', $admin->id);

        $html = $this->actingAs($admin)->get(route('purchase-orders.show', $po))->assertOk()->getContent();

        $this->assertStringContainsString('TEMPO', $html);
        $this->assertStringContainsString('Sisa: Rp 1.980.000', $html);
        $this->assertStringContainsString('cicilan 1', $html);
        $this->assertStringContainsString('Catat Cicilan Masuk', $html);
        $this->assertStringContainsString('Belum', $html);   // badge belum lunas tetap tampil
    }

    /** Super admin cek siapa saja yang belum lunas: filter bayar=belum + total piutang. */
    public function test_filter_belum_lunas_dan_total_piutang(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();

        $belum = $this->po(2_000_000);                 // piutang 2jt
        $svc->setTempo($belum, true, null, null);
        $svc->recordPayment($belum->fresh(), 500_000, '2026-07-21', null, $admin->id);   // sisa 1,5jt

        $lunas = $this->po(999_000);
        $lunas->update(['payment_status' => PurchaseOrder::PAYMENT_PAID]);

        $html = $this->actingAs($admin)->get('/purchase-orders?bayar=belum')->assertOk()->getContent();
        $this->assertStringContainsString($belum->po_number, $html);
        $this->assertStringNotContainsString($lunas->po_number, $html);
        // Piutang = sisa sesungguhnya (2jt − 500rb), bukan total tagihan.
        $this->assertStringContainsString('Total piutang', $html);
        $this->assertStringContainsString('1.500.000', $html);

        $html = $this->actingAs($admin)->get('/purchase-orders?bayar=lunas')->assertOk()->getContent();
        $this->assertStringContainsString($lunas->po_number, $html);
        $this->assertStringNotContainsString($belum->po_number, $html);
    }

    public function test_mitra_tak_bisa_menandai_tempo_atau_mencatat_cicilan(): void
    {
        $mitra = User::create([
            'name' => 'M', 'fullname' => 'Mitra', 'username' => 'tpmitra', 'email' => 'tpm@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_DISTRIBUTOR, 'status' => User::STATUS_ACTIVE,
        ]);
        $po = $this->po();

        $this->actingAs($mitra)->post(route('purchase-orders.tempo', $po), ['tempo' => 1])->assertForbidden();
        $this->actingAs($mitra)->post(route('purchase-orders.payments', $po), [
            'amount' => 1, 'paid_at' => '2026-07-21',
        ])->assertForbidden();

        $this->assertFalse((bool) $po->fresh()->is_tempo);
        $this->assertSame(0, $po->payments()->count());
    }

    /**
     * DAFTAR PO menampilkan badge pembayaran per baris — bukan cuma status
     * order. PO 'completed' yang tempo & belum lunas harus tetap kelihatan
     * belum lunasnya di daftar (keluhan nyata: tak ada tandanya di list).
     */
    public function test_daftar_po_menampilkan_badge_pembayaran(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();

        // Tempo completed, dicicil sebagian → badge Tempo + sisa.
        $tempo = $this->po(2_980_000);
        $svc->setTempo($tempo, true, null, '2026-08-31');
        $svc->recordPayment($tempo->fresh(), 1_000_000, '2026-07-21', null, $admin->id);
        $tempo->update(['status' => PurchaseOrder::STATUS_COMPLETED]);

        // Lunas penuh.
        $lunas = $this->po(500_000);
        $lunas->update(['payment_status' => PurchaseOrder::PAYMENT_PAID]);

        // Belum lunas, bukan tempo.
        $belum = $this->po(300_000);

        $html = $this->actingAs($admin)->get(route('purchase-orders.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Tempo · sisa Rp 1.980.000', $html);   // completed pun tetap ketahuan belum lunas
        $this->assertStringContainsString('🟢 Lunas', $html);
        $this->assertStringContainsString('🔴 Belum Lunas', $html);
        $this->assertStringContainsString('Pembayaran', $html);                  // kolom baru
    }

    /** Penjualan back-date otomatis LUNAS — bukan piutang palsu. */
    public function test_penjualan_backdate_langsung_lunas(): void
    {
        $svc = app(PurchaseOrderService::class);
        $admin = $this->admin();
        $buyer = User::create([
            'name' => 'B', 'fullname' => 'Erna', 'username' => 'bderna', 'email' => 'bderna@skinku.test',
            'password' => Hash::make('secret123'), 'role' => User::ROLE_RESELLER, 'status' => User::STATUS_ACTIVE,
            'company_name' => 'Erna',
        ]);
        $p = Product::create([
            'name' => 'BD Prod', 'sku' => 'BDP-1', 'hq_stock' => 100, 'status' => 'active',
            'cogs' => 1000, 'price_distributor' => 5000, 'price_reseller' => 5000,
        ]);
        AppSetting::put(AppSetting::PO_DEDUCT_FROM, '2026-07-15');

        $po = $svc->recordBackdatedSale($buyer, [['product_id' => $p->id, 'qty' => 10]],
            Carbon::parse('2026-06-08'), 'dari Excel', $admin->id);

        $this->assertSame(PurchaseOrder::PAYMENT_PAID, $po->fresh()->payment_status);
        $this->assertNotNull($po->fresh()->paid_at);
        // Badge di daftar: LUNAS, bukan Belum Lunas.
        $html = $this->actingAs($admin)->get(route('purchase-orders.index'))->assertOk()->getContent();
        $this->assertStringContainsString('🟢 Lunas', $html);
    }

    /** Migrasi 000053: back-date lama 'unpaid' jadi lunas; tempo & normal TAK tersentuh. */
    public function test_migrasi_menandai_backdate_lama_lunas_tanpa_menyentuh_tempo(): void
    {
        $backdate = $this->po(500_000);
        $backdate->update(['order_date' => '2026-06-01', 'payment_status' => PurchaseOrder::PAYMENT_UNPAID, 'status' => PurchaseOrder::STATUS_COMPLETED]);

        $tempo = $this->po(2_980_000);   // recent, order_date NULL, tempo
        $tempo->update(['is_tempo' => true, 'payment_status' => PurchaseOrder::PAYMENT_UNPAID]);

        $normalBelum = $this->po(300_000);   // recent unpaid biasa, order_date NULL

        // Migrasi anonim: require mengembalikan instance-nya, panggil up().
        $migration = require database_path('migrations/2026_01_01_000053_mark_backdated_pos_paid.php');
        $migration->up();

        $this->assertSame(PurchaseOrder::PAYMENT_PAID, $backdate->fresh()->payment_status);   // disentuh
        $this->assertSame(PurchaseOrder::PAYMENT_UNPAID, $tempo->fresh()->payment_status);    // tempo aman
        $this->assertSame(PurchaseOrder::PAYMENT_UNPAID, $normalBelum->fresh()->payment_status); // normal aman
    }
}
