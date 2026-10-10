<?php

namespace App\Services;

use App\Models\JobOpening;
use App\Services\Ai\AiException;
use App\Services\ReportBot\ReportAi;
use App\Support\PdfTextExtractor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "Baca CV dengan AI" (HR → Rekrutmen, dijalankan BacaCvJob): CV (PDF / foto) → model AI aktif portal → isian form
 * tambah kandidat: nama, HP, email, lowongan yang cocok (dari lowongan yang sedang buka) + Ringkasan CV berpoin
 * (pengalaman kerja dengan poin tugas, pendidikan, keahlian, sertifikat, bahasa, organisasi) — diringkas APA ADANYA,
 * tanpa penilaian / kesimpulan AI. PDF berteks dikirim sebagai teks; PDF hasil scan & foto sebagai berkas multimodal.
 * Hasil hanya MENGISI form — HR memeriksa sebelum menyimpan. Isi CV tak dicatat di log/audit.
 */
class BacaCvService
{
    /** Batas teks PDF yang dikirim ke AI (CV normal jauh di bawah ini). */
    private const MAKS_TEKS = 20_000;

    public function __construct(private ReportAi $ai) {}

    /**
     * @param  string  $path  berkas CV lokal (sudah disimpan sementara)
     * @return array{name:?string,phone:?string,email:?string,job_opening_id:?int,cv_summary:?string}
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
        Kamu membantu tim HR SKINKU meringkas CV / surat lamaran kandidat. Ringkas isi CV APA ADANYA — jangan menilai,
        menyimpulkan, memberi kesan/opini, atau menambah hal yang tidak tertulis. Balas HANYA satu objek JSON valid (tanpa
        teks lain, tanpa pagar kode) berkunci persis:
        {"nama": string|null, "telepon": string|null, "email": string|null, "posisi_dilamar": string|null,
         "lowongan_id": number|null, "domisili": string|null, "tanggal_lahir": "YYYY-MM-DD"|null, "gaji_diharapkan": string|null,
         "pengalaman": [{"jabatan": string, "perusahaan": string|null, "periode": string|null, "poin": [string]}],
         "pendidikan": [{"jenjang": string, "institusi": string|null, "tahun": string|null}],
         "keahlian": [string], "sertifikat": [string], "bahasa": [string], "organisasi": [string]}
        Aturan:
        - Yang tidak tertulis di CV isi null (atau [] untuk daftar).
        - pengalaman: terbaru dulu, maks 6. poin = tugas / tanggung jawab / pencapaian yang tertulis di CV, maks 4 per
          pengalaman, tiap poin kalimat pendek (maks 15 kata) Bahasa Indonesia. periode mis. "Jan 2021 – Des 2023".
        - pendidikan: jenjang + jurusan (mis. "S1 Manajemen", "SMK Akuntansi"), maks 3, terbaru dulu.
        - keahlian maks 12, sertifikat/pelatihan maks 6, bahasa maks 4, organisasi maks 4 — semuanya singkat.
        - telepon persis seperti tertulis.
        - lowongan_id: id dari daftar lowongan berikut yang paling cocok dengan posisi dilamar / pengalaman kandidat; null
          bila tidak ada yang cocok.
        Lowongan yang sedang buka: {$daftar}
        TXT;
    }

    /**
     * Balasan AI → isian form yang aman: teks dipangkas, email harus valid, lowongan harus yang sedang buka, ringkasan
     * CV disusun jadi teks berpoin.
     *
     * @return array{name:?string,phone:?string,email:?string,job_opening_id:?int,cv_summary:?string}
     */
    private function rapikan(array $h, Collection $lowongan): array
    {
        $email = $this->teks($h['email'] ?? null, 120);
        $telepon = trim((string) preg_replace('/[^0-9+\-() ]/', '', (string) $this->teks($h['telepon'] ?? null, 30)));
        $id = is_numeric($h['lowongan_id'] ?? null) ? (int) $h['lowongan_id'] : null;

        return [
            'name' => $this->teks($h['nama'] ?? null, 120),
            'phone' => $telepon !== '' ? $telepon : null,
            'email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'job_opening_id' => $id !== null && $lowongan->contains('id', $id) ? $id : null,
            'cv_summary' => $this->ringkasan($h),
        ];
    }

    /** Ringkasan CV berpoin (teks biasa, tampil apa adanya di form & detail kandidat). */
    private function ringkasan(array $h): ?string
    {
        $bagian = [];

        $info = collect([
            'Melamar' => $this->teks($h['posisi_dilamar'] ?? null, 120),
            'Domisili' => $this->teks($h['domisili'] ?? null, 80),
            'Lahir' => $this->tanggal($h['tanggal_lahir'] ?? null),
            'Gaji diharapkan' => $this->teks($h['gaji_diharapkan'] ?? null, 60),
        ])->filter()->map(fn ($v, $k) => "{$k}: {$v}");
        if ($info->isNotEmpty()) {
            $bagian[] = $info->implode(' · ');
        }

        $kerja = $this->daftar($h['pengalaman'] ?? null, 6)->map(function ($p) {
            if (! is_array($p) || ! ($jabatan = $this->teks($p['jabatan'] ?? null, 120))) {
                return null;
            }
            $judul = '• '.$jabatan
                .(($perusahaan = $this->teks($p['perusahaan'] ?? null, 120)) ? " — {$perusahaan}" : '')
                .(($periode = $this->teks($p['periode'] ?? null, 60)) ? " ({$periode})" : '');
            $poin = $this->daftar($p['poin'] ?? null, 4)->map(fn ($x) => $this->teks($x, 160))->filter()->map(fn ($x) => "   - {$x}");

            return collect([$judul])->merge($poin)->implode("\n");
        })->filter();
        if ($kerja->isNotEmpty()) {
            $bagian[] = "PENGALAMAN KERJA\n".$kerja->implode("\n");
        }

        $sekolah = $this->daftar($h['pendidikan'] ?? null, 3)->map(function ($p) {
            if (! is_array($p) || ! ($jenjang = $this->teks($p['jenjang'] ?? null, 120))) {
                return null;
            }

            return '• '.$jenjang
                .(($institusi = $this->teks($p['institusi'] ?? null, 120)) ? " — {$institusi}" : '')
                .(($tahun = $this->teks($p['tahun'] ?? null, 30)) ? " ({$tahun})" : '');
        })->filter();
        if ($sekolah->isNotEmpty()) {
            $bagian[] = "PENDIDIKAN\n".$sekolah->implode("\n");
        }

        foreach (['keahlian' => ['KEAHLIAN', 12, false], 'bahasa' => ['BAHASA', 4, false],
            'sertifikat' => ['SERTIFIKAT / PELATIHAN', 6, true], 'organisasi' => ['ORGANISASI', 4, true]] as $kunci => [$judul, $maks, $berpoin]) {
            $isi = $this->daftar($h[$kunci] ?? null, $maks)->map(fn ($x) => $this->teks($x, 160))->filter();
            if ($isi->isNotEmpty()) {
                $bagian[] = $judul."\n".($berpoin ? $isi->map(fn ($x) => "• {$x}")->implode("\n") : $isi->implode(', '));
            }
        }

        return $bagian === [] ? null : mb_substr(implode("\n\n", $bagian), 0, 5000);
    }

    private function teks(mixed $v, int $maks = 255): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $maks) : null;
    }

    private function daftar(mixed $v, int $maks): Collection
    {
        return collect(is_array($v) ? array_values($v) : [])->take($maks);
    }

    /** "1998-04-12" → "12-04-1998"; format lain dibiarkan apa adanya. */
    private function tanggal(mixed $v): ?string
    {
        $t = $this->teks($v, 20);
        if ($t === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) || ! Carbon::hasFormat($t, 'Y-m-d')) {
            return $t;
        }

        return Carbon::createFromFormat('!Y-m-d', $t)->format('d-m-Y');
    }
}
