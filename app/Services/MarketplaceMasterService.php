<?php

namespace App\Services;

use App\Models\File;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Engine "Produk Master E-commerce" (ala Desty): tiap unit jualan (satuan/varian/
 * bundle) = 1 master dgn stok+harga di-set langsung, tertaut ke listing TikTok/
 * Shopee, override per-channel. TERPISAH TOTAL dari stok HQ.
 */
class MarketplaceMasterService
{
    public function __construct(private ShopeeClient $shopee, private TikTokClient $tiktok) {}

    // ---- Nilai efektif per (master, channel) ----

    public function effectiveStock(MarketplaceMaster $m, string $channel): ?int
    {
        // Bundle ber-resep: stok DIHITUNG dari komponen = min(floor(stok komponen / qty)); komponen tanpa stok → null.
        $isi = $this->isiBundle($m);
        if ($isi->isNotEmpty()) {
            $min = null;
            foreach ($isi as $it) {
                $s = $it->component ? $this->effectiveStock($it->component, $channel) : null;
                if ($s === null) {
                    return null;
                }
                $bisa = intdiv(max(0, $s), max(1, $it->qty));
                $min = $min === null ? $bisa : min($min, $bisa);
            }

            return $min;
        }

        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->stock !== null) {
            return (int) $ch->stock;
        }

        return $m->base_stock !== null ? (int) $m->base_stock : null;
    }

    public function effectivePrice(MarketplaceMaster $m, string $channel): ?float
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->price !== null) {
            return (float) $ch->price;
        }

        return $m->base_price !== null ? (float) $m->base_price : null;
    }

    /** Gandakan master jadi baris baru (SKU "-COPY", nama "(copy)") tanpa listing/override/foto/stok — mulai bersih. */
    public function duplicateMaster(MarketplaceMaster $m): MarketplaceMaster
    {
        $name = $m->name.' (copy)';

        return MarketplaceMaster::create([
            'master_sku' => $m->master_sku.'-COPY',
            'name' => $name,
            'name_key' => MarketplaceMaster::normalizeName($name),
            'is_bundle' => $m->is_bundle,
            'base_price' => $m->base_price,
        ]);
    }

    /** Kosongkan SELURUH katalog master e-commerce (bulk). FK: listing.master_id auto-null (nullOnDelete), channel override auto-hapus (cascade). HQ TAK disentuh. Return jumlah master dihapus. */
    public function deleteAllMasters(): int
    {
        return MarketplaceMaster::query()->delete();
    }

    // ---- Setter Master ----

    public function setMasterStock(MarketplaceMaster $m, int $qty): void
    {
        $m->update(['base_stock' => max(0, $qty), 'seeded_at' => now()]);
    }

    public function setMasterPrice(MarketplaceMaster $m, float $price): void
    {
        $m->update(['base_price' => max(0, $price)]);
    }

    // ---- Setter override channel ----

    public function setChannelStock(MarketplaceMaster $m, string $channel, int $qty): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['stock' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    public function setChannelPrice(MarketplaceMaster $m, string $channel, float $price): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['price' => max(0, $price)],
        );
    }

    /** Kembalikan satu field channel ke "ikut Master"; hapus baris bila stock & price dua-duanya null. */
    public function ikutMaster(MarketplaceMaster $m, string $channel, string $field): void
    {
        $row = MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if (! $row) {
            return;
        }
        $row->update([$field => null]);
        if ($row->stock === null && $row->price === null) {
            $row->delete();
        }
    }

    // ---- Helper koneksi & token (SALINAN transisi dari MarketplaceStockService) ----

    private function tiktokConn(): ?TiktokConnection
    {
        return TiktokConnection::latest('id')->first();
    }

    private function shopeeConn(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

    private function tiktokToken(TiktokConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->tiktok->refreshToken($c->refresh_token);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->tiktokExpiry($t['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->tiktokExpiry($t['refresh_token_expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    private function tiktokExpiry(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }

    private function shopeeToken(ShopeeConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->shopee->refreshToken($c->refresh_token, $c->shop_id);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->shopeeExpiry($t['expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    private function shopeeExpiry(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }

    // ---- Resolve listing dari channel + tautkan manual ----

    /**
     * Isi/segarkan marketplace_listings dari channel (item_id/variation_id/
     * warehouse_id). TAK auto-buat/tautkan master — penautan ke master
     * dilakukan manual (lewat modal Kaitkan di UI / linkListings()). TERPISAH
     * dari MarketplaceStockService::resolveListings() (SkuMap/HQ).
     *
     * @return array{found:int}
     */
    public function resolveListings(string $channel): array
    {
        return $channel === 'tiktok' ? $this->resolveTiktok() : $this->resolveShopee();
    }

    /**
     * Tautkan banyak listing ke master (bulk). Return jumlah listing ter-update.
     * Guard `whereNull('master_id')`: hanya listing yang BELUM tertaut yang boleh
     * ditautkan — cegah "curi" listing milik master lain lewat POST langsung
     * (UI modal memang cuma nawarin baris belum-tertaut). Simetris dg unlinkListings.
     */
    public function linkListings(MarketplaceMaster $m, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return MarketplaceListing::whereIn('id', $ids)->whereNull('master_id')->update(['master_id' => $m->id]);
    }

    /** Lepas banyak listing dari master — HANYA yang memang milik master ini (safety). */
    public function unlinkListings(MarketplaceMaster $m, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return MarketplaceListing::whereIn('id', $ids)->where('master_id', $m->id)->update(['master_id' => null]);
    }

    // ---- Push stok & harga (aditif; independen per listing, anti-push null) ----

    /** Push stok & harga efektif satu listing (via master+channel). Kedua field independen, anti-push null. */
    public function pushListing(MarketplaceListing $l, bool $force = false): array
    {
        $master = $l->master_id ? $l->master : null;
        if (! $master || ! $l->item_id) {
            return ['stock' => 'skip', 'price' => 'skip'];
        }
        $stock = $this->effectiveStock($master, $l->channel);
        $price = $this->effectivePrice($master, $l->channel);

        return [
            'stock' => $this->pushStock($l, $stock, $force),
            'price' => $this->pushPrice($l, $price, $force),
        ];
    }

    private function pushStock(MarketplaceListing $l, ?int $stock, bool $force): string
    {
        if ($stock === null) {
            return 'skip';
        }
        if (! $force && $l->last_pushed_qty === $stock) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->updateStock($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, (string) $l->warehouse_id, $stock);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updateStock($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $stock);
            }
            $l->update(['last_pushed_qty' => $stock, 'last_status' => 'ok', 'last_error' => null, 'last_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_status' => 'failed', 'last_error' => $this->errorText($e)]);

            return 'failed';
        }
    }

    private function pushPrice(MarketplaceListing $l, ?float $price, bool $force): string
    {
        if ($price === null) {
            return 'skip';
        }
        if (! $force && $l->last_pushed_price !== null && (float) $l->last_pushed_price === $price) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->updatePrice($this->tiktokToken($c), $c->shop_cipher, $l->item_id, (string) $l->variation_id, $price);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updatePrice($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, (int) $l->variation_id, $price);
            }
            $l->update(['last_pushed_price' => $price, 'last_price_status' => 'ok', 'last_price_error' => null, 'last_price_pushed_at' => now()]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_price_status' => 'failed', 'last_price_error' => $this->errorText($e)]);

            return 'failed';
        }
    }

    public function pushMaster(MarketplaceMaster $m, bool $force = true): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($m->listings()->whereNotNull('item_id')->get() as $l) {
            $this->tallyPush($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    public function pushDirty(): array
    {
        return $this->pushEach(false);
    }

    public function pushAll(): array
    {
        return $this->pushEach(true);
    }

    private function pushEach(bool $force): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        foreach (MarketplaceListing::whereNotNull('item_id')->whereNotNull('master_id')->get() as $l) {
            $this->tallyPush($out, $this->pushListing($l, $force));
        }

        return $out;
    }

    /** Hitung stok & harga sbg dua unit terpisah: ok→pushed, failed→failed, skip→skipped. */
    private function tallyPush(array &$out, array $res): void
    {
        foreach (['stock', 'price'] as $field) {
            match ($res[$field]) {
                'ok' => $out['pushed']++,
                'failed' => $out['failed']++,
                default => $out['skipped']++,
            };
        }
    }

    // ---- Dorong KONTEN produk (nama/deskripsi/berat/dimensi/barcode + foto) — jalur TERPISAH dari stok/harga ----
    //
    // Sengaja TIDAK lewat pushListing/pushDirty/pushEach (cron 5-menit). Dua pemicu:
    // (1) tombol "Dorong" (manual, paksa semua), (2) otomatis tiap Simpan form Ubah — HANYA ke listing yang
    // sudah pernah didorong manual (dorong pertama menimpa isi listing, jadi wajib disengaja), lewat diff-guard.

    /**
     * Payload konten partial per-channel — HANYA field yang terisi (skip-empty), supaya master
     * yang belum lengkap tak menimpa listing yang sudah bagus dengan kosong. Sudah konversi
     * satuan (gram → kg). `[]` bila tak ada satu pun yang dikirim.
     *
     * TIDAK pernah memuat `item_id` (ShopeeClient::updateItem menambahkannya sendiri lewat
     * array_merge, jadi key yang sama di sini akan menimpanya).
     *
     * Dengan $l (listing tujuan): + NAMA (judul = level PRODUK → hanya bila produk itu cuma punya 1 SKU di
     * marketplace; produk bervarian dilewati agar judul tak tertimpa nama salah satu varian) dan BARCODE
     * (level SKU; hanya GTIN valid — TikTok `skus[].identifier_code`, Shopee `gtin_code` utk item tanpa model).
     */
    public function buildContentPayload(MarketplaceMaster $m, string $channel, ?MarketplaceListing $l = null): array
    {
        $tiktok = $channel === 'tiktok';
        $payload = [];
        $varian = $m;              // barcode = level SKU → dari master varian/listing ini
        $m = $m->sumberKonten();   // nama/deskripsi/berat/dimensi/kategori = level PRODUK → dari induk bila varian

        if ($l && trim((string) $m->name) !== '' && $this->satuSku($l, $m)) {
            $payload[$tiktok ? 'title' : 'item_name'] = trim((string) $m->name);
        }

        if (trim((string) $m->description) !== '') {
            $payload['description'] = (string) $m->description;
        }

        $weightG = (int) $m->weight_g;
        if ($weightG > 0) {
            $kg = round($weightG / 1000, 3);
            if ($tiktok) {
                $payload['package_weight'] = ['value' => (string) $kg, 'unit' => 'KILOGRAM'];
            } else {
                $payload['weight'] = $kg;
            }
        }

        // Dimensi: ketiganya harus terisi — setengah-dimensi tak valid di marketplace, jadi dilewati semua.
        [$length, $width, $height] = [(int) $m->length_cm, (int) $m->width_cm, (int) $m->height_cm];
        if ($length > 0 && $width > 0 && $height > 0) {
            if ($tiktok) {
                $payload['package_dimensions'] = ['length' => (string) $length, 'width' => (string) $width, 'height' => (string) $height, 'unit' => 'CENTIMETER'];
            } else {
                $payload['dimension'] = ['package_length' => $length, 'package_width' => $width, 'package_height' => $height];
            }
        }

        if ($l) {
            $payload += $this->kategoriPayload($m, $l);
        }

        $gtin = self::gtin((string) $varian->barcode);
        if ($l && $gtin !== null) {
            if ($tiktok && $l->variation_id) {
                $payload['skus'] = [['id' => (string) $l->variation_id, 'identifier_code' => ['code' => $gtin, 'type' => self::gtinType($gtin)]]];
            } elseif (! $tiktok && in_array((string) $l->variation_id, ['', '0'], true)) {
                // ponytail: barcode per-model Shopee butuh endpoint update_model — belum; item bervarian dilewati.
                $payload['gtin_code'] = $gtin;
            }
        }

        return $payload;
    }

    /**
     * Judul boleh dikirim: SEMUA SKU produk marketplace ini (channel+item_id sama) tertaut ke keluarga master yang sama
     * (induk + variannya) — produk 1 SKU, atau produk bervarian yang variannya dikelola sbg varian master ini.
     * Ada SKU tak tertaut / milik master lain → dilewati agar judul tak tertimpa nama satu varian.
     */
    private function satuSku(MarketplaceListing $l, MarketplaceMaster $induk): bool
    {
        $keluarga = $induk->keluargaIds();

        return ! MarketplaceListing::where('channel', $l->channel)->where('item_id', $l->item_id)
            ->where(fn ($q) => $q->whereNull('master_id')->orWhereNotIn('master_id', $keluarga))->exists();
    }

    /** Barcode → GTIN bersih bila valid (8/12/13/14 digit + check digit benar), selain itu null (tak dikirim). */
    public static function gtin(string $barcode): ?string
    {
        $d = preg_replace('/\s+/', '', $barcode);
        if (! preg_match('/^(\d{8}|\d{12,14})$/', $d)) {
            return null;
        }
        $sum = 0;
        $body = substr($d, 0, -1);
        for ($i = strlen($body) - 1, $w = 3; $i >= 0; $i--, $w = $w === 3 ? 1 : 3) {
            $sum += (int) $body[$i] * $w;
        }

        return (10 - $sum % 10) % 10 === (int) substr($d, -1) ? $d : null;
    }

    /** Jenis kode utk TikTok identifier_code: 12 digit = UPC, 14 = GTIN, 8/13 = EAN. */
    private static function gtinType(string $gtin): string
    {
        return match (strlen($gtin)) {
            12 => 'UPC',
            14 => 'GTIN',
            default => 'EAN',
        };
    }

    /** Hash payload terkirim — kunci diff-guard (payload sama persis = tak perlu kirim ulang). */
    private function contentHash(array $payload): string
    {
        return md5(json_encode($payload));
    }

    /**
     * Pesan error yang AMAN disimpan & ditampilkan (tooltip halaman channel): saat timeout Guzzle ikut
     * menempelkan URL lengkap berikut query string — Shopee menaruh access_token & sign di query — jadi
     * kredensial disamarkan dulu, lalu dipotong 500 karakter.
     */
    private function errorText(\Throwable $e): string
    {
        return mb_substr(self::maskSecrets($e->getMessage()), 0, 500);
    }

    /** Samarkan nilai kredensial di teks (URL/query) sebelum disimpan atau ditampilkan ke admin. */
    public static function maskSecrets(string $msg): string
    {
        return (string) preg_replace('/\b(access_token|refresh_token|sign|partner_key|app_secret|shop_cipher)=[^&\s"\'<>]+/i', '$1=***', $msg);
    }

    /**
     * Dorong konten master ke SATU listing. 'skip' bila payload kosong (tanpa panggilan API)
     * atau — kecuali $force — hash payload sama dgn push sukses terakhir; 'ok'|'failed' sisanya.
     * Pola sama persis pushStock/pushPrice; jejak di kolom last_content_* + content_hash.
     */
    public function pushContent(MarketplaceListing $l, MarketplaceMaster $m, bool $force): string
    {
        $payload = $this->buildContentPayload($m, $l->channel, $l);
        if ($payload === []) {
            return 'skip';
        }
        $hash = $this->contentHash($payload);
        if (! $force && $l->content_hash === $hash) {
            return 'skip';
        }
        try {
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $this->tiktok->partialEditProduct($this->tiktokToken($c), $c->shop_cipher, $l->item_id, $payload);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $this->shopee->updateItem($this->shopeeToken($c), $c->shop_id, (int) $l->item_id, $payload);
            }
            $l->update(['last_content_status' => 'ok', 'last_content_error' => null, 'last_content_pushed_at' => now(), 'content_hash' => $hash]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_content_status' => 'failed', 'last_content_error' => $this->errorText($e)]);

            return 'failed';
        }
    }

    /**
     * Sidik jari SET foto master (koleksi master_image, urut: pertama = cover) — kunci diff-guard foto.
     * Berubah bila foto ditambah/dihapus/diurut-ulang (isi file tak pernah diganti di tempat: upload =
     * baris File baru). '' bila master tanpa foto.
     */
    public function photoHash(MarketplaceMaster $m): string
    {
        return $this->photoSetHash($m->sumberKonten()->filesIn(MarketplaceMaster::MASTER_IMAGE)->get());
    }

    /** Cast int: tipe angka dari driver DB (MySQL prod vs SQLite tes) tak boleh mengubah hash. */
    private function photoSetHash(Collection $files): string
    {
        if ($files->isEmpty()) {
            return '';
        }

        return md5(json_encode($files->map(fn (File $f) => [(int) $f->id, (int) $f->sort_order])->values()->all()));
    }

    /**
     * Dorong FOTO master ke SATU listing: upload semua foto (urut; pertama = cover) lalu GANTI SELURUH set
     * foto listing (TikTok main_images / Shopee image.image_id_list — marketplace tak bisa tambah satu-satu).
     * 'skip' bila master tanpa foto (set foto listing TAK dikosongkan; tanpa koneksi/HTTP apa pun) atau —
     * kecuali $force — set foto sama dgn push sukses terakhir (photo_hash). Satu foto gagal upload = set TAK
     * dikirim (tak ada ganti-parsial). Jejak di last_photo_* + photo_hash; gagal → pesan asli marketplace,
     * hash & waktu sukses terakhir dipertahankan (pola sama pushContent).
     *
     * $uploaded = cache upload SATU run (diisi pushMasterContent): ref urut (uri TikTok / image_id Shopee) per
     * channel + hash set foto. Listing berikutnya di channel yg sama tinggal di-set tanpa upload ulang (upload
     * sekuensial dalam 1 request web rawan timeout). Kunci ikut hash → ref tak mungkin nyasar ke set foto lain.
     */
    public function pushPhotos(MarketplaceListing $l, MarketplaceMaster $m, bool $force, array &$uploaded = []): string
    {
        $files = $m->sumberKonten()->filesIn(MarketplaceMaster::MASTER_IMAGE)->get(); // foto = level produk (induk)
        if ($files->isEmpty()) {
            return 'skip';
        }
        $hash = $this->photoSetHash($files);
        if (! $force && $l->photo_hash === $hash) {
            return 'skip';
        }
        $cacheKey = $l->channel.':'.$hash;
        try {
            // `??=` baru mengisi cache setelah SEMUA foto ter-upload: upload yg melempar exception tak
            // meninggalkan ref setengah jadi, jadi listing berikutnya upload ulang dari awal.
            if ($l->channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $token = $this->tiktokToken($c);
                $uris = $uploaded[$cacheKey] ??= $this->uploadPhotos($files, fn (string $bytes, string $name) => $this->tiktok->uploadImage($token, $c->shop_cipher, $bytes, $name));
                $this->tiktok->partialEditProduct($token, $c->shop_cipher, $l->item_id, ['main_images' => array_map(fn (string $uri) => ['uri' => $uri], $uris)]);
            } else {
                $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                $token = $this->shopeeToken($c);
                $ids = $uploaded[$cacheKey] ??= $this->uploadPhotos($files, fn (string $bytes, string $name) => $this->shopee->uploadImage($token, $c->shop_id, $bytes, $name));
                $this->shopee->updateItem($token, $c->shop_id, (int) $l->item_id, ['image' => ['image_id_list' => $ids]]);
            }
            $l->update(['last_photo_status' => 'ok', 'last_photo_error' => null, 'last_photo_pushed_at' => now(), 'photo_hash' => $hash]);

            // Foto milik PRODUK (item_id), listing per SKU: varian lain dari produk yang sama (mis. ditautkan
            // ke master lain) kini fotonya ikut tertimpa → hash-nya basi. Kosongkan hash sibling yang BERBEDA
            // supaya dorong berikutnya dari master itu mengirim ulang (klik terakhir yang menang, sama seperti
            // konten). Sibling ber-hash sama (master yang sama) dibiarkan agar diff-guard & cache tetap jalan.
            MarketplaceListing::where('channel', $l->channel)->where('item_id', $l->item_id)
                ->whereKeyNot($l->id)->where('photo_hash', '!=', $hash)->update(['photo_hash' => null]);

            return 'ok';
        } catch (\Throwable $e) {
            $l->update(['last_photo_status' => 'failed', 'last_photo_error' => $this->errorText($e)]);

            return 'failed';
        }
    }

    /**
     * Upload tiap foto (urut) lewat $upload(bytes, namaFile) → daftar ref urut. Nama file dibuat sendiri
     * (`foto-{id}.{ext}`), BUKAN original_name: tanda kutip di nama asli merusak header multipart. Ekstensi
     * ikut file tersimpan krn Content-Type bagian multipart diturunkan darinya.
     *
     * @param  callable(string, string): string  $upload
     * @return list<string>
     */
    private function uploadPhotos(Collection $files, callable $upload): array
    {
        $refs = [];
        foreach ($files as $f) {
            $bytes = Storage::disk($f->disk ?: 'public')->get($f->path)
                ?? throw new \RuntimeException("File foto #{$f->id} tak ditemukan di server — hapus lalu upload ulang foto itu di Produk Master.");
            $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string) $f->path, PATHINFO_EXTENSION))) ?: 'jpg';
            $refs[] = $upload($bytes, "foto-{$f->id}.{$ext}");
        }

        return $refs;
    }

    /**
     * Dorong konten + FOTO master ke SEMUA listing-nya yang sudah punya item_id. Tally per LISTING
     * (1 listing = 1 unit) — beda dgn pushMaster yg menghitung stok & harga sbg dua unit. Status listing =
     * gabungan konten & foto: failed bila salah satu gagal; pushed bila salah satu terkirim; else skipped.
     *
     * Dua mode:
     * - manual (tombol "Dorong", $otomatis=false): $force berlaku utk konten DAN foto — semua terdorong ulang.
     * - otomatis (tiap Simpan, $otomatis=true): diff-guard (tak dipaksa) & HANYA ke listing yang sudah pernah
     *   sukses didorong manual per bagian (konten: last_content_pushed_at; foto: last_photo_pushed_at) — dorong
     *   PERTAMA menimpa isi/foto listing, jadi tak boleh terjadi diam-diam dari Simpan.
     */
    public function pushMasterContent(MarketplaceMaster $m, bool $force = true, bool $otomatis = false): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        $uploaded = []; // cache upload foto run ini — lihat pushPhotos()
        $force = $force && ! $otomatis;
        // Seluruh keluarga (induk + varian): tiap listing didorong dgn master pemiliknya (konten diambil dari induk).
        foreach (MarketplaceListing::with('master')->whereIn('master_id', $m->keluargaIds())->whereNotNull('item_id')->orderBy('id')->get() as $l) {
            $pemilik = $l->master;
            $hasil = [
                $otomatis && ! $l->last_content_pushed_at ? 'skip' : $this->pushContent($l, $pemilik, $force),
                $otomatis && ! $l->last_photo_pushed_at ? 'skip' : $this->pushPhotos($l, $pemilik, $force, $uploaded),
            ];
            // tallyPush() menghitung stok+harga sbg DUA unit; di sini konten+foto = SATU unit per listing.
            match (true) {
                in_array('failed', $hasil, true) => $out['failed']++,
                in_array('ok', $hasil, true) => $out['pushed']++,
                default => $out['skipped']++,
            };
        }

        return $out;
    }

    // ---- Bundle ber-resep ----

    /** Isi bundle (dgn komponen) — pakai relasi ter-eager-load bila ada. Kosong = bukan bundle ber-resep. */
    private function isiBundle(MarketplaceMaster $m): \Illuminate\Support\Collection
    {
        return $m->relationLoaded('bundleItems') ? $m->bundleItems : $m->bundleItems()->with('component.channels')->get();
    }

    /**
     * Dorong stok bundle-bundle yang memakai master ini (stok komponen berubah → stok bundle ikut berubah).
     * Cron 5-menit juga menyusul lewat diff-guard; ini supaya marketplace langsung ter-update saat admin mengubah stok.
     */
    public function pushBundleTerkait(MarketplaceMaster $komponen): void
    {
        $ids = $komponen->dipakaiBundle()->pluck('bundle_id')->unique();
        foreach (MarketplaceMaster::whereIn('id', $ids)->get() as $b) {
            foreach ($b->listings()->whereNotNull('item_id')->get() as $l) {
                $this->pushListing($l);
            }
        }
    }

    // ---- Kategori marketplace (pemilih kategori + atribut per channel; pohon & ID TikTok ≠ Shopee) ----

    /** Pohon kategori mentah channel: [{id, parent, name, leaf}] — dari API, di-cache 1 hari (ribuan baris, jarang berubah). */
    public function kategoriPohon(string $channel): array
    {
        return Cache::remember("mp-pohon:{$channel}", 86400, function () use ($channel) {
            if ($channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');

                return collect($this->tiktok->getCategories($this->tiktokToken($c), (string) $c->shop_cipher)['categories'] ?? [])
                    ->map(fn ($x) => ['id' => (string) $x['id'], 'parent' => (string) ($x['parent_id'] ?? '0'), 'name' => (string) ($x['local_name'] ?? ''), 'leaf' => (bool) ($x['is_leaf'] ?? false)])
                    ->values()->all();
            }
            $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');

            return collect($this->shopee->getCategories($this->shopeeToken($c), $c->shop_id)['response']['category_list'] ?? [])
                ->map(fn ($x) => ['id' => (string) $x['category_id'], 'parent' => (string) ($x['parent_category_id'] ?? '0'),
                    'name' => (string) (($x['display_category_name'] ?? '') ?: ($x['original_category_name'] ?? '')), 'leaf' => ! ($x['has_children'] ?? false)])
                ->values()->all();
        });
    }

    /** Anak langsung satu kategori (parent '0' = level teratas), urut nama — untuk telusur bertingkat ala Seller Center. */
    public function anakKategori(string $channel, string $parent): array
    {
        $ids = collect($this->kategoriPohon($channel))->pluck('id')->flip();

        return collect($this->kategoriPohon($channel))
            // Akar = parent '0' ATAU parent yang tak ada di pohon (jaga-jaga format akar beda).
            ->filter(fn ($x) => $parent === '0' ? ($x['parent'] === '0' || ! isset($ids[$x['parent']])) : $x['parent'] === $parent)
            ->map(fn ($x) => ['id' => $x['id'], 'name' => $x['name'], 'leaf' => $x['leaf']])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Kategori DAUN channel: [{id, path "A > B > C"}] — diturunkan dari pohon (cache yang sama). */
    public function kategoriDaun(string $channel): array
    {
        $byId = collect($this->kategoriPohon($channel))->keyBy('id');

        return $byId->where('leaf', true)->map(function ($x) use ($byId) {
            $path = [$x['name']];
            for ($p = $x['parent'], $i = 0; isset($byId[$p]) && $i < 10; $p = $byId[$p]['parent'], $i++) {
                array_unshift($path, $byId[$p]['name']);
            }

            return ['id' => $x['id'], 'path' => implode(' > ', $path)];
        })->values()->all();
    }

    /** Cari kategori daun: semua kata kunci harus ada di jalur kategori (tak peka huruf besar). */
    public function cariKategori(string $channel, string $q, int $limit = 50): array
    {
        $kata = array_filter(preg_split('/\s+/', mb_strtolower(trim($q))));

        return collect($this->kategoriDaun($channel))
            ->filter(fn ($k) => collect($kata)->every(fn ($w) => str_contains(mb_strtolower($k['path']), $w)))
            ->take($limit)->values()->all();
    }

    /**
     * Atribut kategori daun ternormalisasi utk form: ['attributes' => [{id, name, required, multi, custom, units,
     * values:[{id,name}]}], 'brands' => [{id,name}]|null, 'brand_required' => bool]. Di-cache 1 hari per kategori.
     * TikTok: atribut SALES_PROPERTY (warna/ukuran varian = level SKU) dibuang — yang diisi hanya atribut produk.
     */
    public function atributKategori(string $channel, string $categoryId): array
    {
        return Cache::remember("mp-atribut:{$channel}:{$categoryId}", 86400, function () use ($channel, $categoryId) {
            if ($channel === 'tiktok') {
                $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                $attrs = collect($this->tiktok->getCategoryAttributes($this->tiktokToken($c), (string) $c->shop_cipher, $categoryId)['attributes'] ?? [])
                    ->reject(fn ($a) => ($a['type'] ?? '') === 'SALES_PROPERTY')
                    ->map(fn ($a) => [
                        'id' => (string) $a['id'], 'name' => (string) ($a['name'] ?? ''),
                        'required' => (bool) ($a['is_requried'] ?? $a['is_required'] ?? false), // ejaan "requried" milik API
                        'multi' => (bool) ($a['is_multiple_selection'] ?? false), 'custom' => (bool) ($a['is_customizable'] ?? false), 'units' => [],
                        'values' => collect($a['values'] ?? [])->map(fn ($v) => ['id' => (string) $v['id'], 'name' => (string) ($v['name'] ?? '')])->values()->all(),
                    ]);

                return ['attributes' => $attrs->values()->all(), 'brands' => null, 'brand_required' => false];
            }

            $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
            $token = $this->shopeeToken($c);
            $tree = $this->shopee->getAttributeTree($token, $c->shop_id, (int) $categoryId)['response']['list'][0]['attribute_tree'] ?? [];
            $attrs = collect($tree)->map(function ($a) {
                $input = (int) ($a['attribute_info']['input_type'] ?? 1); // 1 dropdown, 2 combo, 3 teks, 4 multi-dropdown, 5 multi-combo
                $nama = collect($a['multi_lang'] ?? [])->firstWhere('language', 'id')['value'] ?? ($a['name'] ?? '');

                return [
                    'id' => (string) $a['attribute_id'], 'name' => (string) $nama, 'required' => (bool) ($a['mandatory'] ?? false),
                    'multi' => in_array($input, [4, 5], true), 'custom' => in_array($input, [2, 3, 5], true),
                    'units' => array_values(array_map('strval', $a['attribute_info']['attribute_unit_list'] ?? [])),
                    'values' => collect($a['attribute_value_list'] ?? [])->map(fn ($v) => [
                        'id' => (string) $v['value_id'],
                        'name' => (string) (collect($v['multi_lang'] ?? [])->firstWhere('language', 'id')['value'] ?? ($v['name'] ?? '')),
                    ])->values()->all(),
                ];
            });

            $brands = [];
            $wajib = false;
            for ($offset = 0, $hal = 0; $hal < 5; $hal++) { // ponytail: maks 500 merek; kategori dgn merek lebih banyak terpotong
                $r = $this->shopee->getBrandList($token, $c->shop_id, (int) $categoryId, $offset)['response'] ?? [];
                $wajib = $wajib || (bool) ($r['is_mandatory'] ?? false);
                foreach ($r['brand_list'] ?? [] as $b) {
                    $brands[] = ['id' => (string) $b['brand_id'], 'name' => (string) (($b['display_brand_name'] ?? '') ?: ($b['original_brand_name'] ?? ''))];
                }
                if (! ($r['has_next_page'] ?? false)) {
                    break;
                }
                $offset = (int) ($r['next_offset'] ?? $offset + 100);
            }

            return ['attributes' => $attrs->values()->all(), 'brands' => $brands, 'brand_required' => $wajib];
        });
    }

    /**
     * Tarik kategori + atribut (+ merek Shopee) yang SEKARANG terpasang di listing tertaut ke master — titik awal
     * aman supaya dorong berikutnya tak mengosongkan atribut. Per channel: listing pertama ber-item_id.
     *
     * @return array<string,string> channel => 'ok' | pesan gagal (channel tanpa listing tak muncul)
     */
    public function tarikKategori(MarketplaceMaster $m): array
    {
        $hasil = [];
        foreach (['tiktok', 'shopee'] as $channel) {
            $l = $m->listings()->where('channel', $channel)->whereNotNull('item_id')->first();
            if (! $l) {
                continue;
            }
            try {
                if ($channel === 'tiktok') {
                    $c = $this->tiktokConn() ?? throw new \RuntimeException('TikTok belum terhubung');
                    $p = $this->tiktok->getProduct($this->tiktokToken($c), (string) $c->shop_cipher, (string) $l->item_id);
                    $chain = collect($p['category_chains'] ?? []);
                    $daun = $chain->firstWhere('is_leaf', true) ?? $chain->last();
                    if (! $daun) {
                        throw new \RuntimeException('produk TikTok tanpa kategori');
                    }
                    $m->update([
                        'tiktok_category_id' => (string) $daun['id'],
                        'tiktok_category_name' => $chain->pluck('local_name')->implode(' > '),
                        'tiktok_attributes' => collect($p['product_attributes'] ?? [])->map(fn ($a) => [
                            'id' => (string) $a['id'],
                            'values' => collect($a['values'] ?? [])->map(fn ($v) => ['id' => (string) ($v['id'] ?? ''), 'name' => (string) ($v['name'] ?? '')])->values()->all(),
                        ])->values()->all(),
                    ]);
                } else {
                    $c = $this->shopeeConn() ?? throw new \RuntimeException('Shopee belum terhubung');
                    $item = $this->shopee->getItemBaseInfo($this->shopeeToken($c), $c->shop_id, [(int) $l->item_id])['response']['item_list'][0] ?? null;
                    if (! $item || empty($item['category_id'])) {
                        throw new \RuntimeException('item Shopee tak ditemukan / tanpa kategori');
                    }
                    $id = (string) $item['category_id'];
                    $path = collect($this->kategoriDaun('shopee'))->firstWhere('id', $id)['path'] ?? $id;
                    $brand = $item['brand'] ?? null;
                    $m->update([
                        'shopee_category_id' => $id,
                        'shopee_category_name' => $path,
                        'shopee_attributes' => collect($item['attribute_list'] ?? [])->map(fn ($a) => [
                            'id' => (string) $a['attribute_id'],
                            'values' => collect($a['attribute_value_list'] ?? [])->map(fn ($v) => array_filter([
                                'id' => (string) ($v['value_id'] ?? 0) === '0' ? '' : (string) $v['value_id'],
                                'name' => (string) ($v['original_value_name'] ?? ''),
                                'unit' => (string) ($v['value_unit'] ?? ''),
                            ], fn ($x, $k) => $k !== 'unit' || $x !== '', ARRAY_FILTER_USE_BOTH))->values()->all(),
                        ])->values()->all(),
                        'shopee_brand' => $brand ? ['brand_id' => (int) ($brand['brand_id'] ?? 0), 'original_brand_name' => (string) ($brand['original_brand_name'] ?? '')] : null,
                    ]);
                }
                $hasil[$channel] = 'ok';
            } catch (\Throwable $e) {
                $hasil[$channel] = $this->errorText($e);
            }
        }

        return $hasil;
    }

    /**
     * Kategori master boleh dikirim ke listing ini? Kategori = level PRODUK: tolak bila varian lain dari produk yg
     * sama tertaut ke master LAIN yg memilih kategori berbeda di channel itu (saling timpa tiap Simpan).
     */
    private function kategoriBoleh(MarketplaceMaster $m, MarketplaceListing $l, string $kolom): bool
    {
        // Master lain (di luar keluarga induk ini) yg tertaut ke produk yg sama & memilih kategori berbeda → tahan.
        return ! MarketplaceListing::with('master.parent')->where('channel', $l->channel)->where('item_id', $l->item_id)
            ->whereNotNull('master_id')->whereNotIn('master_id', $m->keluargaIds())->get()
            ->contains(function ($x) use ($kolom, $m) {
                $lain = $x->master?->sumberKonten();

                return $lain && $lain->{$kolom} !== null && (string) $lain->{$kolom} !== (string) $m->{$kolom};
            });
    }

    /** Payload kategori+atribut per channel (bagian buildContentPayload). [] bila master belum memilih kategori. */
    private function kategoriPayload(MarketplaceMaster $m, MarketplaceListing $l): array
    {
        $m = $m->sumberKonten();
        if ($l->channel === 'tiktok') {
            if (! $m->tiktok_category_id || ! $this->kategoriBoleh($m, $l, 'tiktok_category_id')) {
                return [];
            }

            // Ganti kategori di TikTok MENGHAPUS atribut lama → atribut selalu ikut dikirim bersama kategori.
            return [
                'category_id' => (string) $m->tiktok_category_id,
                'product_attributes' => collect($m->tiktok_attributes ?? [])->map(fn ($a) => [
                    'id' => (string) $a['id'],
                    'values' => collect($a['values'] ?? [])->map(fn ($v) => ($v['id'] ?? '') !== '' ? ['id' => (string) $v['id'], 'name' => (string) ($v['name'] ?? '')] : ['name' => (string) ($v['name'] ?? '')])->values()->all(),
                ])->filter(fn ($a) => $a['values'] !== [])->values()->all(),
            ];
        }
        if (! $m->shopee_category_id || ! $this->kategoriBoleh($m, $l, 'shopee_category_id')) {
            return [];
        }
        $payload = [
            'category_id' => (int) $m->shopee_category_id,
            'attribute_list' => collect($m->shopee_attributes ?? [])->map(fn ($a) => [
                'attribute_id' => (int) $a['id'],
                'attribute_value_list' => collect($a['values'] ?? [])->map(fn ($v) => array_filter([
                    'value_id' => (int) ($v['id'] ?? 0), // 0 = nilai isian bebas → original_value_name
                    'original_value_name' => (string) ($v['name'] ?? ''),
                    'value_unit' => (string) ($v['unit'] ?? ''),
                ], fn ($x, $k) => $k !== 'value_unit' || $x !== '', ARRAY_FILTER_USE_BOTH))->values()->all(),
            ])->filter(fn ($a) => $a['attribute_value_list'] !== [])->values()->all(),
        ];
        if (is_array($m->shopee_brand) && isset($m->shopee_brand['brand_id'])) {
            $payload['brand'] = ['brand_id' => (int) $m->shopee_brand['brand_id'], 'original_brand_name' => (string) ($m->shopee_brand['original_brand_name'] ?? '')];
        }

        return $payload;
    }

    // ---- Cermin order (aditif; HQ TAK disentuh) ----

    /** Cermin order marketplace → turunkan/kembalikan bucket efektif stok master (HQ TAK disentuh). */
    public function applyOrderDelta(MarketplaceListing $listing, int $delta, Carbon $orderCreatedAt): void
    {
        if (! $listing->master_id) {
            return;
        }
        $master = $listing->master;
        if (! $master) {
            return;
        }
        // Bundle ber-resep: yang berkurang/bertambah stok KOMPONENNYA (qty × delta); stok bundle ikut terhitung ulang.
        $isi = $this->isiBundle($master);
        if ($isi->isNotEmpty()) {
            foreach ($isi as $it) {
                if ($it->component) {
                    $this->applyDeltaMaster($it->component, $listing->channel, $delta * $it->qty, $orderCreatedAt);
                }
            }

            return;
        }
        $this->applyDeltaMaster($master, $listing->channel, $delta, $orderCreatedAt);
    }

    /** Terapkan delta stok ke satu master (override channel bila ada, else base_stock) dgn guard seeded_at. */
    private function applyDeltaMaster(MarketplaceMaster $master, string $channel, int $delta, Carbon $orderCreatedAt): void
    {
        $override = MarketplaceMasterChannel::where('master_id', $master->id)->where('channel', $channel)->first();

        if ($override && $override->stock !== null) {
            if ($override->seeded_at === null || $orderCreatedAt->lt($override->seeded_at)) {
                return;
            }
            $override->update(['stock' => max(0, (int) $override->stock + $delta)]);

            return;
        }

        if ($master->base_stock === null || $master->seeded_at === null || $orderCreatedAt->lt($master->seeded_at)) {
            return;
        }
        $master->update(['base_stock' => max(0, (int) $master->base_stock + $delta)]);
    }

    /**
     * Upsert baris listing by (channel, seller_sku); master_id dibiarkan apa
     * adanya — penautan ke master dilakukan manual (lewat modal Kaitkan / linkListings()).
     */
    private function upsertListing(string $channel, string $sellerSku, string $itemId, string $variationId, ?string $warehouseId, ?string $title): MarketplaceListing
    {
        return MarketplaceListing::updateOrCreate(
            ['channel' => $channel, 'seller_sku' => $sellerSku],
            ['item_id' => $itemId, 'variation_id' => $variationId, 'warehouse_id' => $warehouseId, 'title' => $title, 'resolved_at' => now()],
        );
    }

    /**
     * NB: TikTokClient::request() sudah unwrap ke $json['data'], jadi TANPA
     * prefiks 'data.' di path data_get() bawah ini (sama spt
     * MarketplaceStockService::resolveTiktok()).
     */
    private function resolveTiktok(): array
    {
        $c = $this->tiktokConn();
        if (! $c) {
            return ['found' => 0];
        }
        $tok = $this->tiktokToken($c);
        // Gudang default toko = FALLBACK saja (dipakai kalau SKU tak bawa
        // inventory.warehouse_id sendiri). Per-SKU pakai warehouse ASLI-nya (lihat
        // loop di bawah) supaya update stok tak ditolak TikTok (error 12052533).
        // SALES diutamakan; kalau tak ada, gudang pertama drpd null utk semua.
        $warehouses = data_get($this->tiktok->getWarehouses($tok, $c->shop_cipher), 'warehouses', []);
        $warehouseId = null;
        foreach ($warehouses as $w) {
            if (($w['type'] ?? null) === 'SALES_WAREHOUSE') {
                $warehouseId = (string) $w['id'];
                break;
            }
        }
        if ($warehouseId === null && $warehouses) {
            $warehouseId = (string) data_get($warehouses[0], 'id');
        }

        $found = 0;
        $pageToken = '';
        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->tiktok->searchProducts($tok, $c->shop_cipher, 50, $pageToken);
            foreach (data_get($res, 'products', []) as $prod) {
                $pid = (string) data_get($prod, 'id', '');
                $title = data_get($prod, 'title');
                foreach ($prod['skus'] ?? [] as $sku) {
                    $sellerSku = (string) data_get($sku, 'seller_sku', '');
                    if ($sellerSku === '') {
                        continue;
                    }
                    // Pakai warehouse ASLI milik SKU (dari inventory-nya). TikTok TOLAK
                    // update stok kalau warehouse_id beda dari gudang asal SKU (error
                    // 12052533 "warehouse changes are not permitted"). Gudang default toko
                    // ($warehouseId) cuma FALLBACK bila SKU tak bawa info inventory.
                    $skuWarehouse = data_get($sku, 'inventory.0.warehouse_id');
                    $skuWarehouse = $skuWarehouse !== null && $skuWarehouse !== '' ? (string) $skuWarehouse : $warehouseId;
                    $this->upsertListing('tiktok', $sellerSku, $pid, (string) data_get($sku, 'id', ''), $skuWarehouse, $title !== null ? (string) $title : null);
                    $found++;
                }
            }
            $pageToken = (string) data_get($res, 'next_page_token', '');
            if ($pageToken === '') {
                break;
            }
            if ($guard === 199) {
                Log::warning('[mp-master:resolve] TikTok mentok 200 halaman.');
            }
        }

        return ['found' => $found];
    }

    /**
     * Paging & bentuk respons IDENTIK dgn MarketplaceStockService::resolveShopee():
     * get_item_base_info di-batch SEKALI per halaman (bukan per-item) & get_model_list
     * hanya dipanggil kalau item punya varian (`has_model`) — hemat panggilan API
     * persis spt service lama.
     */
    private function resolveShopee(): array
    {
        $c = $this->shopeeConn();
        if (! $c) {
            return ['found' => 0];
        }

        $tok = $this->shopeeToken($c);
        $found = 0;
        $offset = 0;

        for ($guard = 0; $guard < 200; $guard++) {
            $res = $this->shopee->getItemList($tok, $c->shop_id, $offset, 50);
            $items = data_get($res, 'response.item', []);
            $itemIds = array_values(array_filter(array_map(fn ($i) => (int) ($i['item_id'] ?? 0), $items)));

            if ($itemIds !== []) {
                $base = $this->shopee->getItemBaseInfo($tok, $c->shop_id, $itemIds);
                foreach (data_get($base, 'response.item_list', []) as $info) {
                    $itemId = (string) ($info['item_id'] ?? '');
                    if ($itemId === '') {
                        continue;
                    }
                    $title = $info['item_name'] ?? null;

                    if (! empty($info['has_model'])) {
                        $models = data_get($this->shopee->getModelList($tok, $c->shop_id, (int) $itemId), 'response.model', []);
                        foreach ($models as $mo) {
                            $sellerSku = (string) ($mo['model_sku'] ?? '');
                            if ($sellerSku === '') {
                                continue;
                            }
                            $this->upsertListing('shopee', $sellerSku, $itemId, (string) ($mo['model_id'] ?? '0'), null, $title);
                            $found++;
                        }
                    } else {
                        $sellerSku = (string) ($info['item_sku'] ?? '');
                        if ($sellerSku === '') {
                            continue;
                        }
                        $this->upsertListing('shopee', $sellerSku, $itemId, '0', null, $title);
                        $found++;
                    }
                }
            }

            $offset += count($items);
            $hasNext = (bool) data_get($res, 'response.has_next_page', false);
            if (! $hasNext || $items === []) {
                break;
            }
            if ($guard === 199) {
                Log::warning('[mp-master:resolve] Shopee mentok 200 halaman.');
            }
        }

        return ['found' => $found];
    }
}
