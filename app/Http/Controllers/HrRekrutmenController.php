<?php

namespace App\Http\Controllers;

use App\Jobs\BacaCvJob;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\File;
use App\Models\JobOpening;
use App\Models\PsychotestSession;
use App\Services\AuditService;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menu HR → Rekrutmen (Fase 2, izin hr.recruit + internal): lowongan, kandidat & tahap seleksi, CV di disk privat,
 * link psikotes, dan "Jadikan karyawan" (butuh hr.manage juga). Kontak kandidat tak dicatat nilainya di audit.
 * "Baca CV dengan AI": CV disimpan sementara (privat) → dibaca AI di antrean (BacaCvJob) → form tambah kandidat terisi
 * lewat polling → CV ikut terlampir saat disimpan.
 */
class HrRekrutmenController extends Controller
{
    /** Folder CV sementara hasil "Baca CV dengan AI" (disk privat; bukan awalan hr_ → tak ikut backup dokumen). */
    private const CV_SEMENTARA = 'cv_sementara';

    /** Kunci sesi: CV yang sudah dibaca AI & menunggu disimpan bersama kandidat baru. */
    private const SESI_CV = 'hr_cv_ai';

    public function index(Request $request): View
    {
        $tahap = array_key_exists((string) $request->query('tahap'), Candidate::STAGES) ? $request->query('tahap') : null;
        $lowongan = (int) $request->query('lowongan') ?: null;
        $cari = trim((string) $request->query('q'));

        $candidates = Candidate::with(['opening', 'psychotests'])
            ->when($tahap, fn ($q) => $q->where('stage', $tahap))
            ->when($lowongan, fn ($q) => $q->where('job_opening_id', $lowongan))
            ->when($cari !== '', fn ($q) => $q->where('name', 'like', "%{$cari}%"))
            ->latest('id')->get();

        return view('hr.rekrutmen.index', [
            'candidates' => $candidates,
            'perTahap' => Candidate::selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage'),
            'openings' => JobOpening::withCount('candidates')->orderByRaw("status = 'tutup'")->latest('id')->get(),
            'filters' => ['tahap' => $tahap, 'lowongan' => $lowongan, 'q' => $cari],
        ]);
    }

    public function storeLowongan(Request $request): RedirectResponse
    {
        $lowongan = JobOpening::create($this->dataLowongan($request) + ['created_by' => $request->user()->id]);
        AuditService::log(action: 'create_job_opening', targetType: 'job_opening', targetId: $lowongan->id, after: ['judul' => $lowongan->title]);

        return back()->with('status', "Lowongan \"{$lowongan->title}\" dibuat.");
    }

    public function updateLowongan(Request $request, JobOpening $lowongan): RedirectResponse
    {
        $sebelum = $lowongan->only(['title', 'department', 'status']);
        $lowongan->update($this->dataLowongan($request));
        AuditService::log(action: 'update_job_opening', targetType: 'job_opening', targetId: $lowongan->id,
            before: $sebelum, after: $lowongan->only(['title', 'department', 'status']));

        return back()->with('status', "Lowongan \"{$lowongan->title}\" disimpan.");
    }

    public function createKandidat(Request $request): View
    {
        $candidate = new Candidate(['stage' => 'lamar', 'job_opening_id' => (int) $request->query('lowongan') ?: null]);
        $cvAi = $request->session()->get(self::SESI_CV);
        $bacaAi = $cvAi ? (Cache::get(BacaCvJob::kunci((string) ($cvAi['token'] ?? ''))) ?? ['status' => 'gagal', 'hasil' => null,
            'pesan' => 'Hasil baca CV sudah kedaluwarsa — silakan baca ulang.']) : null;
        if (($bacaAi['status'] ?? null) === 'selesai') {
            $candidate->fill(array_filter((array) $bacaAi['hasil'], fn ($v) => $v !== null));
        }

        return view('hr.rekrutmen.kandidat-form', [
            'candidate' => $candidate,
            'openings' => JobOpening::where('status', 'buka')->latest('id')->get(),
            'cvAi' => $cvAi,
            'bacaAi' => $bacaAi,
        ]);
    }

    public function storeKandidat(Request $request): RedirectResponse
    {
        $data = $this->dataKandidat($request) + $request->validate(['notes' => ['nullable', 'string', 'max:3000']]);
        $kandidat = Candidate::create($data + ['stage' => 'lamar', 'created_by' => $request->user()->id]);
        AuditService::log(action: 'create_candidate', targetType: 'candidate', targetId: $kandidat->id,
            after: ['nama' => $kandidat->name, 'lowongan' => $kandidat->opening?->title]);

        // CV dari "Baca CV dengan AI" ikut terlampir (atau dibuang bila HR tak mencentang).
        if ($cv = $request->session()->pull(self::SESI_CV)) {
            $request->boolean('lampirkan_cv') ? $this->lampirkanCvSementara($kandidat, $cv) : Storage::disk(Employee::DISK)->delete($cv['path']);
            Cache::forget(BacaCvJob::kunci((string) ($cv['token'] ?? '')));
        }

        return redirect()->route('hr.rekrutmen.kandidat.show', $kandidat)->with('status', "Kandidat {$kandidat->name} ditambahkan.");
    }

    /**
     * "Baca CV dengan AI": CV (PDF/foto) disimpan apa adanya di disk privat (tanpa olah gambar → hemat memori), lalu
     * dibaca AI di antrean (BacaCvJob). Form tambah kandidat langsung terbuka & terisi otomatis saat AI selesai
     * (polling statusBacaCv). Request web tak pernah menunggu AI → tak diputus server walau AI lama.
     */
    public function bacaCv(Request $request): RedirectResponse
    {
        $request->validate(['cv' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120']], [
            'cv.required' => 'Pilih file CV dulu.',
            'cv.mimes' => 'Baca CV dengan AI menerima PDF atau foto (JPG, PNG, WEBP).',
            'cv.max' => 'Ukuran CV maksimal 5 MB.',
        ]);
        $file = $request->file('cv');
        $this->buangCvSementara($request);
        $path = $file->store(self::CV_SEMENTARA, Employee::DISK);
        $mime = Storage::disk(Employee::DISK)->mimeType($path) ?: (string) $file->getMimeType();
        $token = Str::random(32);

        BacaCvJob::antri($token);
        $request->session()->put(self::SESI_CV, ['token' => $token, 'path' => $path, 'nama' => $file->getClientOriginalName(), 'mime' => $mime]);
        AuditService::log(action: 'read_candidate_cv_ai', targetType: 'candidate', after: ['file' => $file->getClientOriginalName()]);
        BacaCvJob::dispatch($token, $path, $mime);

        return redirect()->route('hr.rekrutmen.kandidat.create')
            ->with('status', 'CV diterima — AI sedang membaca, form di bawah terisi otomatis begitu selesai.');
    }

    /** Status baca CV (polling form Tambah kandidat). Hanya untuk token milik sesi ini. */
    public function statusBacaCv(Request $request, string $token): JsonResponse
    {
        abort_unless(($request->session()->get(self::SESI_CV)['token'] ?? null) === $token, 404);

        return response()->json(Cache::get(BacaCvJob::kunci($token))
            ?? ['status' => 'gagal', 'hasil' => null, 'pesan' => 'Hasil baca CV sudah kedaluwarsa — silakan baca ulang.']);
    }

    public function showKandidat(Request $request, Candidate $kandidat): View
    {
        return view('hr.rekrutmen.kandidat', [
            'candidate' => $kandidat->load(['opening', 'employee']),
            'sesi' => $kandidat->psikotesTerakhir(),
            'cv' => $kandidat->filesIn('hr_cv')->get(),
            'openings' => JobOpening::latest('id')->get(),
            'bolehJadikanKaryawan' => $request->user()->canDo('hr.manage'),
        ]);
    }

    public function updateKandidat(Request $request, Candidate $kandidat): RedirectResponse
    {
        $kandidat->update($this->dataKandidat($request) + $request->validate([
            'interview_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]));
        AuditService::log(action: 'update_candidate', targetType: 'candidate', targetId: $kandidat->id, after: ['nama' => $kandidat->name]);

        return back()->with('status', 'Data kandidat disimpan.');
    }

    /** Pindah tahap seleksi (lamar → psikotes → interview → diterima / ditolak). */
    public function tahap(Request $request, Candidate $kandidat): RedirectResponse
    {
        $baru = $request->validate(['stage' => ['required', Rule::in(array_keys(Candidate::STAGES))]])['stage'];
        $lama = $kandidat->stage;
        $kandidat->update(['stage' => $baru]);
        AuditService::log(action: 'candidate_stage', targetType: 'candidate', targetId: $kandidat->id,
            before: ['tahap' => Candidate::STAGES[$lama] ?? $lama], after: ['tahap' => Candidate::STAGES[$baru]]);

        return back()->with('status', "{$kandidat->name} dipindah ke tahap ".Candidate::STAGES[$baru].'.');
    }

    /** Buat link psikotes baru (berlaku 7 hari, sekali pakai). Kandidat di tahap Lamar otomatis pindah ke Psikotes. */
    public function buatPsikotes(Request $request, Candidate $kandidat): RedirectResponse
    {
        $sesi = PsychotestSession::buatUntuk($kandidat, $request->user()->id);
        if ($kandidat->stage === 'lamar') {
            $kandidat->update(['stage' => 'psikotes']);
        }
        AuditService::log(action: 'create_psychotest', targetType: 'candidate', targetId: $kandidat->id,
            after: ['nama' => $kandidat->name, 'berlaku_sampai' => $sesi->expires_at->toDateString()]);

        return back()->with('status', 'Link psikotes dibuat — salin atau kirim lewat WhatsApp ke kandidat.');
    }

    public function uploadCv(Request $request, Candidate $kandidat, ImageService $images): RedirectResponse
    {
        $request->validate(['cv' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx', 'max:5120']],
            ['cv.required' => 'Pilih file CV dulu.', 'cv.mimes' => 'CV harus PDF, Word, atau foto.', 'cv.max' => 'Ukuran CV maksimal 5 MB.']);
        $file = $images->attach($kandidat, $request->file('cv'), 'hr_cv', 1600, 85, Employee::DISK);
        AuditService::log(action: 'upload_candidate_cv', targetType: 'candidate', targetId: $kandidat->id, after: ['file' => $file->original_name]);

        return back()->with('status', 'CV diunggah.');
    }

    public function cv(Candidate $kandidat, File $file): StreamedResponse
    {
        $this->milik($kandidat, $file);
        AuditService::log(action: 'view_candidate_cv', targetType: 'candidate', targetId: $kandidat->id, after: ['file' => $file->original_name]);

        return Storage::disk($file->disk)->response($file->path, $file->original_name);
    }

    public function destroyCv(Candidate $kandidat, File $file): RedirectResponse
    {
        $this->milik($kandidat, $file);
        AuditService::log(action: 'delete_candidate_cv', targetType: 'candidate', targetId: $kandidat->id, before: ['file' => $file->original_name]);
        $file->delete();

        return back()->with('status', 'CV dihapus.');
    }

    /** Kandidat diterima → data karyawan (Fase 1) dibuat dari data kandidat, lalu dilengkapi di form karyawan. */
    public function jadikanKaryawan(Request $request, Candidate $kandidat): RedirectResponse
    {
        if ($kandidat->employee_id) {
            return redirect()->route('hr.employees.show', $kandidat->employee_id)->with('status', 'Kandidat ini sudah menjadi karyawan.');
        }
        $employee = Employee::create([
            'name' => $kandidat->name,
            'position' => $kandidat->opening?->title,
            'department' => $kandidat->opening?->department,
            'phone' => $kandidat->phone,
            'email' => $kandidat->email,
            'employment_type' => 'percobaan',
            'status' => 'aktif',
            'join_date' => now()->toDateString(),
            'onboarding' => [],
            'created_by' => $request->user()->id,
        ]);
        $kandidat->update(['employee_id' => $employee->id, 'stage' => 'diterima']);
        AuditService::log(action: 'create_employee', targetType: 'employee', targetId: $employee->id,
            after: ['name' => $employee->name, 'position' => $employee->position, 'dari_kandidat' => $kandidat->id]);

        return redirect()->route('hr.employees.edit', $employee)
            ->with('status', "{$employee->name} kini karyawan ({$employee->kode}). Lengkapi tanggal mulai, masa percobaan & data identitasnya.");
    }

    private function dataLowongan(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:60'],
            'status' => ['required', Rule::in(array_keys(JobOpening::STATUSES))],
            'description' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function dataKandidat(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'job_opening_id' => ['nullable', 'integer', 'exists:job_openings,id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'source' => ['nullable', 'string', 'max:60'],
            'cv_summary' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function milik(Candidate $kandidat, File $file): void
    {
        abort_unless($file->fileable_type === $kandidat->getMorphClass() && (int) $file->fileable_id === $kandidat->id, 404);
    }

    /** Pindahkan CV sementara (hasil Baca CV AI) jadi CV kandidat (koleksi hr_cv, disk privat, ikut backup dokumen). */
    private function lampirkanCvSementara(Candidate $kandidat, array $cv): void
    {
        $disk = Storage::disk(Employee::DISK);
        $asal = (string) ($cv['path'] ?? '');
        if (! str_starts_with($asal, self::CV_SEMENTARA.'/') || ! $disk->exists($asal)) {
            return;
        }
        $tujuan = 'hr_cv/'.basename($asal);
        $disk->move($asal, $tujuan);
        $file = $kandidat->files()->create([
            'collection' => 'hr_cv', 'disk' => Employee::DISK, 'path' => $tujuan, 'original_name' => (string) ($cv['nama'] ?? basename($asal)),
            'mime_type' => $cv['mime'] ?? null, 'size' => $disk->size($tujuan), 'sort_order' => 0,
        ]);
        AuditService::log(action: 'upload_candidate_cv', targetType: 'candidate', targetId: $kandidat->id,
            after: ['file' => $file->original_name, 'dari' => 'baca_cv_ai']);
    }

    /** Buang CV sementara milik sesi ini + sisa yang terlantar > 1 hari (HR batal menyimpan kandidat). */
    private function buangCvSementara(Request $request): void
    {
        $disk = Storage::disk(Employee::DISK);
        if ($lama = $request->session()->pull(self::SESI_CV)) {
            $disk->delete($lama['path']);
            Cache::forget(BacaCvJob::kunci((string) ($lama['token'] ?? '')));
        }
        foreach ($disk->files(self::CV_SEMENTARA) as $p) {
            if ($disk->lastModified($p) < now()->subDay()->getTimestamp()) {
                $disk->delete($p);
            }
        }
    }
}
