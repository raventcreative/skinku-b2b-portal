<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolContentDailySnapshot;
use App\Models\KolCreatorContent;
use App\Models\KolCreatorContentStat;
use App\Models\KolTiktokProfile;
use App\Models\KolTiktokSnapshot;
use App\Models\KolUsernameAlias;
use App\Models\TiktokAffiliateConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sinkron order affiliate TikTok (Affiliate Seller API) → pipeline
 * KolAffiliateTransaction (source='tiktok_api') yang sama dipakai Tim Gapok &
 * halaman Affiliate. Token/refresh dikelola di sini; pemetaan respons TikTok
 * (mapOrders) dibuat murni supaya bisa dites dengan JSON asli dari probe.
 */
class TikTokAffiliateService
{
    private TikTokClient $client;

    public function __construct(private KolAffiliateService $affiliate)
    {
        $this->client = new TikTokClient('tiktok_affiliate');
    }

    public function client(): TikTokClient
    {
        return $this->client;
    }

    /**
     * Tarik SEMUA order affiliate di rentang [from,to] (paginasi) → import ke
     * pipeline. Return {imported,matched,unmatched,pages}.
     */
    public function syncOrders(TiktokAffiliateConnection $conn, Carbon $from, Carbon $to, ?int $actorId, int $maxPages = 100): array
    {
        $access = $this->freshToken($conn);
        $rows = [];
        $pageToken = '';
        $pages = 0;

        do {
            $data = $this->client->searchSellerAffiliateOrders(
                $access, (string) $conn->shop_cipher, 100, $pageToken, $from->timestamp, $to->timestamp
            );
            $rows = array_merge($rows, $this->mapOrders($data));
            $pageToken = (string) ($data['next_page_token'] ?? '');
            $pages++;
        } while ($pageToken !== '' && $pages < $maxPages);

        $res = $this->affiliate->import($rows, 'tiktok', $actorId, 'tiktok_api');
        $conn->update(['last_synced_at' => now()]);

        return $res + ['pages' => $pages];
    }

    /**
     * Sync jumlah video & LIVE per kreator (bulan tsb) dari Analytics API →
     * kol_creator_content_stats. Page-through semua (di-cap), tally per username,
     * simpan untuk kreator yang cocok ke KOL. Berat → dipakai cron, bukan tombol web.
     *
     * @return array{videos:int,lives:int,creators:int}
     */
    public function syncContentStats(TiktokAffiliateConnection $conn, Carbon $month, int $maxPages = 60): array
    {
        $access = $this->freshToken($conn);
        $cipher = (string) $conn->shop_cipher;
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->addDay()->toDateString(); // end_date_lt eksklusif
        $period = $month->copy()->startOfMonth()->toDateString();

        $videos = $this->collect(fn ($pt) => $this->client->getShopVideoPerformance($access, $cipher, $start, $end, 100, $pt), 'videos', 'video', $maxPages);
        $lives = $this->collect(fn ($pt) => $this->client->getShopLivePerformance($access, $cipher, $start, $end, 100, $pt), 'live_stream_sessions', 'live', $maxPages);

        $usernames = array_unique(array_merge(array_keys($videos), array_keys($lives)));
        $stored = $vTotal = $lTotal = 0;
        foreach ($usernames as $u) {
            $kolId = $this->kolIdUntuk($u);
            if (! $kolId) {
                continue; // bukan KOL → tak disimpan
            }
            $vids = $videos[$u] ?? [];
            $lvs = $lives[$u] ?? [];
            $vTotal += count($vids);
            $lTotal += count($lvs);

            KolCreatorContentStat::updateOrCreate(
                ['kol_id' => $kolId, 'period' => $period],
                ['videos' => count($vids), 'lives' => count($lvs)],
            );

            // Snapshot detail: ganti isi bulan ini utk kreator ini.
            KolCreatorContent::where('kol_id', $kolId)->where('period', $period)->delete();
            $rows = [];
            foreach (array_merge($vids, $lvs) as $it) {
                $rows[] = $it + ['kol_id' => $kolId, 'period' => $period, 'created_at' => now(), 'updated_at' => now()];
            }
            if ($rows !== []) {
                KolCreatorContent::insert($rows);
            }
            // Riwayat harian video (views harian = selisih antar potret). Idempoten per hari.
            $this->tulisPotretHarian($kolId, $vids, $period, now()->toDateString());
            $stored++;
        }
        $conn->update(['last_synced_at' => now()]);

        return ['videos' => $vTotal, 'lives' => $lTotal, 'creators' => $stored];
    }

    /**
     * Isi mundur SATU potret harian yang terlewat (mis. sebelum report views harian aktif): minta data kumulatif
     * bulan s/d hari sebelum $capturedOn (start = awal bulan hari itu, end_date_lt = $capturedOn) — persis yang
     * didapat potret jam 04:00 pagi itu — lalu, bila $simpan, tulis sbg potret captured_on = $capturedOn. Hanya
     * potret harian video; data bulanan Tim Gapok (kol_creator_content*) tak disentuh.
     *
     * @return array{videos:int,kreator:int,views:int,gmv:int,ditulis:int,rentang:string}
     */
    public function isiMundurPotretHarian(TiktokAffiliateConnection $conn, Carbon $capturedOn, bool $simpan, int $maxPages = 60): array
    {
        $tgl = $capturedOn->copy()->startOfDay();
        $hari = $tgl->copy()->subDay();
        $period = $hari->copy()->startOfMonth()->toDateString();
        $access = $this->freshToken($conn);
        $videos = $this->collect(fn ($pt) => $this->client->getShopVideoPerformance($access, (string) $conn->shop_cipher, $period, $tgl->toDateString(), 100, $pt), 'videos', 'video', $maxPages);

        $out = ['videos' => 0, 'kreator' => 0, 'views' => 0, 'gmv' => 0, 'ditulis' => 0, 'rentang' => "{$period} s/d {$hari->toDateString()}"];
        $perKol = [];
        foreach ($videos as $u => $vids) {
            $kolId = $this->kolIdUntuk($u);
            if (! $kolId) {
                continue; // bukan KOL → tak disimpan (sama spt sync harian)
            }
            $out['kreator']++;
            $out['videos'] += count($vids);
            $out['views'] += array_sum(array_column($vids, 'views'));
            $out['gmv'] += array_sum(array_column($vids, 'gmv'));
            $perKol[$kolId] = array_merge($perKol[$kolId] ?? [], $vids);
        }
        if ($simpan && $perKol !== []) {
            // Satu tanggal potret = satu transaksi: terputus di tengah (mis. SSH putus) → tak ada tanggal setengah
            // tersimpan yang kelak dilewati sbg "sudah ada" saat perintah dijalankan ulang.
            DB::transaction(function () use ($perKol, $period, $tgl, &$out) {
                foreach ($perKol as $kolId => $vids) {
                    $out['ditulis'] += $this->tulisPotretHarian($kolId, $vids, $period, $tgl->toDateString());
                }
            });
        }

        return $out;
    }

    /** Username TikTok (lowercase) → id KOL (kolom tiktok_username atau alias); null = bukan KOL. */
    private function kolIdUntuk(string $username): ?int
    {
        $id = Kol::whereRaw('LOWER(tiktok_username) = ?', [$username])->value('id')
            ?? KolUsernameAlias::where('username', $username)->value('kol_id');

        return $id ? (int) $id : null;
    }

    /** Tulis potret harian video satu kreator (views harian = selisih antar potret). Idempoten per tanggal potret. */
    private function tulisPotretHarian(int $kolId, array $vids, string $period, string $capturedOn): int
    {
        $n = 0;
        foreach ($vids as $v) {
            if (($v['content_id'] ?? '') === '') {
                continue;
            }
            KolContentDailySnapshot::updateOrCreate(
                ['content_id' => $v['content_id'], 'period' => $period, 'captured_on' => $capturedOn],
                ['kol_id' => $kolId, 'title' => $v['title'] ?: null, 'posted_at' => $v['occurred_at'] ?? null,
                    'views' => (int) ($v['views'] ?? 0), 'gmv' => (int) ($v['gmv'] ?? 0), 'items_sold' => (int) ($v['items_sold'] ?? 0)],
            );
            $n++;
        }

        return $n;
    }

    /**
     * Page-through list API → kumpulkan item ternormalisasi per username (lowercase).
     * Berhenti saat token habis / berulang (loop tak maju) / kena cap halaman.
     *
     * @return array<string,array<int,array<string,mixed>>> username => item[]
     */
    private function collect(callable $fetch, string $listKey, string $type, int $maxPages): array
    {
        $byUser = [];
        $pt = '';
        $seen = [];
        for ($page = 0; $page < $maxPages; $page++) {
            $data = $fetch($pt);
            foreach (($data[$listKey] ?? []) as $item) {
                $u = mb_strtolower(trim((string) ($item['username'] ?? data_get($item, 'creator.user_name', ''))));
                if ($u !== '') {
                    $byUser[$u][] = $this->normalizeContent($item, $type);
                }
            }
            $pt = (string) ($data['next_page_token'] ?? '');
            if ($pt === '' || isset($seen[$pt])) {
                break;
            }
            $seen[$pt] = true;
        }
        if ($page >= $maxPages && $pt !== '') {
            // Masih ada halaman tapi kena batas → data terpotong. Report views harian mengandalkan potret LENGKAP
            // (video absen = 0 views), jadi jangan sampai terpotong diam-diam.
            Log::warning("[tiktok-affiliate] {$type}: berhenti di batas {$maxPages} halaman, data terpotong — naikkan maxPages.");
        }

        return $byUser;
    }

    /** Item API video/LIVE → baris kol_creator_contents (tanpa kol_id/period). */
    private function normalizeContent(array $item, string $type): array
    {
        if ($type === 'video') {
            return [
                'type' => 'video',
                'content_id' => (string) ($item['id'] ?? ''),
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
                'views' => (int) ($item['views'] ?? 0),
                'gmv' => (int) round((float) data_get($item, 'gmv.amount', 0)),
                'items_sold' => (int) ($item['items_sold'] ?? 0),
                'sku_orders' => (int) ($item['sku_orders'] ?? 0),
                'occurred_at' => $item['video_post_time'] ?? null,
            ];
        }

        return [
            'type' => 'live',
            'content_id' => (string) ($item['id'] ?? ''),
            'title' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
            'views' => null,
            'gmv' => (int) round((float) data_get($item, 'sales_performance.gmv.amount', 0)),
            'items_sold' => (int) data_get($item, 'sales_performance.items_sold', 0),
            'sku_orders' => (int) data_get($item, 'sales_performance.sku_orders', 0),
            'occurred_at' => isset($item['start_time']) ? Carbon::createFromTimestamp((int) $item['start_time'])->toDateTimeString() : null,
        ];
    }

    /**
     * Cari kreator di Creator Marketplace TikTok by keyword (username/nickname) →
     * daftar kreator ternormalisasi untuk screening (GMV 30 hari, follower, dsb),
     * TERMASUK yang belum pernah jadi affiliate kita. Butuh scope
     * seller.creator_marketplace.read. Hasil di-map lewat mapMarketplaceCreator
     * supaya bisa dites dengan JSON asli dari probe.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchCreators(TiktokAffiliateConnection $conn, string $keyword, int $pageSize = 12): array
    {
        $access = $this->freshToken($conn);
        $data = $this->client->searchMarketplaceCreators($access, (string) $conn->shop_cipher, $keyword, $pageSize);
        $conn->update(['last_synced_at' => now()]);

        return array_map(fn ($c) => $this->mapMarketplaceCreator($c), $data['creators'] ?? []);
    }

    /**
     * Tulis satu kreator (ternormalisasi, hasil mapMarketplaceCreator ATAU subset
     * {username,open_id,followers,gmv_usd}) ke record Database KOL-nya:
     *  - kols.followers + tiktok_checked_at
     *  - GMV asli → screening terbaru (kolom "GMV Asli") bila ada
     *  - snapshot penuh → kol_tiktok_profiles
     * Dipakai bareng oleh tombol "Simpan ke Database" & sync massal.
     *
     * @return array{gmv_saved:bool,gmv_idr:int|null}
     */
    public function applyCreatorToKol(Kol $kol, array $c): array
    {
        $rate = (int) config('services.tiktok_affiliate.usd_idr_rate', 16000);
        $idr = fn (?float $usd) => $usd === null ? null : (int) round($usd * $rate);

        $followers = isset($c['followers']) ? (int) $c['followers'] : null;
        $kol->update(array_filter([
            'followers' => $followers,
            'tiktok_checked_at' => now(),
        ], fn ($v) => $v !== null));

        // GMV asli → screening terbaru (kolom "GMV Asli" nempel di screening).
        $gmvIdr = $idr(isset($c['gmv_usd']) ? (float) $c['gmv_usd'] : null);
        $gmvSaved = false;
        if ($gmvIdr !== null && ($screening = $kol->latestScreening()->first())) {
            $screening->update(['gmv' => $gmvIdr]);
            $gmvSaved = true;
        }

        // Snapshot: null di-buang (updateOrCreate tak menghapus nilai lama saat data
        // parsial, mis. dari tombol tanpa cache).
        $ageLabel = fn ($a) => str_replace(['AGE_RANGE_', '_'], ['', '–'], (string) $a);
        $ages = $c['age_ranges'] ?? null;
        KolTiktokProfile::updateOrCreate(['kol_id' => $kol->id], array_filter([
            'open_id' => ($c['open_id'] ?? '') ?: null,
            'followers' => $followers,
            'gmv_usd' => isset($c['gmv_usd']) ? (float) $c['gmv_usd'] : null,
            'gmv_idr' => $gmvIdr,
            'gmv_range' => ($c['gmv_range'] ?? '') ?: null,
            'video_gmv_idr' => $idr(isset($c['video_gmv_usd']) ? (float) $c['video_gmv_usd'] : null),
            'live_gmv_idr' => $idr(isset($c['live_gmv_usd']) ? (float) $c['live_gmv_usd'] : null),
            'avg_video_views' => isset($c['avg_video_views']) ? (int) $c['avg_video_views'] : null,
            'avg_live_uv' => isset($c['avg_live_uv']) ? (int) $c['avg_live_uv'] : null,
            'region' => ($c['region'] ?? '') ?: null,
            'gender' => ($c['gender'] ?? '') ?: null,
            'gender_pct' => ($c['gender_pct'] ?? 0) ?: null,
            'age_ranges' => is_array($ages) && $ages !== [] ? implode(', ', array_map($ageLabel, $ages)) : null,
            'usd_idr_rate' => $rate,
            'synced_at' => now(),
        ], fn ($v) => $v !== null));

        return ['gmv_saved' => $gmvSaved, 'gmv_idr' => $gmvIdr];
    }

    /**
     * Performa 30 hari satu kreator (endpoint marketplace_creators/{open_id}) →
     * angka siap simpan, SEMUA uang sudah Rupiah. MURNI (tanpa I/O) → dites
     * dengan JSON asli dari probe. Rate/persen TikTok berbasis 10.000
     * (250 = 2,5%; 700 = 7%). GPM = GMV per 1.000 views.
     *
     * @return array<string,mixed>
     */
    public function mapCreatorPerformance(array $data): array
    {
        $c = (array) ($data['creator'] ?? $data);
        $rate = (int) config('services.tiktok_affiliate.usd_idr_rate', 16000);
        $idr = function (string $key) use ($c, $rate): ?int {
            $v = data_get($c, $key.'.amount');

            return ($v === null || $v === '') ? null : (int) round((float) $v * $rate);
        };
        $pct = fn (string $key) => isset($c[$key]) && $c[$key] !== '' ? round((float) $c[$key] / 100, 2) : null;
        $int = fn (string $key) => isset($c[$key]) ? (int) $c[$key] : null;

        return [
            'followers' => $int('follower_count'),
            'avg_video_views' => $int('avg_ec_video_play_count'),
            'avg_live_uv' => $int('avg_ec_live_view_count'),
            'video_count' => $int('ec_video_count'),
            'live_count' => $int('ec_live_count'),
            'video_engagement_pct' => $pct('ec_video_engagement_rate'),
            'live_engagement_pct' => $pct('ec_live_engagement_rate'),
            'gmv_idr' => $idr('gmv'),
            'video_gmv_idr' => $idr('video_gmv'),
            'live_gmv_idr' => $idr('live_gmv'),
            'gpm_idr' => $idr('gpm'),
            'units_sold' => $int('units_sold'),
            'brand_collab_count' => $int('brand_collaboration_count'),
            'avg_commission_pct' => $pct('avg_commission_rate'),
            'gmv_range' => (string) data_get($c, 'gmv_range.formatted_range', '') ?: null,
            'usd_idr_rate' => $rate,
            'region' => ($c['selection_region'] ?? '') ?: null,
        ] + $this->mapPerformanceDemografi($c);
    }

    /**
     * Demografi dari endpoint performa: follower_gender/follower_age = daftar
     * {key, value pecahan}. Disimpan format yang sama dgn hasil search
     * (gender FEMALE|MALE + % mayoritas, "25–34, 18–24" = 2 umur terbesar).
     *
     * @return array{gender:?string,gender_pct:?float,age_ranges:?string}
     */
    private function mapPerformanceDemografi(array $c): array
    {
        $gender = collect((array) ($c['follower_gender'] ?? []))
            ->filter(fn ($g) => in_array(strtolower((string) ($g['key'] ?? '')), ['male', 'female'], true))
            ->sortByDesc(fn ($g) => (float) ($g['value'] ?? 0))->first();
        $ages = collect((array) ($c['follower_age'] ?? []))
            ->sortByDesc(fn ($a) => (float) ($a['value'] ?? 0))->take(2)
            ->map(fn ($a) => str_replace('-', '–', (string) ($a['key'] ?? '')))->filter()->values();

        return [
            'gender' => $gender ? strtoupper((string) $gender['key']) : null,
            'gender_pct' => $gender ? round((float) $gender['value'] * 100, 1) : null,
            'age_ranges' => $ages->isNotEmpty() ? $ages->implode(', ') : null,
        ];
    }

    /**
     * Tarik performa 30 hari satu KOL (butuh open_id di profil) lalu simpan:
     * profil = angka terbaru, snapshot hari ini = riwayat tracker (1 baris/hari).
     */
    public function syncKolPerformance(TiktokAffiliateConnection $conn, Kol $kol): KolTiktokSnapshot
    {
        $openId = (string) $kol->tiktokProfile?->open_id;
        if ($openId === '') {
            throw new \RuntimeException("@{$kol->tiktok_username} belum punya open_id TikTok — cek dulu lewat Cek Performa TikTok.");
        }
        $data = $this->client->getMarketplaceCreatorPerformance($this->freshToken($conn), (string) $conn->shop_cipher, $openId);

        return $this->applyPerformanceToKol($kol, $this->mapCreatorPerformance($data));
    }

    /** @param  array<string,mixed>  $m  hasil mapCreatorPerformance */
    public function applyPerformanceToKol(Kol $kol, array $m): KolTiktokSnapshot
    {
        $keep = fn (array $a) => array_filter($a, fn ($v) => $v !== null);

        KolTiktokProfile::updateOrCreate(['kol_id' => $kol->id], $keep($m + ['performance_synced_at' => now()]));
        if ($m['followers'] !== null) {
            $kol->update(['followers' => $m['followers']]);
        }

        return KolTiktokSnapshot::updateOrCreate(
            ['kol_id' => $kol->id, 'captured_on' => now()->toDateString()],
            $keep(collect($m)->only([
                'followers', 'avg_video_views', 'video_count', 'live_count', 'video_engagement_pct',
                'live_engagement_pct', 'gmv_idr', 'video_gmv_idr', 'live_gmv_idr', 'gpm_idr', 'units_sold',
            ])->all()),
        );
    }

    /**
     * Satu item creators[] dari marketplace search → baris siap tampil. MURNI
     * (tanpa I/O) → dites dengan JSON asli. GMV datang dalam USD (string) — biarkan
     * sbg float USD; konversi ke Rupiah dilakukan di view pakai kurs config. Field
     * gmv/video_gmv/live_gmv bisa TIDAK ADA utk kreator berdata tipis → null.
     *
     * @return array<string,mixed>
     */
    public function mapMarketplaceCreator(array $c): array
    {
        $gmv = data_get($c, 'gmv.amount');
        $vgmv = data_get($c, 'video_gmv.amount');
        $lgmv = data_get($c, 'live_gmv.amount');

        return [
            'open_id' => (string) ($c['creator_open_id'] ?? ''),
            'username' => (string) ($c['username'] ?? ''),
            'nickname' => (string) ($c['nickname'] ?? ''),
            'avatar' => (string) data_get($c, 'avatar.url', ''),
            'followers' => (int) ($c['follower_count'] ?? 0),
            'gmv_usd' => ($gmv === null || $gmv === '') ? null : (float) $gmv,
            'gmv_range' => (string) data_get($c, 'gmv_range.formatted_range', ''),
            'video_gmv_usd' => ($vgmv === null || $vgmv === '') ? null : (float) $vgmv,
            'live_gmv_usd' => ($lgmv === null || $lgmv === '') ? null : (float) $lgmv,
            'avg_video_views' => (int) ($c['avg_ec_video_view_count'] ?? 0),
            'avg_live_uv' => (int) ($c['avg_ec_live_uv'] ?? 0),
            'region' => (string) ($c['selection_region'] ?? ''),
            'gender' => (string) data_get($c, 'top_follower_demographics.major_gender.gender', ''),
            // percentage TikTok = basis 10.000 (4694 = 46,94%).
            'gender_pct' => round((float) data_get($c, 'top_follower_demographics.major_gender.percentage', 0) / 100, 1),
            'age_ranges' => array_values((array) data_get($c, 'top_follower_demographics.age_ranges', [])),
        ];
    }

    /**
     * Respons `data` (orders[].skus[]) → baris siap import. MURNI (tanpa I/O) →
     * dites dengan JSON asli. Satu SKU = satu baris (order_id sintetis
     * "{orderId}-{skuId}" agar unik & idempoten saat re-sync).
     *
     * @return array<int,array<string,mixed>>
     */
    public function mapOrders(array $data): array
    {
        $rows = [];
        foreach (($data['orders'] ?? []) as $order) {
            foreach (($order['skus'] ?? []) as $sku) {
                $rows[] = $this->mapSku($order, $sku);
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function mapSku(array $order, array $sku): array
    {
        // GMV = dasar komisi (nilai penjualan yang diatribusikan). Fallback price×qty
        // bila base kosong. Angka datang sbg string → cast.
        $qty = (int) ($sku['quantity'] ?? 1);
        $gmv = (float) data_get($sku, 'estimated_commission_base.amount', 0);
        if ($gmv <= 0) {
            $gmv = (float) data_get($sku, 'price.amount', 0) * max(1, $qty);
        }

        return [
            'order_id' => (string) ($order['id'] ?? '').'-'.(string) ($sku['sku_id'] ?? ''),
            'username' => $sku['creator_username'] ?? null,
            'gmv' => (int) round($gmv),
            'commission' => (int) round((float) data_get($sku, 'estimated_paid_commission.amount', 0)),
            'commission_settled' => (int) round((float) data_get($sku, 'actual_paid_commission.amount', 0)),
            'qty' => $qty,
            'product' => $sku['product_id'] ?? null,
            'content_type' => $sku['content_type'] ?? null,          // VIDEO | LIVE | SHOP | LINKSHARE
            'status' => $sku['settlement_status'] ?? null,
            'order_date' => isset($order['create_time'])
                ? Carbon::createFromTimestamp((int) $order['create_time'])->toDateString()
                : now()->toDateString(),
        ];
    }

    /** Access token valid — refresh bila mau habis. */
    public function freshToken(TiktokAffiliateConnection $conn): string
    {
        if (! $conn->accessExpiringSoon()) {
            return (string) $conn->access_token;
        }
        $t = $this->client->refreshToken((string) $conn->refresh_token);
        $conn->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $conn->refresh_token,
            'access_expires_at' => $this->toTime($t['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->toTime($t['refresh_token_expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    /** Epoch detik (atau detik-dari-sekarang) → Carbon. */
    public function toTime(mixed $v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = (int) $v;

        return $n > 1_000_000_000 ? Carbon::createFromTimestamp($n) : now()->addSeconds($n);
    }
}
