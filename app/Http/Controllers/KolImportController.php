<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\KolImportService;
use App\Support\XlsxWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor massal KOL. Dua tahap: unggah → preview (belum tersimpan) → konfirmasi.
 * File unggahan disimpan sementara di disk lokal antar-tahap (token uuid),
 * dihapus setelah commit. Semua route di balik permission kol.screening.manage.
 */
class KolImportController extends Controller
{
    private const DIR = 'kol-imports';

    public function __construct(private KolImportService $importer) {}

    /** Halaman impor: tombol unduh template + form unggah. */
    public function form()
    {
        return view('kols.import', ['today' => now()->format('Y-m-d')]);
    }

    /** Unduh template kolom rapi (.xlsx). */
    public function template(): BinaryFileResponse
    {
        return XlsxWriter::download('template-impor-kol.xlsx', $this->importer->templateSheets());
    }

    /** Unggah + tampilkan preview (tanpa menulis apa pun). */
    public function preview(Request $request)
    {
        $data = $request->validate([
            // extensions (bukan mimes): xlsx = zip, mimes salah tebak jadi 'zip'.
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:10240'],
            'default_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension()) ?: 'xlsx';
        $token = (string) Str::uuid();
        $file->storeAs(self::DIR, "{$token}.{$ext}", 'local');
        $rel = self::DIR."/{$token}.{$ext}";

        try {
            $result = $this->importer->preview(Storage::disk('local')->path($rel), $ext, $data['default_date']);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($rel);

            return back()->withErrors(['file' => 'File tak bisa dibaca — pastikan .xlsx/.csv yang valid dari template.']);
        }

        // Template salah (tak ada kolom username/followers) → jangan tampilkan preview.
        if (! $result['header_ok']) {
            Storage::disk('local')->delete($rel);

            return back()->withErrors(['file' => 'Kolom "username" & "followers" tak ditemukan — pakai template yang disediakan (tombol Unduh Template).']);
        }

        return view('kols.import', [
            'today' => now()->format('Y-m-d'),
            'preview' => $result,
            'token' => $token,
            'ext' => $ext,
            'defaultDate' => $data['default_date'],
        ]);
    }

    /** Konfirmasi: tulis baris yang layak, hapus file sementara, laporkan. */
    public function commit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'ext' => ['required', 'in:xlsx,xls,csv,txt'],
            'default_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        // Token WAJIB uuid — cegah path traversal ke file lain.
        abort_unless((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data['token']), 422);

        $rel = self::DIR."/{$data['token']}.{$data['ext']}";
        abort_unless(Storage::disk('local')->exists($rel), 404, 'File impor tak ditemukan atau sudah kadaluarsa — ulangi dari unggah.');

        $path = Storage::disk('local')->path($rel);
        $result = $this->importer->commit($path, $data['ext'], $data['default_date'], $request->user()->id);
        Storage::disk('local')->delete($rel);

        $s = $result['summary'];
        AuditService::log(action: 'import_kol', targetType: 'kol', after: $s);

        $msg = "Impor selesai — {$s['baru']} KOL baru, {$s['lama']} KOL lama (+screening), {$s['skip']} dilewati dari {$s['total']} baris.";

        return redirect()->route('kols.index')->with('status', $msg);
    }
}
