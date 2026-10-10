@extends('layouts.app')
@section('title', $employee->exists ? 'Ubah karyawan' : 'Tambah karyawan')
@section('heading', $employee->exists ? 'Ubah data karyawan' : 'Tambah karyawan')

@section('content')
{{-- Pesan sukses/gagal & error validasi ditampilkan layout (tak diulang di sini). --}}
@php
    $nilai = fn ($k) => old($k, $employee->{$k} instanceof \Illuminate\Support\Carbon ? $employee->{$k}->toDateString() : $employee->{$k});
    $input = 'mt-1 w-full px-3 py-2 text-sm border border-stone-300 rounded-lg';
    $label = 'block text-xs font-semibold text-stone-600';
@endphp
<form method="POST" action="{{ $employee->exists ? route('hr.employees.update', $employee) : route('hr.employees.store') }}" class="space-y-4 max-w-4xl">
    @csrf
    @if($employee->exists) @method('PUT') @endif
    <a href="{{ $employee->exists ? route('hr.employees.show', $employee) : route('hr.employees.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Kembali</a>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Data kerja</h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="{{ $label }} sm:col-span-2">Nama lengkap *<input name="name" value="{{ $nilai('name') }}" required maxlength="120" class="{{ $input }}"></label>
            <label class="{{ $label }}">Jabatan<input name="position" value="{{ $nilai('position') }}" maxlength="100" placeholder="Admin Gudang" class="{{ $input }}"></label>
            <label class="{{ $label }}">Divisi<input name="department" value="{{ $nilai('department') }}" maxlength="60" list="daftarDivisi" placeholder="Gudang, Konten, KOL, Admin…" class="{{ $input }}"></label>
            <datalist id="daftarDivisi">@foreach($divisions as $d)<option value="{{ $d }}">@endforeach</datalist>
            <label class="{{ $label }}">Status kerja *
                <select name="employment_type" class="{{ $input }}">
                    @foreach(\App\Models\Employee::TYPES as $v => $l)<option value="{{ $v }}" @selected($nilai('employment_type') === $v)>{{ $l }}</option>@endforeach
                </select>
            </label>
            <label class="{{ $label }}">Status *
                <select name="status" class="{{ $input }}">
                    @foreach(\App\Models\Employee::STATUSES as $v => $l)<option value="{{ $v }}" @selected($nilai('status') === $v)>{{ $l }}</option>@endforeach
                </select>
            </label>
            <label class="{{ $label }}">Mulai kerja<input type="date" name="join_date" value="{{ $nilai('join_date') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Akhir masa percobaan<input type="date" name="probation_end" value="{{ $nilai('probation_end') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Akhir kontrak<input type="date" name="contract_end" value="{{ $nilai('contract_end') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Akun portal (opsional)
                <select name="user_id" class="{{ $input }}">
                    <option value="">— tidak ditautkan —</option>
                    @foreach($akun as $a)<option value="{{ $a->id }}" @selected((string) $nilai('user_id') === (string) $a->id)>{{ $a->displayName() }} ({{ $a->role }})</option>@endforeach
                </select>
            </label>
            <label class="{{ $label }}">Tanggal keluar<input type="date" name="resign_date" value="{{ $nilai('resign_date') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Alasan keluar<input name="resign_reason" value="{{ $nilai('resign_reason') }}" maxlength="255" class="{{ $input }}"></label>
        </div>
        <p class="mt-2 text-[11px] text-stone-400">Pengingat muncul 30 hari sebelum masa percobaan / kontrak berakhir. Status "Keluar" wajib tanggal keluar.</p>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800 mb-3">Data pribadi</h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="{{ $label }}">Telepon / WA<input name="phone" value="{{ $nilai('phone') }}" maxlength="30" class="{{ $input }}"></label>
            <label class="{{ $label }}">Email<input type="email" name="email" value="{{ $nilai('email') }}" maxlength="120" class="{{ $input }}"></label>
            <label class="{{ $label }}">Tanggal lahir<input type="date" name="birth_date" value="{{ $nilai('birth_date') }}" class="{{ $input }}"></label>
            <label class="{{ $label }}">Jenis kelamin
                <select name="gender" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach(\App\Models\Employee::GENDERS as $v => $l)<option value="{{ $v }}" @selected($nilai('gender') === $v)>{{ $l }}</option>@endforeach
                </select>
            </label>
            <label class="{{ $label }}">Status PTKP (untuk PPh 21)
                <select name="ptkp_status" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach(\App\Models\Employee::PTKP as $v)<option value="{{ $v }}" @selected($nilai('ptkp_status') === $v)>{{ $v }}</option>@endforeach
                </select>
            </label>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <h3 class="text-sm font-bold text-stone-800">Identitas, rekening &amp; BPJS</h3>
        <p class="text-[11px] text-stone-400 mb-3">🔒 Disimpan terenkripsi. Perubahan tercatat di Audit Log tanpa isi datanya.</p>
        <div class="grid sm:grid-cols-2 gap-3">
            <label class="{{ $label }}">NIK (16 digit)<input name="nik" value="{{ $nilai('nik') }}" inputmode="numeric" maxlength="16" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }}">NPWP<input name="npwp" value="{{ $nilai('npwp') }}" maxlength="25" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }} sm:col-span-2">Alamat<textarea name="address" rows="2" maxlength="500" class="{{ $input }}">{{ $nilai('address') }}</textarea></label>
            <label class="{{ $label }}">Bank<input name="bank_name" value="{{ $nilai('bank_name') }}" maxlength="60" placeholder="BCA" class="{{ $input }}"></label>
            <label class="{{ $label }}">No. rekening<input name="bank_account" value="{{ $nilai('bank_account') }}" maxlength="40" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }} sm:col-span-2">Atas nama rekening<input name="bank_account_name" value="{{ $nilai('bank_account_name') }}" maxlength="120" class="{{ $input }}"></label>
            <label class="{{ $label }}">No. BPJS Kesehatan<input name="bpjs_kesehatan" value="{{ $nilai('bpjs_kesehatan') }}" maxlength="30" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }}">No. BPJS Ketenagakerjaan<input name="bpjs_ketenagakerjaan" value="{{ $nilai('bpjs_ketenagakerjaan') }}" maxlength="30" class="{{ $input }} font-mono"></label>
            <label class="{{ $label }} sm:col-span-2">Kontak darurat (nama, hubungan, nomor)<input name="emergency_contact" value="{{ $nilai('emergency_contact') }}" maxlength="255" class="{{ $input }}"></label>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <label class="{{ $label }}">Catatan<textarea name="notes" rows="3" maxlength="2000" class="{{ $input }}">{{ $nilai('notes') }}</textarea></label>
    </div>

    <div class="flex items-center gap-2">
        <button class="px-5 py-2.5 text-sm font-semibold bg-red-700 text-white rounded-lg hover:bg-red-800">{{ $employee->exists ? 'Simpan perubahan' : 'Tambah karyawan' }}</button>
        <a href="{{ $employee->exists ? route('hr.employees.show', $employee) : route('hr.employees.index') }}" class="px-4 py-2.5 text-sm text-stone-600 hover:text-stone-800">Batal</a>
    </div>
</form>
@endsection
