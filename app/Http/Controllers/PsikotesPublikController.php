<?php

namespace App\Http\Controllers;

use App\Models\PsychotestSession;
use App\Services\PsikotesService;
use App\Support\Psikotes\BankSoal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Halaman psikotes PUBLIK untuk kandidat (tanpa login): /tes/{token}. Token 48 karakter acak, berlaku 7 hari, sekali
 * pakai. Urutan tes tetap (kepribadian → DISC → logika); logika berbatas waktu (dihitung server sejak halaman dibuka).
 * Hanya nama depan kandidat yang ditampilkan.
 */
class PsikotesPublikController extends Controller
{
    /** Kelonggaran kirim tes logika setelah waktu habis (detik) — jaringan lambat; lewat itu ditandai lewat_waktu. */
    private const TOLERANSI_DETIK = 60;

    public function __construct(private PsikotesService $svc) {}

    public function mulai(string $token): View
    {
        $sesi = $this->sesi($token);
        if ($sesi->selesai() || $sesi->kedaluwarsa()) {
            return $this->tutup($sesi);
        }

        return view('psikotes.mulai', ['sesi' => $sesi, 'namaDepan' => $this->namaDepan($sesi), 'berikut' => $sesi->tesBerikutnya()]);
    }

    public function tes(string $token, string $tes): View|RedirectResponse
    {
        $sesi = $this->sesi($token);
        if ($sesi->selesai() || $sesi->kedaluwarsa()) {
            return $this->tutup($sesi);
        }
        // Urutan tetap: selalu ke tes berikutnya yang belum dikerjakan.
        $berikut = $sesi->tesBerikutnya();
        if ($tes !== $berikut) {
            return redirect()->route('psikotes.publik.tes', [$token, $berikut]);
        }

        $progress = (array) $sesi->progress;
        if (! isset($progress[$tes]['mulai'])) {
            $progress[$tes]['mulai'] = now()->toDateTimeString();
            $sesi->update(['progress' => $progress, 'started_at' => $sesi->started_at ?? now()]);
        }
        $sisaDetik = null;
        if ($tes === 'logika') {
            $batas = Carbon::parse($progress['logika']['mulai'])->addMinutes(BankSoal::MENIT_LOGIKA);
            $sisaDetik = max(0, (int) now()->diffInSeconds($batas, false));
        }

        return view('psikotes.tes', [
            'sesi' => $sesi,
            'tes' => $tes,
            'judul' => PsikotesService::TES[$tes],
            'nomorTes' => array_search($tes, (array) $sesi->tests, true) + 1,
            'jumlahTes' => count((array) $sesi->tests),
            'sisaDetik' => $sisaDetik,
            'disc' => $tes === 'disc' ? array_map(fn ($g) => $this->svc->urutanDisc($g), array_keys(BankSoal::DISC)) : [],
        ]);
    }

    public function simpan(Request $request, string $token, string $tes): View|RedirectResponse
    {
        $sesi = $this->sesi($token);
        if ($sesi->selesai() || $sesi->kedaluwarsa()) {
            return $this->tutup($sesi);
        }
        if ($tes !== $sesi->tesBerikutnya()) {
            return redirect()->route('psikotes.publik.tes', [$token, $sesi->tesBerikutnya()]);
        }

        $progress = (array) $sesi->progress;
        $answers = (array) $sesi->answers;
        $results = (array) $sesi->results;
        [$answers[$tes], $results[$tes]] = match ($tes) {
            'kepribadian' => $this->kepribadian($request),
            'disc' => $this->disc($request),
            'logika' => $this->logika($request),
        };
        $progress[$tes]['selesai'] = now()->toDateTimeString();
        if ($tes === 'logika' && isset($progress['logika']['mulai'])) {
            $batas = Carbon::parse($progress['logika']['mulai'])->addMinutes(BankSoal::MENIT_LOGIKA)->addSeconds(self::TOLERANSI_DETIK);
            $progress['logika']['lewat_waktu'] = now()->gt($batas);
        }
        $sesi->forceFill(['progress' => $progress, 'answers' => $answers, 'results' => $results]);
        if ($sesi->tesBerikutnya() === null) {
            $sesi->finished_at = now();
        }
        $sesi->save();

        return $sesi->selesai()
            ? view('psikotes.selesai', ['namaDepan' => $this->namaDepan($sesi)])
            : redirect()->route('psikotes.publik.tes', [$token, $sesi->tesBerikutnya()]);
    }

    /** @return array{0:array,1:array} [jawaban, skor] */
    private function kepribadian(Request $request): array
    {
        $jawaban = $this->wajibLengkap((array) $request->input('jawaban', []), count(BankSoal::KEPRIBADIAN), ['1', '2', '3', '4', '5'],
            'Masih ada pernyataan yang belum dijawab.');

        return [$jawaban, $this->svc->skorKepribadian($jawaban)];
    }

    /** @return array{0:array,1:array} */
    private function disc(Request $request): array
    {
        $huruf = ['D', 'I', 'S', 'C'];
        $paling = $this->wajibLengkap((array) $request->input('paling', []), count(BankSoal::DISC), $huruf, 'Masih ada kelompok kata yang belum dipilih.');
        $kurang = $this->wajibLengkap((array) $request->input('kurang', []), count(BankSoal::DISC), $huruf, 'Masih ada kelompok kata yang belum dipilih.');
        foreach ($paling as $g => $h) {
            if ($kurang[$g] === $h) {
                throw ValidationException::withMessages(['jawaban' => 'Di kelompok '.($g + 1).', pilihan "paling" dan "paling tidak" harus berbeda.']);
            }
        }

        return [['paling' => $paling, 'kurang' => $kurang], $this->svc->skorDisc($paling, $kurang)];
    }

    /** Logika boleh tak lengkap (waktu habis = kosong dihitung salah). @return array{0:array,1:array} */
    private function logika(Request $request): array
    {
        $jawaban = collect((array) $request->input('jawaban', []))
            ->filter(fn ($v, $i) => is_numeric($i) && isset(BankSoal::LOGIKA[(int) $i]) && in_array((string) $v, ['0', '1', '2', '3'], true))
            ->mapWithKeys(fn ($v, $i) => [(int) $i => (int) $v])->all();

        return [$jawaban, $this->svc->skorLogika($jawaban)];
    }

    /** Semua nomor 0..n-1 wajib terisi nilai yang sah. @return array<int,string> */
    private function wajibLengkap(array $input, int $n, array $sah, string $pesan): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $v = Arr::get($input, $i);
            if (! is_scalar($v) || ! in_array((string) $v, $sah, true)) {
                throw ValidationException::withMessages(['jawaban' => $pesan]);
            }
            $out[$i] = (string) $v;
        }

        return $out;
    }

    private function sesi(string $token): PsychotestSession
    {
        abort_unless(strlen($token) === 48, 404);

        return PsychotestSession::with('candidate')->where('token', $token)->firstOrFail();
    }

    private function tutup(PsychotestSession $sesi): View
    {
        return view('psikotes.tutup', ['selesai' => $sesi->selesai(), 'namaDepan' => $this->namaDepan($sesi)]);
    }

    private function namaDepan(PsychotestSession $sesi): string
    {
        return strtok((string) $sesi->candidate?->name, ' ') ?: 'Kandidat';
    }
}
