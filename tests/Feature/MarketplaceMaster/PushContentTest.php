<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use App\Services\MarketplaceMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Mesin dorong KONTEN (deskripsi/berat/dimensi) ke listing marketplace yang SUDAH ADA:
 * buildContentPayload (skip-empty + konversi satuan), contentHash (diff-guard),
 * pushContent (ok/failed/skip per listing), pushMasterContent (tally per listing).
 *
 * Jalur TERPISAH dari push stok/harga: tak boleh ikut terpicu pushListing/pushMaster/
 * pushDirty/pushAll (cron 5-menit), dan sebaliknya tak menyentuh jejak stok/harga.
 */
class PushContentTest extends TestCase
{
    use RefreshDatabase;

    /** Master lengkap (desc 'Serum X', 250 g, 10x8x5 cm) → bentuk payload TikTok. */
    private const TIKTOK_LENGKAP = [
        'description' => 'Serum X',
        'package_weight' => ['value' => '0.25', 'unit' => 'KILOGRAM'],
        'package_dimensions' => ['length' => '10', 'width' => '8', 'height' => '5', 'unit' => 'CENTIMETER'],
    ];

    /** Master yang sama → bentuk payload Shopee (berat float kg, dimensi int cm). */
    private const SHOPEE_LENGKAP = [
        'description' => 'Serum X',
        'weight' => 0.25,
        'dimension' => ['package_length' => 10, 'package_width' => 8, 'package_height' => 5],
    ];

    // ---- helper ----

    private function svc(): MarketplaceMasterService
    {
        return app(MarketplaceMasterService::class);
    }

    private function tiktokConn(): void
    {
        TiktokConnection::create(['shop_id' => 's', 'shop_cipher' => 'c', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    private function shopeeConn(): void
    {
        ShopeeConnection::create(['shop_id' => '123', 'access_token' => 't', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()]);
    }

    /** Fake sukses utk dua endpoint konten; request lain (mis. stok/harga) = stray → gagal keras. */
    private function fakeOk(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*/product/202309/products/*/partial_edit*' => Http::response(['code' => 0, 'message' => 'Success', 'data' => []]),
            '*/api/v2/product/update_item*' => Http::response(['error' => '', 'message' => '', 'response' => []]),
        ]);
    }

    private function masterLengkap(array $over = []): MarketplaceMaster
    {
        return MarketplaceMaster::create(array_merge([
            'master_sku' => 'SX-1', 'name' => 'Serum X',
            'description' => 'Serum X', 'weight_g' => 250, 'length_cm' => 10, 'width_cm' => 8, 'height_cm' => 5,
        ], $over));
    }

    private function masterKosong(): MarketplaceMaster
    {
        return MarketplaceMaster::create(['master_sku' => 'K-1', 'name' => 'Kosong']);
    }

    private function tiktokListing(MarketplaceMaster $m, array $over = []): MarketplaceListing
    {
        return MarketplaceListing::create(array_merge([
            'channel' => 'tiktok', 'seller_sku' => 'SX-1', 'master_id' => $m->id,
            'item_id' => 'PID1', 'variation_id' => 'SKU1', 'warehouse_id' => 'WH1',
        ], $over));
    }

    private function shopeeListing(MarketplaceMaster $m, array $over = []): MarketplaceListing
    {
        return MarketplaceListing::create(array_merge([
            'channel' => 'shopee', 'seller_sku' => 'SX-1', 'master_id' => $m->id,
            'item_id' => '555', 'variation_id' => '66',
        ], $over));
    }

    // ---- buildContentPayload ----

    public function test_payload_tiktok_lengkap_dengan_konversi_satuan(): void
    {
        $m = $this->masterLengkap();

        $this->assertSame(self::TIKTOK_LENGKAP, $this->svc()->buildContentPayload($m, 'tiktok'));
    }

    public function test_payload_shopee_lengkap_dengan_konversi_satuan(): void
    {
        $m = $this->masterLengkap();

        $this->assertSame(self::SHOPEE_LENGKAP, $this->svc()->buildContentPayload($m, 'shopee'));
    }

    public function test_payload_dari_baris_db_tetap_bertipe_benar(): void
    {
        // Model dimuat ulang dari DB (bukan atribut hasil create): tipe angka dari driver
        // tak boleh mengubah bentuk payload (string di TikTok, int/float di Shopee).
        $m = $this->masterLengkap()->fresh();

        $this->assertSame(self::TIKTOK_LENGKAP, $this->svc()->buildContentPayload($m, 'tiktok'));
        $this->assertSame(self::SHOPEE_LENGKAP, $this->svc()->buildContentPayload($m, 'shopee'));
    }

    public function test_payload_master_kosong_semua_adalah_array_kosong(): void
    {
        $m = $this->masterKosong();

        $this->assertSame([], $this->svc()->buildContentPayload($m, 'tiktok'));
        $this->assertSame([], $this->svc()->buildContentPayload($m, 'shopee'));
    }

    public function test_payload_nol_dan_spasi_dianggap_kosong(): void
    {
        // Deskripsi cuma spasi/newline + berat 0 + dimensi 0 = sama saja kosong → tak ada yang dikirim.
        foreach (['   ', "\n\t  \n", ''] as $i => $desc) {
            $m = $this->masterLengkap(['description' => $desc, 'weight_g' => 0, 'length_cm' => 0, 'width_cm' => 0, 'height_cm' => 0]);

            $this->assertSame([], $this->svc()->buildContentPayload($m, 'tiktok'), "tiktok, deskripsi kosong varian #{$i}");
            $this->assertSame([], $this->svc()->buildContentPayload($m, 'shopee'), "shopee, deskripsi kosong varian #{$i}");
        }
    }

    public function test_payload_dimensi_tak_lengkap_dilewati_tapi_berat_dan_deskripsi_tetap_ikut(): void
    {
        // Satu saja dimensi 0/null → SELURUH blok dimensi dilewati (setengah-dimensi ditolak marketplace),
        // tapi field lain yang terisi tetap dikirim.
        $kasus = [[10, 8, 0], [10, 8, null], [10, null, 5], [0, 8, 5], [null, null, null]];
        foreach ($kasus as [$p, $l, $t]) {
            $m = $this->masterLengkap(['length_cm' => $p, 'width_cm' => $l, 'height_cm' => $t]);
            $label = 'dimensi ['.var_export($p, true).','.var_export($l, true).','.var_export($t, true).']';

            $this->assertSame(
                ['description' => 'Serum X', 'package_weight' => ['value' => '0.25', 'unit' => 'KILOGRAM']],
                $this->svc()->buildContentPayload($m, 'tiktok'),
                "tiktok, {$label}",
            );
            $this->assertSame(
                ['description' => 'Serum X', 'weight' => 0.25],
                $this->svc()->buildContentPayload($m, 'shopee'),
                "shopee, {$label}",
            );
        }
    }

    public function test_payload_tiap_field_berdiri_sendiri(): void
    {
        $svc = $this->svc();

        $cuma = $this->masterLengkap(['weight_g' => null, 'length_cm' => null, 'width_cm' => null, 'height_cm' => null]);
        $this->assertSame(['description' => 'Serum X'], $svc->buildContentPayload($cuma, 'tiktok'));
        $this->assertSame(['description' => 'Serum X'], $svc->buildContentPayload($cuma, 'shopee'));

        $cuma = $this->masterLengkap(['description' => null, 'length_cm' => null, 'width_cm' => null, 'height_cm' => null]);
        $this->assertSame(['package_weight' => ['value' => '0.25', 'unit' => 'KILOGRAM']], $svc->buildContentPayload($cuma, 'tiktok'));
        $this->assertSame(['weight' => 0.25], $svc->buildContentPayload($cuma, 'shopee'));

        $cuma = $this->masterLengkap(['description' => null, 'weight_g' => null]);
        $this->assertSame(['package_dimensions' => self::TIKTOK_LENGKAP['package_dimensions']], $svc->buildContentPayload($cuma, 'tiktok'));
        $this->assertSame(['dimension' => self::SHOPEE_LENGKAP['dimension']], $svc->buildContentPayload($cuma, 'shopee'));
    }

    public function test_payload_konversi_gram_ke_kilogram(): void
    {
        // gram → [nilai TikTok (string), nilai Shopee (float)]
        $kasus = [
            1 => ['0.001', 0.001],
            250 => ['0.25', 0.25],
            333 => ['0.333', 0.333],
            1000 => ['1', 1.0],
            1500 => ['1.5', 1.5],
            12345 => ['12.345', 12.345],
        ];
        foreach ($kasus as $gram => [$tiktok, $shopee]) {
            $m = $this->masterLengkap(['weight_g' => $gram]);

            $this->assertSame(['value' => $tiktok, 'unit' => 'KILOGRAM'], $this->svc()->buildContentPayload($m, 'tiktok')['package_weight'], "tiktok {$gram} g");
            $this->assertSame($shopee, $this->svc()->buildContentPayload($m, 'shopee')['weight'], "shopee {$gram} g");
        }
    }

    public function test_payload_tak_pernah_memuat_item_id_atau_nama(): void
    {
        // item_id di payload akan menimpa $itemId eksplisit di ShopeeClient::updateItem (array_merge),
        // dan nama/judul memang TIDAK boleh didorong (aturan aman #3).
        $m = $this->masterLengkap(['name' => 'Nama Master']);

        foreach (['tiktok', 'shopee'] as $channel) {
            $payload = $this->svc()->buildContentPayload($m, $channel);
            foreach (['item_id', 'product_id', 'title', 'item_name', 'name'] as $terlarang) {
                $this->assertArrayNotHasKey($terlarang, $payload, "{$channel}: '{$terlarang}' tak boleh ikut didorong");
            }
        }
    }

    // ---- contentHash (private; diuji lewat reflection + perilakunya di pushContent) ----

    public function test_content_hash_deterministik(): void
    {
        $svc = $this->svc();
        $hash = new ReflectionMethod($svc, 'contentHash');
        $payload = ['description' => 'Serum X', 'weight' => 0.25];

        $a = $hash->invoke($svc, $payload);
        $b = $hash->invoke($svc, ['description' => 'Serum X', 'weight' => 0.25]);

        $this->assertSame($a, $b);
        $this->assertSame(md5(json_encode($payload)), $a);
        $this->assertNotSame($a, $hash->invoke($svc, ['description' => 'Serum Y', 'weight' => 0.25]));
    }

    // ---- pushContent: skip ----

    public function test_payload_kosong_skip_tanpa_http_dan_tanpa_menyentuh_jejak(): void
    {
        $this->tiktokConn();
        Http::fake();
        $m = $this->masterKosong();
        $l = $this->tiktokListing($m);

        $r = $this->svc()->pushContent($l, $m, true); // force pun tak ada yang bisa dikirim

        $this->assertSame('skip', $r);
        Http::assertNothingSent();
        $l->refresh();
        $this->assertNull($l->last_content_status);
        $this->assertNull($l->last_content_error);
        $this->assertNull($l->last_content_pushed_at);
        $this->assertNull($l->content_hash);
    }

    // ---- pushContent: TikTok ----

    public function test_tiktok_sukses_ok_kirim_partial_edit_dan_catat_jejak(): void
    {
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);

        $r = $this->svc()->pushContent($l, $m, false);

        $this->assertSame('ok', $r);
        $l->refresh();
        $this->assertSame('ok', $l->last_content_status);
        $this->assertNull($l->last_content_error);
        $this->assertNotNull($l->last_content_pushed_at);
        $this->assertSame(md5(json_encode(self::TIKTOK_LENGKAP)), $l->content_hash);
        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            return $req->method() === 'POST'
                && str_contains($req->url(), '/product/202309/products/PID1/partial_edit')
                && str_contains($req->url(), 'shop_cipher=c')
                && $req->hasHeader('x-tts-access-token', 't')
                && json_decode($req->body(), true) === self::TIKTOK_LENGKAP;
        });
    }

    public function test_diff_guard_payload_sama_dilewati_tanpa_http(): void
    {
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushContent($l, $m, false));
        Http::assertSentCount(1);

        // Model dimuat ulang dari DB → hash memang tersimpan (bukan sekadar atribut in-memory).
        $this->assertSame('skip', $svc->pushContent($l->fresh(), $m, false));
        Http::assertSentCount(1);
    }

    public function test_force_true_kirim_ulang_walau_hash_sama(): void
    {
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushContent($l, $m, false));
        $this->assertSame('ok', $svc->pushContent($l->fresh(), $m, true));

        Http::assertSentCount(2);
    }

    public function test_konten_berubah_lolos_diff_guard_dan_hash_diperbarui(): void
    {
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushContent($l, $m, false));
        $hashLama = $l->fresh()->content_hash;

        $m->update(['description' => 'Serum X versi baru']);
        $this->assertSame('ok', $svc->pushContent($l->fresh(), $m, false)); // tanpa force, tapi kontennya beda

        Http::assertSentCount(2);
        $baru = $l->fresh()->content_hash;
        $this->assertNotSame($hashLama, $baru);
        $this->assertSame(md5(json_encode($svc->buildContentPayload($m, 'tiktok'))), $baru);
    }

    public function test_api_error_catat_failed_dan_pesan_asli_tanpa_menyimpan_hash(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 36004, 'message' => 'no permission'], 200)]);
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);

        $r = $this->svc()->pushContent($l, $m, false);

        $this->assertSame('failed', $r);
        $l->refresh();
        $this->assertSame('failed', $l->last_content_status);
        $this->assertStringContainsString('no permission', (string) $l->last_content_error);
        $this->assertNull($l->content_hash);              // gagal ≠ terkirim → diff-guard tak boleh "mengunci"
        $this->assertNull($l->last_content_pushed_at);
    }

    public function test_exception_koneksi_dicatat_failed(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);

        $r = $this->svc()->pushContent($l, $m, true);

        $this->assertSame('failed', $r);
        $l->refresh();
        $this->assertSame('failed', $l->last_content_status);
        $this->assertStringContainsString('timed out', (string) $l->last_content_error);
    }

    public function test_tanpa_koneksi_tiktok_dicatat_failed_tanpa_http(): void
    {
        Http::fake(); // sengaja TANPA baris TiktokConnection
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);

        $r = $this->svc()->pushContent($l, $m, true);

        $this->assertSame('failed', $r);
        $this->assertSame('TikTok belum terhubung', $l->refresh()->last_content_error);
        Http::assertNothingSent();
    }

    public function test_pesan_error_dipotong_500_karakter(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::response(['code' => 36004, 'message' => str_repeat('x', 800)], 200)]);
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);

        $this->svc()->pushContent($l, $m, true);

        $this->assertSame(500, mb_strlen((string) $l->refresh()->last_content_error));
    }

    public function test_setelah_gagal_push_ulang_tanpa_force_tetap_terkirim_dan_error_lama_dibersihkan(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::sequence()
            ->push(['code' => 36004, 'message' => 'no permission'], 200)
            ->push(['code' => 0, 'data' => []], 200)]);
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('failed', $svc->pushContent($l, $m, false));
        $this->assertSame('ok', $svc->pushContent($l->fresh(), $m, false)); // hash tak tersimpan saat gagal → bukan 'skip'

        $l->refresh();
        $this->assertSame('ok', $l->last_content_status);
        $this->assertNull($l->last_content_error);
        $this->assertNotNull($l->content_hash);
        $this->assertNotNull($l->last_content_pushed_at);
    }

    public function test_gagal_setelah_sukses_mempertahankan_hash_dan_waktu_sukses_terakhir(): void
    {
        $this->tiktokConn();
        Http::fake(['*' => Http::sequence()
            ->push(['code' => 0, 'data' => []], 200)
            ->push(['code' => 36004, 'message' => 'no permission'], 200)]);
        $m = $this->masterLengkap();
        $l = $this->tiktokListing($m);
        $svc = $this->svc();

        $this->assertSame('ok', $svc->pushContent($l, $m, false));
        $sukses = $l->fresh();

        $m->update(['description' => 'Serum X versi baru']);
        $this->assertSame('failed', $svc->pushContent($l->fresh(), $m, false));

        $l->refresh();
        $this->assertSame('failed', $l->last_content_status);
        $this->assertNotNull($l->last_content_error);
        $this->assertSame($sukses->content_hash, $l->content_hash);                       // yang terpasang di marketplace tetap versi lama
        $this->assertSame($sukses->last_content_pushed_at, $l->last_content_pushed_at);   // waktu = push SUKSES terakhir
    }

    // ---- pushContent: Shopee ----

    public function test_shopee_sukses_ok_kirim_update_item_dengan_item_id_integer(): void
    {
        $this->shopeeConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $l = $this->shopeeListing($m);

        $r = $this->svc()->pushContent($l, $m, false);

        $this->assertSame('ok', $r);
        $l->refresh();
        $this->assertSame('ok', $l->last_content_status);
        $this->assertNull($l->last_content_error);
        $this->assertNotNull($l->last_content_pushed_at);
        $this->assertSame(md5(json_encode(self::SHOPEE_LENGKAP)), $l->content_hash);
        Http::assertSentCount(1);
        Http::assertSent(function ($req) {
            return $req->method() === 'POST'
                && str_contains($req->url(), '/api/v2/product/update_item')
                && str_contains($req->url(), 'shop_id=123')
                && str_contains($req->url(), 'access_token=t')
                // body = item_id (int, dari listing) + payload persis, tak ada key lain.
                && json_decode($req->body(), true) === array_merge(['item_id' => 555], self::SHOPEE_LENGKAP);
        });
    }

    public function test_shopee_error_field_dicatat_failed_dengan_pesan_asli(): void
    {
        $this->shopeeConn();
        Http::fake(['*' => Http::response(['error' => 'error_param', 'message' => 'invalid dimension', 'response' => []])]);
        $m = $this->masterLengkap();
        $l = $this->shopeeListing($m);

        $r = $this->svc()->pushContent($l, $m, true);

        $this->assertSame('failed', $r);
        $l->refresh();
        $this->assertSame('failed', $l->last_content_status);
        $this->assertStringContainsString('invalid dimension', (string) $l->last_content_error);
        $this->assertNull($l->content_hash);
    }

    // ---- pushMasterContent ----

    public function test_push_master_content_tally_dua_listing_dan_hq_tak_berubah(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $this->tiktokListing($m);
        $this->shopeeListing($m);
        $hqSebelum = DB::table('stock_movements')->count();

        $r = $this->svc()->pushMasterContent($m);

        // 1 listing = 1 unit (BUKAN dua seperti stok+harga): 2 listing → pushed 2.
        $this->assertSame(['pushed' => 2, 'skipped' => 0, 'failed' => 0], $r);
        Http::assertSentCount(2);
        $this->assertSame($hqSebelum, DB::table('stock_movements')->count());
    }

    public function test_push_master_content_default_force_true_dan_force_false_menghormati_diff_guard(): void
    {
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $this->tiktokListing($m, ['content_hash' => md5(json_encode(self::TIKTOK_LENGKAP))]); // sudah terkirim persis ini
        $svc = $this->svc();

        $this->assertSame(['pushed' => 0, 'skipped' => 1, 'failed' => 0], $svc->pushMasterContent($m, false));
        Http::assertNothingSent();

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $svc->pushMasterContent($m)); // default force=true
        Http::assertSentCount(1);
    }

    public function test_push_master_content_payload_kosong_semua_skipped_tanpa_http(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        Http::fake();
        $m = $this->masterKosong();
        $this->tiktokListing($m);
        $this->shopeeListing($m);

        $r = $this->svc()->pushMasterContent($m);

        $this->assertSame(['pushed' => 0, 'skipped' => 2, 'failed' => 0], $r);
        Http::assertNothingSent();
    }

    public function test_push_master_content_campuran_ok_dan_failed(): void
    {
        $this->tiktokConn(); // Shopee sengaja belum terhubung
        $this->fakeOk();
        $m = $this->masterLengkap();
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);

        $r = $this->svc()->pushMasterContent($m);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 1], $r);
        $this->assertSame('ok', $tiktok->fresh()->last_content_status);
        $this->assertSame('failed', $shopee->fresh()->last_content_status);
        $this->assertSame('Shopee belum terhubung', $shopee->fresh()->last_content_error);
    }

    public function test_push_master_content_hanya_listing_ber_item_id_milik_master_itu(): void
    {
        $this->tiktokConn();
        $this->fakeOk();
        $m = $this->masterLengkap();
        $lain = $this->masterLengkap(['master_sku' => 'LAIN-1', 'name' => 'Master Lain']);
        $this->tiktokListing($m);                                                                    // ikut
        $this->tiktokListing($m, ['seller_sku' => 'SX-1-BELUM-RESOLVE', 'item_id' => null]);           // tanpa item_id → tak ikut
        $this->tiktokListing($lain, ['seller_sku' => 'LAIN-1', 'item_id' => 'PID-LAIN']);              // master lain → tak ikut

        $r = $this->svc()->pushMasterContent($m);

        $this->assertSame(['pushed' => 1, 'skipped' => 0, 'failed' => 0], $r);
        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/products/PID1/partial_edit'));
    }

    // ---- Jalur konten TERPISAH dari jalur stok/harga ----

    public function test_push_konten_tidak_menyentuh_stok_dan_harga(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        $this->fakeOk();
        $m = $this->masterLengkap(['base_stock' => 30, 'base_price' => 39000]);
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);

        $this->svc()->pushMasterContent($m);

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'inventory/update')
            || str_contains($req->url(), 'prices/update')
            || str_contains($req->url(), '/product/update_stock')
            || str_contains($req->url(), '/product/update_price'));
        foreach ([$tiktok, $shopee] as $l) {
            $l->refresh();
            $this->assertNull($l->last_pushed_qty);
            $this->assertNull($l->last_status);
            $this->assertNull($l->last_pushed_price);
            $this->assertNull($l->last_price_status);
        }
    }

    public function test_jalur_stok_harga_termasuk_cron_tak_pernah_mengirim_konten(): void
    {
        $this->tiktokConn();
        $this->shopeeConn();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['code' => 0, 'data' => [], 'error' => '', 'response' => []])]);
        $m = $this->masterLengkap(['base_stock' => 30, 'base_price' => 39000]);
        $tiktok = $this->tiktokListing($m);
        $shopee = $this->shopeeListing($m);
        $svc = $this->svc();

        $svc->pushListing($tiktok, true);
        $svc->pushMaster($m);
        $svc->pushDirty();   // dipakai cron 5-menit
        $svc->pushAll();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'inventory/update')); // stok/harga memang jalan…
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'partial_edit')     // …tapi konten tidak.
            || str_contains($req->url(), '/product/update_item'));
        foreach ([$tiktok, $shopee] as $l) {
            $l->refresh();
            $this->assertNull($l->last_content_status);
            $this->assertNull($l->last_content_pushed_at);
            $this->assertNull($l->content_hash);
        }
    }
}
