<?php

namespace App\Services;

use App\Models\JobOpening;
use App\Services\Ai\AiException;
use App\Services\ReportBot\ReportAi;
use App\Support\PdfTextExtractor;
use App\Support\PdfUnicodeText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * "Baca CV dengan AI" (HR → Rekrutmen, dijalankan BacaCvJob): CV (PDF / foto) → model AI aktif portal → isian form
 * tambah kandidat: nama, HP, email, lowongan yang cocok (dari lowongan yang sedang buka) + Ringkasan CV berpoin yang
 * MENGIKUTI bagian-bagian CV itu sendiri (format CV beda-beda — tanpa template tetap), "Tentang Saya" ikut diringkas,
 * maks 3 poin per bagian / entri — diringkas APA ADANYA, tanpa penilaian / kesimpulan AI. Teks PDF dibaca portal sendiri (PdfUnicodeText — termasuk font CID Canva/Google
 * Docs/Word lewat ToUnicode) lalu dikirim sebagai teks: cepat, murah, jalan di model AI apa pun. Hanya PDF hasil scan &
 * foto yang dikirim sebagai berkas multimodal. Hasil hanya MENGISI form — HR memeriksa sebelum menyimpan. Isi CV tak
 * dicatat di log/audit.
 */
class BacaCvService
{
    /** Batas teks PDF yang dikirim ke AI (CV normal jauh di bawah ini). */
    private const MAKS_TEKS = 20_000;

    /** Batas panjang jawaban AI untuk ringkasan berpoin (bawaan portal 1.500 token bisa memotong JSON-nya). */
    private const MAKS_TOKEN_JAWABAN = 3000;

    /** Ringkasan: maks bagian CV, entri per bagian, dan poin per bagian / entri (kesepakatan user: maks 3 poin). */
    private const MAKS_BAGIAN = 10;

    private const MAKS_ITEM = 8;

    private const MAKS_POIN = 3;

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
        $teks = $mime === 'application/pdf' ? $this->teksPdf($path) : '';
        $jalur = $teks !== '' ? 'teks' : 'berkas';

        $hasil = $this->rapikan($this->denganJawabanPanjang(fn () => $jalur === 'teks'
            ? $this->ai->readText($instruksi, mb_substr($teks, 0, self::MAKS_TEKS))
            : $this->ai->readFile((string) file_get_contents($path), $mime, $instruksi, 'cv.pdf')), $lowongan);

        if (! array_filter($hasil)) {
            // Tanpa isi CV / balasan AI (data pribadi) — cukup untuk menelusuri jalur mana yang gagal.
            Log::warning('Baca CV: AI tidak mengembalikan data kandidat.', ['jalur' => $jalur, 'mime' => $mime, 'panjang_teks' => mb_strlen($teks)]);
        }

        return $hasil;
    }

    /**
     * Teks PDF terbaik dari dua ekstraktor ('' bila hasil scan / tak terbaca → kirim berkas). Format PDF beda-beda, jadi
     * keduanya dicoba lalu dinilai jumlah "kata wajar"-nya; PdfUnicodeText diutamakan (susunan barisnya lebih rapi)
     * kecuali ekstraktor lama jauh lebih lengkap.
     */
    private function teksPdf(string $path): string
    {
        $rapi = fn (string $t) => trim((string) preg_replace(["/[ \t]+/", "/ *\n */", "/\n{3,}/"], [' ', "\n", "\n\n"], $t));
        [$baru, $lama] = array_map(function (string $t) use ($rapi) {
            $t = $rapi($t);

            return mb_strlen($t) >= 40 && ! PdfTextExtractor::looksUnreadable($t) ? $t : '';
        }, [PdfUnicodeText::extract($path), PdfTextExtractor::extract($path)]);

        return $this->skorTeks($lama) > 1.25 * $this->skorTeks($baru) ? $lama : $baru;
    }

    /** Jumlah kata wajar (2–20 huruf, boleh diakhiri tanda baca) — teks yang hurufnya hilang / sampah skornya rendah. */
    private function skorTeks(string $teks): int
    {
        return (int) preg_match_all('/(?<!\S)\p{L}[\p{L}\'’-]{1,19}[.,;:!?)]?(?!\S)/u', $teks);
    }

    /** Naikkan sementara batas token jawaban AI (config dibaca AiProviderFactory saat dibuat), lalu kembalikan. */
    private function denganJawabanPanjang(callable $panggil): array
    {
        $lama = config('services.ai.max_output_tokens');
        config(['services.ai.max_output_tokens' => max(self::MAKS_TOKEN_JAWABAN, (int) $lama)]);
        try {
            return $panggil();
        } finally {
            config(['services.ai.max_output_tokens' => $lama]);
        }
    }

    private function instruksi(Collection $lowongan): string
    {
        $daftar = json_encode($lowongan->map(fn ($l) => ['id' => $l->id, 'posisi' => $l->title, 'divisi' => $l->department])->values(),
            JSON_UNESCAPED_UNICODE);

        return <<<TXT
        Kamu membantu tim HR SKINKU meringkas CV / surat lamaran kandidat. Format CV berbeda-beda — ikuti susunan CV itu
        sendiri, JANGAN memaksakan template. Ringkas isinya APA ADANYA: jangan menilai, menyimpulkan, memberi kesan/opini,
        atau menambah hal yang tidak tertulis. Balas HANYA satu objek JSON valid (tanpa teks lain, tanpa pagar kode):
        {"nama": string|null, "telepon": string|null, "email": string|null, "posisi_dilamar": string|null,
         "lowongan_id": number|null, "domisili": string|null, "tanggal_lahir": "YYYY-MM-DD"|null, "gaji_diharapkan": string|null,
         "bagian": [{"judul": string, "poin": [string], "item": [{"judul": string, "poin": [string]}]}]}
        Aturan:
        - Yang tidak tertulis di CV isi null (atau [] untuk daftar).
        - bagian = bagian-bagian CV sesuai urutan & judul aslinya (mis. "Tentang Saya", "Pendidikan", "Pengalaman Kerja",
          "Pengalaman Organisasi & Volunteer", "Keterampilan"). Profil diri / "Tentang Saya" ikut diringkas. Lewati data
          kontak (alamat lengkap, telepon, email, media sosial) — sudah diambil di kolom terpisah.
        - Bagian berisi daftar entri (pekerjaan, organisasi, pendidikan, kepanitiaan, pelatihan) memakai "item": judul =
          "Jabatan/Peran — Tempat (periode)" sesuai yang tertulis, poin = tugas / tanggung jawab / pencapaian. Bagian
          lain (profil, keterampilan, bahasa) memakai "poin" langsung.
        - Maks 3 poin per bagian atau per item, tiap poin kalimat pendek (maks 15 kata) Bahasa Indonesia; bila isinya
          banyak (mis. daftar keterampilan), gabungkan jadi maks 3 poin.
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

    /**
     * Ringkasan CV (teks biasa, tampil apa adanya di form & detail kandidat): baris info singkat lalu bagian-bagian CV
     * sesuai judul & urutan aslinya — poin langsung, atau entri ("- Peran — Tempat (periode)") dengan sub-poin; maks 3.
     */
    private function ringkasan(array $h): ?string
    {
        $blok = [];

        $info = collect([
            'Melamar' => $this->teks($h['posisi_dilamar'] ?? null, 120),
            'Domisili' => $this->teks($h['domisili'] ?? null, 80),
            'Lahir' => $this->tanggal($h['tanggal_lahir'] ?? null),
            'Gaji diharapkan' => $this->teks($h['gaji_diharapkan'] ?? null, 60),
        ])->filter()->map(fn ($v, $k) => "{$k}: {$v}");
        if ($info->isNotEmpty()) {
            $blok[] = $info->implode(' · ');
        }

        foreach ($this->daftar($h['bagian'] ?? null, self::MAKS_BAGIAN) as $b) {
            if (! is_array($b) || ! ($judul = $this->teks($b['judul'] ?? null, 80))) {
                continue;
            }
            $baris = $this->poin($b['poin'] ?? null, '- ');
            foreach ($this->daftar($b['item'] ?? null, self::MAKS_ITEM) as $item) {
                if (is_array($item) && ($judulItem = $this->teks($item['judul'] ?? null, 200))) {
                    $baris = $baris->push("- {$judulItem}")->merge($this->poin($item['poin'] ?? null, '   - '));
                }
            }
            if ($baris->isNotEmpty()) {
                $blok[] = mb_strtoupper($judul)."\n".$baris->implode("\n");
            }
        }

        return $blok === [] ? null : mb_substr(implode("\n\n", $blok), 0, 5000);
    }

    /** Maks 3 poin pendek, masing-masing diberi awalan. */
    private function poin(mixed $v, string $awalan): Collection
    {
        return $this->daftar($v, self::MAKS_POIN)->map(fn ($x) => $this->teks($x, 160))->filter()->map(fn ($x) => $awalan.$x)->values();
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
