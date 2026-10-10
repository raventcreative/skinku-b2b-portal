<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Employee;
use App\Models\File;
use App\Models\JobOpening;
use App\Models\PsychotestSession;
use App\Services\AuditService;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menu HR → Rekrutmen (Fase 2, izin hr.recruit + internal): lowongan, kandidat & tahap seleksi, CV di disk privat,
 * link psikotes, dan "Jadikan karyawan" (butuh hr.manage juga). Kontak kandidat tak dicatat nilainya di audit.
 */
class HrRekrutmenController extends Controller
{
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
        return view('hr.rekrutmen.kandidat-form', [
            'candidate' => new Candidate(['stage' => 'lamar', 'job_opening_id' => (int) $request->query('lowongan') ?: null]),
            'openings' => JobOpening::where('status', 'buka')->latest('id')->get(),
        ]);
    }

    public function storeKandidat(Request $request): RedirectResponse
    {
        $kandidat = Candidate::create($this->dataKandidat($request) + ['stage' => 'lamar', 'created_by' => $request->user()->id]);
        AuditService::log(action: 'create_candidate', targetType: 'candidate', targetId: $kandidat->id,
            after: ['nama' => $kandidat->name, 'lowongan' => $kandidat->opening?->title]);

        return redirect()->route('hr.rekrutmen.kandidat.show', $kandidat)->with('status', "Kandidat {$kandidat->name} ditambahkan.");
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
        ]);
    }

    private function milik(Candidate $kandidat, File $file): void
    {
        abort_unless($file->fileable_type === $kandidat->getMorphClass() && (int) $file->fileable_id === $kandidat->id, 404);
    }
}
