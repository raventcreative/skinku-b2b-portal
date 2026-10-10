@extends('layouts.app')
@section('title', $employee->name.' · Karyawan')
@section('heading', 'Karyawan')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $tgl = fn ($d) => $d?->translatedFormat('d M Y') ?? '—';
    $isi = fn ($v) => filled($v) ? $v : '—';
@endphp
<div class="space-y-4 max-w-5xl">
    <a href="{{ route('hr.employees.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Semua karyawan</a>

    <div class="bg-white rounded-2xl border border-stone-200 p-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-lg font-bold text-stone-800">{{ $employee->name }}</h2>
            <p class="text-sm text-stone-500"><span class="font-mono">{{ $employee->kode }}</span>{{ $employee->position ? ' · '.$employee->position : '' }}{{ $employee->department ? ' · '.$employee->department : '' }}</p>
            <div class="mt-2 flex flex-wrap gap-1.5">
                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $employee->aktif() ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">{{ \App\Models\Employee::STATUSES[$employee->status] ?? $employee->status }}</span>
                <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-stone-100 text-stone-700">{{ \App\Models\Employee::TYPES[$employee->employment_type] ?? $employee->employment_type }}</span>
                @if($employee->percobaanPerluDitinjau())<span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-amber-100 text-amber-800">Percobaan berakhir {{ $tgl($employee->probation_end) }}</span>@endif
                @if($employee->kontrakSegeraBerakhir())<span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold bg-amber-100 text-amber-800">Kontrak berakhir {{ $tgl($employee->contract_end) }}</span>@endif
            </div>
        </div>
        @if($bolehKelola)
            <a href="{{ route('hr.employees.edit', $employee) }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Ubah data</a>
        @endif
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-3">Info kerja</h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <dt class="text-stone-500">Mulai kerja</dt><dd class="text-stone-800">{{ $tgl($employee->join_date) }}</dd>
                <dt class="text-stone-500">Akhir percobaan</dt><dd class="text-stone-800">{{ $tgl($employee->probation_end) }}</dd>
                <dt class="text-stone-500">Akhir kontrak</dt><dd class="text-stone-800">{{ $tgl($employee->contract_end) }}</dd>
                @unless($employee->aktif())
                    <dt class="text-stone-500">Keluar</dt><dd class="text-stone-800">{{ $tgl($employee->resign_date) }}{{ $employee->resign_reason ? ' — '.$employee->resign_reason : '' }}</dd>
                @endunless
                <dt class="text-stone-500">Akun portal</dt><dd class="text-stone-800">{{ $employee->user ? $employee->user->displayName().' ('.$employee->user->role.')' : '—' }}</dd>
                <dt class="text-stone-500">Dicatat oleh</dt><dd class="text-stone-800">{{ $employee->creator?->displayName() ?? '—' }}</dd>
            </dl>
        </div>

        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-3">Data pribadi &amp; identitas</h3>
            @if($bolehKelola)
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-stone-500">Telepon</dt><dd class="text-stone-800">{{ $isi($employee->phone) }}</dd>
                    <dt class="text-stone-500">Email</dt><dd class="text-stone-800 break-words">{{ $isi($employee->email) }}</dd>
                    <dt class="text-stone-500">Tanggal lahir</dt><dd class="text-stone-800">{{ $tgl($employee->birth_date) }}</dd>
                    <dt class="text-stone-500">Jenis kelamin</dt><dd class="text-stone-800">{{ \App\Models\Employee::GENDERS[$employee->gender] ?? '—' }}</dd>
                    <dt class="text-stone-500">Status PTKP</dt><dd class="text-stone-800">{{ $isi($employee->ptkp_status) }}</dd>
                    <dt class="text-stone-500">NIK</dt><dd class="text-stone-800 font-mono">{{ $isi($employee->nik) }}</dd>
                    <dt class="text-stone-500">NPWP</dt><dd class="text-stone-800 font-mono">{{ $isi($employee->npwp) }}</dd>
                    <dt class="text-stone-500">Alamat</dt><dd class="text-stone-800 break-words">{{ $isi($employee->address) }}</dd>
                    <dt class="text-stone-500">Rekening</dt><dd class="text-stone-800">{{ $employee->bank_account ? trim(($employee->bank_name ?? '').' '.$employee->bank_account.' a.n. '.($employee->bank_account_name ?? '-')) : '—' }}</dd>
                    <dt class="text-stone-500">BPJS Kesehatan</dt><dd class="text-stone-800 font-mono">{{ $isi($employee->bpjs_kesehatan) }}</dd>
                    <dt class="text-stone-500">BPJS Ketenagakerjaan</dt><dd class="text-stone-800 font-mono">{{ $isi($employee->bpjs_ketenagakerjaan) }}</dd>
                    <dt class="text-stone-500">Kontak darurat</dt><dd class="text-stone-800 break-words">{{ $isi($employee->emergency_contact) }}</dd>
                </dl>
                <p class="mt-3 text-[11px] text-stone-400">🔒 NIK, NPWP, alamat, rekening, BPJS &amp; kontak darurat disimpan terenkripsi.</p>
            @else
                <p class="text-sm text-stone-500">Data pribadi, identitas &amp; dokumen hanya untuk izin <b>Kelola karyawan</b>.</p>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between gap-2">
            <h3 class="text-sm font-bold text-stone-800">Checklist onboarding</h3>
            <span class="text-xs text-stone-500">{{ $employee->onboardingSelesai() }}/{{ count(\App\Models\Employee::ONBOARDING) }} selesai</span>
        </div>
        <div class="divide-y divide-stone-100">
            @foreach(\App\Models\Employee::ONBOARDING as $key => $label)
                @php $item = $employee->itemOnboarding($key); $berkas = $dokumen->get('hr_'.$key, collect()); @endphp
                <div class="px-5 py-3 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold {{ $item ? 'text-emerald-700' : 'text-stone-700' }}">{{ $item ? '✓' : '○' }} {{ $label }}</p>
                        @if($item)
                            <p class="text-[11px] text-stone-400">Selesai {{ \Illuminate\Support\Carbon::parse($item['selesai'])->translatedFormat('d M Y H:i') }}{{ isset($pencentang[$item['oleh']]) ? ' oleh '.$pencentang[$item['oleh']] : '' }}</p>
                        @endif
                        @foreach($berkas as $f)
                            <div class="mt-1 flex items-center gap-2 text-xs">
                                <a href="{{ route('hr.employees.documents.show', [$employee, $f]) }}" target="_blank" rel="noopener" class="text-red-700 hover:underline break-words">📄 {{ $f->original_name }}</a>
                                <span class="text-stone-400">{{ $f->created_at?->translatedFormat('d M Y') }}</span>
                                <form method="POST" action="{{ route('hr.employees.documents.destroy', [$employee, $f]) }}" onsubmit="return confirm('Hapus dokumen ini?')">@csrf @method('DELETE')
                                    <button class="text-[11px] text-rose-600 hover:underline">Hapus</button>
                                </form>
                            </div>
                        @endforeach
                    </div>
                    @if($bolehKelola)
                        <div class="flex flex-wrap items-center gap-2">
                            <form method="POST" action="{{ route('hr.employees.documents.store', [$employee, $key]) }}" enctype="multipart/form-data" class="flex items-center gap-2">@csrf
                                <input type="file" name="dokumen" accept="image/*,.pdf" required aria-label="Dokumen {{ $label }}" class="text-xs text-stone-600 max-w-[200px]">
                                <button class="px-3 py-1.5 text-xs font-semibold bg-stone-100 text-stone-700 rounded-lg hover:bg-stone-200">Unggah</button>
                            </form>
                            <form method="POST" action="{{ route('hr.employees.onboarding', [$employee, $key]) }}">@csrf
                                <button class="px-3 py-1.5 text-xs font-semibold rounded-lg {{ $item ? 'border border-stone-300 text-stone-600 hover:bg-stone-50' : 'bg-emerald-700 text-white hover:bg-emerald-800' }}">{{ $item ? 'Batalkan' : 'Tandai selesai' }}</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
        @if($bolehKelola)
            <p class="px-5 py-3 border-t border-stone-100 text-[11px] text-stone-400">Dokumen disimpan privat (tidak bisa dibuka lewat link publik), maksimal 5 MB, foto atau PDF. Setiap unggah, buka &amp; hapus tercatat di Audit Log.</p>
        @endif
    </div>

    @if($employee->notes)
        <div class="bg-white rounded-2xl border border-stone-200 p-5">
            <h3 class="text-sm font-bold text-stone-800 mb-1">Catatan</h3>
            <p class="text-sm text-stone-700 whitespace-pre-line">{{ $employee->notes }}</p>
        </div>
    @endif
</div>
@endsection
