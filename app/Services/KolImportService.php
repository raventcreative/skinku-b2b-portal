<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolScreening;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Carbon;

/**
 * Impor massal KOL + screening dari file template (.xlsx/.csv). Alur dua tahap:
 *   preview()  — validasi + dedup + klasifikasi TIAP baris, tanpa menyentuh DB;
 *   commit()   — tulis baris yang layak lewat KolService (satu logika dengan form).
 *
 * Kurasi (kunci = username + tanggal_listing): duplikat dalam file dilewati,
 * KOL/screening yang sudah ada tak digandakan → re-upload file yang sama aman.
 * Detail lihat docs/KOL-IMPORT.md.
 */
class KolImportService
{
    /** Kolom kanonik template — urutan ini juga urutan header saat unduh template. */
    public const COLUMNS = [
        'username', 'platform', 'followers', 'ratecard',
        'views_1', 'views_2', 'views_3', 'views_4', 'views_5', 'views_6', 'views_7',
        'tanggal_listing', 'agency', 'kategori', 'phone',
    ];

    public function __construct(private KolService $kol) {}

    /** Sheet untuk XlsxWriter: sheet1 = data (header saja), sheet2 = petunjuk. */
    public function templateSheets(): array
    {
        return [
            'Data KOL' => ['headers' => self::COLUMNS, 'rows' => []],
            'Petunjuk' => [
                'headers' => ['Kolom', 'Wajib?', 'Keterangan'],
                'rows' => [
                    ['username', 'WAJIB', 'Username tanpa @'],
                    ['platform', 'opsional', 'tiktok / instagram / youtube / shopee — kosong = tiktok'],
                    ['followers', 'WAJIB', 'Jumlah followers (angka)'],
                    ['ratecard', 'opsional', 'Harga per video. Kosong = belum nego'],
                    ['views_1 s/d views_7', 'WAJIB', 'Views 7 video terakhir. Kosong = 0'],
                    ['tanggal_listing', 'opsional', 'YYYY-MM-DD. Kosong = pakai Tanggal default di form upload'],
                    ['agency', 'opsional', 'Nama agency, kosongkan bila non-agency'],
                    ['kategori', 'opsional', 'Skinfluencer / Makeup / Lifestyle / dll'],
                    ['phone', 'opsional', 'No. HP KOL (mis. 08xxxxxxxxxx) — untuk kontak dealing'],
                    ['—', '—', 'Isi mulai baris ke-2. Jangan hapus baris header.'],
                ],
            ],
        ];
    }

    /**
     * Klasifikasi seluruh baris tanpa menulis (untuk preview). Tiap item:
     * status = baru | lama | skip, plus alasan & verdict terhitung.
     *
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, int>, header_ok: bool}
     */
    public function preview(string $path, ?string $ext, string $defaultDate): array
    {
        $mapped = $this->mapRows(SpreadsheetReader::rows($path, $ext));

        return $this->classify($mapped['rows'], $mapped['header_ok'], $defaultDate);
    }

    /**
     * Tulis baris yang layak. Re-baca & re-klasifikasi file (konsisten dengan
     * preview), lalu upsert yang berstatus baru/lama.
     *
     * @return array{summary: array<string, int>, skipped: array<int, array<string, mixed>>, header_ok: bool}
     */
    public function commit(string $path, ?string $ext, string $defaultDate, int $actorId): array
    {
        $mapped = $this->mapRows(SpreadsheetReader::rows($path, $ext));
        $result = $this->classify($mapped['rows'], $mapped['header_ok'], $defaultDate);

        foreach ($result['items'] as $item) {
            if ($item['status'] === 'skip') {
                continue;
            }
            $this->kol->upsertScreening($item['data'], $actorId);
        }

        return [
            'summary' => $result['summary'],
            'skipped' => array_values(array_filter($result['items'], fn ($i) => $i['status'] === 'skip')),
            'header_ok' => $result['header_ok'],
        ];
    }

    /* ---------------- internal ---------------- */

    /**
     * Petakan baris mentah (baris 0 = header) ke record berkunci kolom kanonik.
     *
     * @param  array<int, array<int, string>>  $raw
     * @return array{header_ok: bool, rows: array<int, array{n:int, raw:array<string,string>}>}
     */
    private function mapRows(array $raw): array
    {
        if (empty($raw)) {
            return ['header_ok' => false, 'rows' => []];
        }

        $colMap = [];
        foreach ($raw[0] as $i => $name) {
            $key = $this->canon((string) $name);
            if ($key !== null && ! isset($colMap[$key])) {
                $colMap[$key] = $i;
            }
        }
        $headerOk = isset($colMap['username'], $colMap['followers']);

        $rows = [];
        foreach (array_slice($raw, 1, null, true) as $idx => $cells) {
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;   // baris kosong total
            }
            $rec = [];
            foreach ($colMap as $key => $i) {
                $rec[$key] = trim((string) ($cells[$i] ?? ''));
            }
            $rows[] = ['n' => $idx + 1, 'raw' => $rec];   // n = nomor baris file (1-based)
        }

        return ['header_ok' => $headerOk, 'rows' => $rows];
    }

    /**
     * @param  array<int, array{n:int, raw:array<string,string>}>  $rows
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, int>, header_ok: bool}
     */
    private function classify(array $rows, bool $headerOk, string $defaultDate): array
    {
        // Preload keadaan DB sekali — hindari query per baris.
        $kols = Kol::get(['id', 'tiktok_username'])->keyBy(fn ($k) => mb_strtolower($k->tiktok_username));
        $screenKeys = KolScreening::get(['kol_id', 'tanggal_listing'])
            ->map(fn ($s) => $s->kol_id.'|'.$s->tanggal_listing->toDateString())
            ->flip();

        $platforms = array_keys(config('kol.platforms'));
        $seenInFile = [];
        $items = [];
        $summary = ['baru' => 0, 'lama' => 0, 'skip' => 0, 'total' => count($rows)];

        foreach ($rows as $row) {
            $r = $row['raw'];
            $item = ['n' => $row['n'], 'username' => trim((string) ($r['username'] ?? '')), 'status' => 'skip', 'reason' => null];

            $username = ltrim(trim((string) ($r['username'] ?? '')), '@');
            if ($username === '') {
                $items[] = $this->skip($item, 'username kosong', $summary);

                continue;
            }
            $item['username'] = $username;

            $followers = $this->toInt((string) ($r['followers'] ?? ''));
            if ($followers === null) {
                $items[] = $this->skip($item, 'followers kosong / bukan angka', $summary);

                continue;
            }

            // views: kosong = 0, non-angka = error.
            $views = [];
            $badView = false;
            for ($i = 1; $i <= 7; $i++) {
                $rawV = trim((string) ($r["views_{$i}"] ?? ''));
                if ($rawV === '') {
                    $views[] = 0;

                    continue;
                }
                $v = $this->toInt($rawV);
                if ($v === null) {
                    $badView = true;
                    break;
                }
                $views[] = $v;
            }
            if ($badView) {
                $items[] = $this->skip($item, 'ada views yang bukan angka', $summary);

                continue;
            }

            // ratecard: kosong = null (belum nego), non-angka = error.
            $rateRaw = trim((string) ($r['ratecard'] ?? ''));
            $ratecard = null;
            if ($rateRaw !== '') {
                $ratecard = $this->toInt($rateRaw);
                if ($ratecard === null) {
                    $items[] = $this->skip($item, 'ratecard bukan angka', $summary);

                    continue;
                }
            }

            // platform: kosong = tiktok; nilai tak dikenal = error.
            $platform = strtolower(trim((string) ($r['platform'] ?? '')));
            if ($platform === '') {
                $platform = 'tiktok';
            } elseif (! in_array($platform, $platforms, true)) {
                $items[] = $this->skip($item, "platform '{$platform}' tak dikenal", $summary);

                continue;
            }

            // tanggal: kosong = default batch; serial Excel / string tanggal didukung.
            [$tanggal, $dateErr] = $this->parseDate((string) ($r['tanggal_listing'] ?? ''), $defaultDate);
            if ($dateErr !== null) {
                $items[] = $this->skip($item, $dateErr, $summary);

                continue;
            }

            // Dedup: duplikat dalam file (username+tanggal).
            $key = mb_strtolower($username).'|'.$tanggal;
            if (isset($seenInFile[$key])) {
                $items[] = $this->skip($item, 'duplikat dalam file (baris '.$seenInFile[$key].')', $summary);

                continue;
            }
            $seenInFile[$key] = $row['n'];

            // Dedup vs DB.
            $existingKol = $kols->get(mb_strtolower($username));
            if ($existingKol && isset($screenKeys[$existingKol->id.'|'.$tanggal])) {
                $items[] = $this->skip($item, 'sudah ada screening tanggal '.$tanggal, $summary);

                continue;
            }

            // Verdict terhitung untuk preview (transient — tak disimpan).
            $t = new KolScreening([
                'ratecard' => $ratecard,
                'views_1' => $views[0], 'views_2' => $views[1], 'views_3' => $views[2], 'views_4' => $views[3],
                'views_5' => $views[4], 'views_6' => $views[5], 'views_7' => $views[6],
            ]);

            $item['status'] = $existingKol ? 'lama' : 'baru';
            $item['median'] = $t->median_views;
            $item['verdict'] = $t->verdict_median;
            $item['reason'] = $existingKol ? 'KOL sudah ada — screening ditambah' : 'KOL baru';
            $item['data'] = [
                'username' => $username,
                'platform' => $platform,
                'tiktok_link' => null,
                'followers' => $followers,
                'kategori' => trim((string) ($r['kategori'] ?? '')) ?: null,
                'provinsi' => null,
                'agency' => trim((string) ($r['agency'] ?? '')) ?: null,
                'phone' => trim((string) ($r['phone'] ?? '')) ?: null,
                'tanggal_listing' => $tanggal,
                'ratecard' => $ratecard,
                'views' => $views,
            ];
            $summary[$item['status']]++;
            $items[] = $item;
        }

        return ['items' => $items, 'summary' => $summary, 'header_ok' => $headerOk];
    }

    /** Tandai baris dilewati + alasan, naikkan penghitung. */
    private function skip(array $item, string $reason, array &$summary): array
    {
        $item['status'] = 'skip';
        $item['reason'] = $reason;
        $summary['skip']++;

        return $item;
    }

    /** Header → kolom kanonik (toleran spasi/strip/huruf besar + sedikit alias). */
    private function canon(string $name): ?string
    {
        $n = strtolower(trim($name));
        $n = preg_replace('/[\s\-]+/', '_', $n);
        $n = preg_replace('/[^a-z0-9_]/', '', (string) $n);

        $alias = [
            'user' => 'username', 'user_name' => 'username', 'username_tiktok' => 'username',
            'follower' => 'followers', 'rate' => 'ratecard', 'rate_card' => 'ratecard',
            'tanggal' => 'tanggal_listing', 'date' => 'tanggal_listing', 'kategori_kol' => 'kategori',
            'no_hp' => 'phone', 'nohp' => 'phone', 'hp' => 'phone', 'whatsapp' => 'phone', 'wa' => 'phone', 'telp' => 'phone',
        ];
        $n = $alias[$n] ?? $n;

        return in_array($n, self::COLUMNS, true) ? $n : null;
    }

    /**
     * String angka → int, toleran format Indonesia: buang pemisah ribuan (titik/
     * koma diikuti TEPAT 3 digit), sisa koma jadi desimal. "100.700"→100700,
     * "6956.0"→6956, "1.413.121"→1413121. null bila bukan angka.
     */
    private function toInt(string $s): ?int
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $clean = preg_replace('/[.,](?=\d{3}(\D|$))/', '', $s);   // buang pemisah ribuan
        $clean = str_replace(',', '.', (string) $clean);          // koma desimal → titik
        if (! is_numeric($clean)) {
            return null;
        }

        return (int) round((float) $clean);
    }

    /** @return array{0: ?string, 1: ?string} [tanggal Y-m-d | null, alasan error | null] */
    private function parseDate(string $s, string $default): array
    {
        $s = trim($s);
        if ($s === '') {
            return [$default, null];
        }
        // Serial Excel (angka murni pada rentang tanggal wajar).
        if (preg_match('/^\d+(\.\d+)?$/', $s)) {
            $serial = (float) $s;
            if ($serial > 20000 && $serial < 90000) {
                return [Carbon::create(1899, 12, 30)->addDays((int) $serial)->toDateString(), null];
            }
        }
        try {
            return [Carbon::parse($s)->toDateString(), null];
        } catch (\Throwable) {
            return [null, "tanggal '{$s}' tak dikenali"];
        }
    }
}
