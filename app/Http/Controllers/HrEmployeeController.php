<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\File;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menu HR → Karyawan (Fase 1, spec docs/superpowers/specs/2026-10-10-hr-design.md). hr.view = data kerja;
 * hr.manage = kelola + data identitas & dokumen. Route di balik middleware internal (mitra diblok keras).
 */
class HrEmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), ['aktif', 'keluar', 'semua'], true) ? $request->query('status') : 'aktif';
        $divisi = trim((string) $request->query('divisi'));
        $cari = trim((string) $request->query('q'));

        $employees = Employee::query()
            ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
            ->when($divisi !== '', fn ($q) => $q->where('department', $divisi))
            ->when($cari !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$cari}%")
                ->orWhere('position', 'like', "%{$cari}%")->orWhere('kode', 'like', "%{$cari}%")))
            ->orderBy('name')->get();
        $aktif = Employee::where('status', 'aktif')->get();

        return view('hr.employees.index', [
            'employees' => $employees,
            'ringkas' => [
                'aktif' => $aktif->count(),
                'percobaan' => $aktif->filter->percobaanPerluDitinjau()->count(),
                'kontrak' => $aktif->filter->kontrakSegeraBerakhir()->count(),
                'onboarding' => $aktif->reject->onboardingLengkap()->count(),
            ],
            'divisions' => Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
            'filters' => ['status' => $status, 'divisi' => $divisi, 'q' => $cari],
            'bolehKelola' => $request->user()->canDo('hr.manage'),
        ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        $bolehKelola = $request->user()->canDo('hr.manage');

        return view('hr.employees.show', [
            'employee' => $employee->load(['user', 'creator']),
            'bolehKelola' => $bolehKelola,
            // Dokumen & nama pencentang onboarding hanya utk pengelola.
            'dokumen' => $bolehKelola ? $employee->files()->orderBy('id')->get()->groupBy('collection') : collect(),
            'pencentang' => User::whereIn('id', collect($employee->onboarding)->pluck('oleh')->filter())->pluck('fullname', 'id'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Employee(['employment_type' => 'percobaan', 'status' => 'aktif', 'join_date' => now()->toDateString()]));
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = Employee::create($this->validated($request, null) + ['created_by' => $request->user()->id, 'onboarding' => []]);
        AuditService::log(action: 'create_employee', targetType: 'employee', targetId: $employee->id,
            after: $this->untukAudit($employee->getAttributes()) + ['data_identitas_diisi' => $this->sensitifTerisi($employee)]);

        return redirect()->route('hr.employees.show', $employee)->with('status', "Karyawan {$employee->name} ({$employee->kode}) ditambahkan.");
    }

    public function edit(Employee $employee): View
    {
        return $this->form($employee);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        $employee->fill($this->validated($request, $employee));
        $berubah = array_keys($employee->getDirty());
        if ($berubah === []) {
            return redirect()->route('hr.employees.show', $employee)->with('status', 'Tidak ada perubahan.');
        }
        $sebelum = array_intersect_key($employee->getRawOriginal(), array_flip($berubah));
        $employee->save();

        // Nilai kolom identitas TIDAK dicatat — hanya namanya (Audit Log menampilkan sebelum → sesudah).
        $sensitif = array_values(array_intersect($berubah, Employee::SENSITIF));
        AuditService::log(action: 'update_employee', targetType: 'employee', targetId: $employee->id,
            before: $this->untukAudit($sebelum),
            after: $this->untukAudit(array_intersect_key($employee->getAttributes(), array_flip($berubah))) + ($sensitif ? ['data_identitas_diubah' => $sensitif] : []));

        return redirect()->route('hr.employees.show', $employee)->with('status', 'Data karyawan disimpan.');
    }

    /** Centang / batalkan satu item checklist onboarding. */
    public function onboarding(Request $request, Employee $employee, string $item): RedirectResponse
    {
        abort_unless(array_key_exists($item, Employee::ONBOARDING), 404);
        $data = (array) $employee->onboarding;
        $selesai = ! isset($data[$item]);
        if ($selesai) {
            $data[$item] = ['selesai' => now()->toDateTimeString(), 'oleh' => $request->user()->id];
        } else {
            unset($data[$item]);
        }
        $employee->update(['onboarding' => $data]);
        AuditService::log(action: 'employee_onboarding', targetType: 'employee', targetId: $employee->id,
            after: ['item' => Employee::ONBOARDING[$item], 'selesai' => $selesai]);

        return back()->with('status', Employee::ONBOARDING[$item].($selesai ? ' ditandai selesai.' : ' dibatalkan.'));
    }

    /** Unggah dokumen satu item onboarding ke disk PRIVAT (bukan URL publik). */
    public function uploadDocument(Request $request, Employee $employee, string $item, ImageService $images): RedirectResponse
    {
        abort_unless(array_key_exists($item, Employee::ONBOARDING), 404);
        $request->validate(['dokumen' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120']],
            ['dokumen.required' => 'Pilih file dokumen dulu.', 'dokumen.mimes' => 'Dokumen harus foto (JPG/PNG/WEBP) atau PDF.', 'dokumen.max' => 'Ukuran dokumen maksimal 5 MB.']);
        $file = $images->attach($employee, $request->file('dokumen'), 'hr_'.$item, 1600, 85, Employee::DISK);
        AuditService::log(action: 'upload_employee_document', targetType: 'employee', targetId: $employee->id,
            after: ['item' => Employee::ONBOARDING[$item], 'file' => $file->original_name]);

        return back()->with('status', 'Dokumen '.Employee::ONBOARDING[$item].' diunggah.');
    }

    public function document(Employee $employee, File $file): StreamedResponse
    {
        $this->milik($employee, $file);
        AuditService::log(action: 'view_employee_document', targetType: 'employee', targetId: $employee->id,
            after: ['item' => $this->labelKoleksi($file->collection), 'file' => $file->original_name]);

        return Storage::disk($file->disk)->response($file->path, $file->original_name);
    }

    public function destroyDocument(Employee $employee, File $file): RedirectResponse
    {
        $this->milik($employee, $file);
        AuditService::log(action: 'delete_employee_document', targetType: 'employee', targetId: $employee->id,
            before: ['item' => $this->labelKoleksi($file->collection), 'file' => $file->original_name]);
        $file->delete(); // event File::deleting ikut menghapus berkas fisiknya

        return back()->with('status', 'Dokumen dihapus.');
    }

    private function form(Employee $employee): View
    {
        return view('hr.employees.form', [
            'employee' => $employee,
            // Akun portal yang bisa ditautkan: staf (bukan mitra) yang belum tertaut ke karyawan lain.
            'akun' => User::whereNotIn('role', User::PARTNER_ROLES)
                ->whereNotIn('id', Employee::whereNotNull('user_id')->where('id', '!=', $employee->id ?? 0)->pluck('user_id'))
                ->orderBy('fullname')->get(['id', 'fullname', 'username', 'role']),
            'divisions' => Employee::whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
        ]);
    }

    private function validated(Request $request, ?Employee $employee): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:60'],
            'employment_type' => ['required', Rule::in(array_keys(Employee::TYPES))],
            'status' => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'join_date' => ['nullable', 'date'],
            'probation_end' => ['nullable', 'date', 'after_or_equal:join_date'],
            'contract_end' => ['nullable', 'date', 'after_or_equal:join_date'],
            'resign_date' => ['nullable', 'date', 'required_if:status,keluar'],
            'resign_reason' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($employee?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(array_keys(Employee::GENDERS))],
            'ptkp_status' => ['nullable', Rule::in(Employee::PTKP)],
            'nik' => ['nullable', 'regex:/^\d{16}$/'],
            'npwp' => ['nullable', 'string', 'max:25'],
            'address' => ['nullable', 'string', 'max:500'],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
            'bpjs_kesehatan' => ['nullable', 'string', 'max:30'],
            'bpjs_ketenagakerjaan' => ['nullable', 'string', 'max:30'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'nik.regex' => 'NIK harus 16 digit angka.',
            'resign_date.required_if' => 'Isi tanggal keluar untuk karyawan berstatus keluar.',
            'user_id.unique' => 'Akun portal itu sudah tertaut ke karyawan lain.',
        ]);
        if (! empty($data['user_id']) && User::whereKey($data['user_id'])->whereIn('role', User::PARTNER_ROLES)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Akun mitra tidak bisa ditautkan sebagai karyawan.']);
        }

        return $data;
    }

    /** Atribut utk audit: buang kolom identitas (nilainya tak boleh tercatat), tanggal jadi Y-m-d. */
    private function untukAudit(array $attrs): array
    {
        $out = array_diff_key($attrs, array_flip([...Employee::SENSITIF, 'onboarding', 'created_at', 'updated_at', 'deleted_at', 'id']));

        return array_map(fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $v) ? substr($v, 0, 10) : $v, $out);
    }

    /** @return array<int,string> */
    private function sensitifTerisi(Employee $employee): array
    {
        return array_values(array_filter(Employee::SENSITIF, fn ($k) => filled($employee->{$k})));
    }

    private function milik(Employee $employee, File $file): void
    {
        abort_unless($file->fileable_type === $employee->getMorphClass() && (int) $file->fileable_id === $employee->id, 404);
    }

    private function labelKoleksi(string $collection): string
    {
        return Employee::ONBOARDING[substr($collection, 3)] ?? $collection;
    }
}
