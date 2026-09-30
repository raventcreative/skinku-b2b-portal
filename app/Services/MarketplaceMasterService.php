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

    // ---- Dorong KONTEN produk (deskripsi/berat/dimensi + foto) — jalur TERPISAH dari stok/harga ----
    //
    // Sengaja TIDAK lewat pushListing/pushDirty/pushEach: konten & foto dikirim manual (tombol +
    // konfirmasi), bukan oleh cron 5-menit. Nama/judul TIDAK ikut didorong.

    /**
     * Payload konten partial per-channel — HANYA field yang terisi (skip-empty), supaya master
     * yang belum lengkap tak menimpa listing yang sudah bagus dengan kosong. Sudah konversi
     * satuan (gram → kg). `[]` bila tak ada satu pun yang dikirim.
     *
     * TIDAK pernah memuat `item_id` (ShopeeClient::updateItem menambahkannya sendiri lewat
     * array_merge, jadi key yang sama di sini akan menimpanya) maupun nama/judul.
     */
    public function buildContentPayload(MarketplaceMaster $m, string $channel): array
    {
        $tiktok = $channel === 'tiktok';
        $payload = [];

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

        return $payload;
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
        $payload = $this->buildContentPayload($m, $l->channel);
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
        return $this->photoSetHash($m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get());
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
        $files = $m->filesIn(MarketplaceMaster::MASTER_IMAGE)->get();
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
     * $force HANYA utk konten. Foto SELALU lewat diff-guard (force=false): user dijanjikan foto listing cuma
     * diganti bila set foto master berubah — klik berulang utk update teks tak boleh terus meng-upload &
     * mengganti foto (sekaligus mencegah upload ulang tiap klik yang rawan timeout).
     */
    public function pushMasterContent(MarketplaceMaster $m, bool $force = true): array
    {
        $out = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];
        $uploaded = []; // cache upload foto run ini — lihat pushPhotos()
        foreach ($m->listings()->whereNotNull('item_id')->get() as $l) {
            $hasil = [$this->pushContent($l, $m, $force), $this->pushPhotos($l, $m, false, $uploaded)];
            // tallyPush() menghitung stok+harga sbg DUA unit; di sini konten+foto = SATU unit per listing.
            match (true) {
                in_array('failed', $hasil, true) => $out['failed']++,
                in_array('ok', $hasil, true) => $out['pushed']++,
                default => $out['skipped']++,
            };
        }

        return $out;
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
        $channel = $listing->channel;
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
