<?php

namespace App\Services;

use App\Models\JobOpening;
use App\Services\Ai\AiException;
use App\Services\ReportBot\ReportAi;
use App\Support\PdfTextExtractor;
use Illuminate\Support\Collection;

/**
 * "Baca CV dengan AI" (HR → Rekrutmen): CV (PDF / foto) → model AI aktif portal → isian form tambah kandidat: nama,
 * HP, email, lowongan yang cocok (dipilih dari lowongan yang sedang buka) dan catatan ringkas (posisi dilamar, domisili,
 * pendidikan, pengalaman, keahlian). PDF berteks dikirim sebagai teks (murah, semua model bisa); PDF hasil scan & foto
 * sebagai berkas multimodal. Hasil hanya MENGISI form — HR memeriksa sebelum menyimpan. Isi CV tak dicatat di log/audit.
 */
class BacaCvService
{
    /** Batas teks PDF yang dikirim ke AI (CV normal jauh di bawah ini). */
    private const MAKS_TEKS = 20_000;

    public function __construct(private ReportAi $ai) {}

    /**
     * @param  string  $path  berkas CV lokal (sudah disimpan sementara)
     * @return array{name:?string,phone:?string,email:?string,job_opening_id:?int,notes:?string}
     *
     * @throws AiException bila AI tak bisa dihubungi / key kosong
     */
    public function baca(string $path, string $mime): array
    {
        $lowongan = JobOpening::where('status', 'buka')->orderBy('title')->get(['id', 'title', 'department']);
        $instruksi = $this->instruksi($lowongan);

        $teks = $mime === 'application/pdf' ? PdfTextExtractor::extract($path) : '';
        $hasil = $teks !== '' && ! PdfTextExtractor::looksUnreadable($teks)
            ? $this->ai->readText($instruksi, mb_substr($teks, 0, self::MAKS_TEKS))
            : $this->ai->readFile((string) file_get_contents($path), $mime, $instruksi);

        return $this->rapikan($hasil, $lowongan);
    }

    private function instruksi(Collection $lowongan): string
    {
        $daftar = json_encode($lowongan->map(fn ($l) => ['id' => $l->id, 'posisi' => $l->title, 'divisi' => $l->department])->values(),
            JSON_UNESCAPED_UNICODE);

        return <<<TXT
        Kamu membantu tim HR SKINKU membaca CV / surat lamaran kandidat. Balas HANYA dengan satu objek JSON valid (tanpa teks
        lain, tanpa pagar kode) berkunci persis:
        {"nama": string|null, "telepon": string|null, "email": string|null, "posisi_dilamar": string|null,
         "lowongan_id": number|null, "domisili": string|null, "tanggal_lahir": "YYYY-MM-DD"|null, "pendidikan": string|null,
         "pengalaman": [string], "keahlian": [string], "ringkasan": string|null}
        Aturan:
        - Jangan mengarang: yang tidak tertulis di CV isi null (atau [] untuk daftar).
        - telepon persis seperti tertulis. pendidikan = jenjang terakhir, jurusan, institusi, tahun lulus.
        - pengalaman: maks 5 terbaru, format "Jabatan — Perusahaan (tahun mulai–selesai)". keahlian: maks 10.
        - ringkasan: 1–2 kalimat kesan profil kandidat, Bahasa Indonesia.
        - lowongan_id: id dari daftar lowongan berikut yang paling cocok dengan posisi dilamar / pengalaman kandidat; null
          bila tidak ada yang cocok.
        Lowongan yang sedang buka: {$daftar}
        TXT;
    }

    /**
     * Balasan AI → isian form yang aman: teks dipangkas, email harus valid, lowongan harus yang sedang buka.
     *
     * @return array{name:?string,phone:?string,email:?string,job_opening_id:?int,notes:?string}
     */
    private function rapikan(array $h, Collection $lowongan): array
    {
        $teks = fn ($v, int $maks = 255) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $maks) : null;
        $daftar = fn ($v, int $maks) => collect(is_array($v) ? $v : [])->map(fn ($x) => $teks($x, 200))->filter()->take($maks)->values();

        $catatan = collect([
            'Posisi dilamar' => $teks($h['posisi_dilamar'] ?? null),
            'Domisili' => $teks($h['domisili'] ?? null),
            'Tanggal lahir' => $teks($h['tanggal_lahir'] ?? null, 20),
            'Pendidikan' => $teks($h['pendidikan'] ?? null, 300),
        ])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->values();
        if (($pengalaman = $daftar($h['pengalaman'] ?? null, 5))->isNotEmpty()) {
            $catatan->push("Pengalaman:\n".$pengalaman->map(fn ($p) => "- {$p}")->implode("\n"));
        }
        if (($keahlian = $daftar($h['keahlian'] ?? null, 10))->isNotEmpty()) {
            $catatan->push('Keahlian: '.$keahlian->implode(', '));
        }
        if ($kesan = $teks($h['ringkasan'] ?? null, 400)) {
            $catatan->push("Kesan: {$kesan}");
        }

        $email = $teks($h['email'] ?? null, 120);
        $telepon = preg_replace('/[^0-9+\-() ]/', '', (string) $teks($h['telepon'] ?? null, 30));
        $id = is_numeric($h['lowongan_id'] ?? null) ? (int) $h['lowongan_id'] : null;

        return [
            'name' => $teks($h['nama'] ?? null, 120),
            'phone' => trim($telepon) !== '' ? trim($telepon) : null,
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'job_opening_id' => $id !== null && $lowongan->contains('id', $id) ? $id : null,
            'notes' => $catatan->isEmpty() ? null : mb_substr("Ringkasan CV oleh AI (cek ulang):\n".$catatan->implode("\n"), 0, 3000),
        ];
    }
}
