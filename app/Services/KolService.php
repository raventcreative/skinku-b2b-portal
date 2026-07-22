<?php

namespace App\Services;

use App\Models\Kol;
use App\Models\KolScreening;
use Illuminate\Support\Facades\DB;

/**
 * Tulis domain KOL yang dipakai bersama form Screening & Impor massal — satu
 * sumber logika "buat/pakai-ulang KOL lalu tambah screening", supaya form &
 * impor tak pernah beda perilaku. TANPA audit di sini: pemanggil yang mencatat
 * (form per-baris, impor cukup ringkasan) agar log tak dibanjiri 100+ entri.
 */
class KolService
{
    /**
     * Buat KOL bila username belum ada (bandingkan abaikan '@' & huruf besar/kecil),
     * atau pakai ulang yang ada — followers selalu diperbarui ke angka terbaru,
     * field opsional yang kosong TIDAK menimpa data lama. Selalu menambah 1
     * screening.
     *
     * @param  array{username:string, platform?:?string, tiktok_link?:?string, followers:int|string, kategori?:?string, provinsi?:?string, agency?:?string, tanggal_listing:string, ratecard?:int|null, views:array<int,int>}  $data
     * @return array{kol: Kol, screening: KolScreening, created: bool}
     */
    public function upsertScreening(array $data, int $actorId): array
    {
        $username = ltrim(trim((string) $data['username']), '@');

        return DB::transaction(function () use ($data, $username, $actorId) {
            $kol = Kol::whereRaw('LOWER(tiktok_username) = ?', [mb_strtolower($username)])->first();
            $created = false;

            if (! $kol) {
                $kol = Kol::create([
                    'tiktok_username' => $username,
                    'platform' => $data['platform'] ?? 'tiktok',
                    'tiktok_link' => $data['tiktok_link'] ?? null,
                    'followers' => (int) $data['followers'],
                    'kategori' => $data['kategori'] ?? null,
                    'provinsi' => $data['provinsi'] ?? null,
                    'agency' => $data['agency'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ]);
                $created = true;
            } else {
                // Isi hanya yang dikirim — jangan menimpa data lama dengan kosong.
                $kol->update(array_filter([
                    'followers' => (int) $data['followers'],
                    'platform' => $data['platform'] ?? null,
                    'tiktok_link' => $data['tiktok_link'] ?? null,
                    'kategori' => $data['kategori'] ?? null,
                    'provinsi' => $data['provinsi'] ?? null,
                    'agency' => $data['agency'] ?? null,
                    'phone' => $data['phone'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
            }

            $views = array_values($data['views']);
            $screening = $kol->screenings()->create([
                'tanggal_listing' => $data['tanggal_listing'],
                'ratecard' => $data['ratecard'] ?? null,
                'views_1' => (int) ($views[0] ?? 0),
                'views_2' => (int) ($views[1] ?? 0),
                'views_3' => (int) ($views[2] ?? 0),
                'views_4' => (int) ($views[3] ?? 0),
                'views_5' => (int) ($views[4] ?? 0),
                'views_6' => (int) ($views[5] ?? 0),
                'views_7' => (int) ($views[6] ?? 0),
                'created_by' => $actorId,
            ]);

            return ['kol' => $kol, 'screening' => $screening, 'created' => $created];
        });
    }
}
