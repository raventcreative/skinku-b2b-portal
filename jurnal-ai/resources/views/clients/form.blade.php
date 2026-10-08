@extends('layouts.app')
@section('title', $client->exists ? 'Ubah klien' : 'Klien baru')

@section('content')
    <h1 class="mb-4 text-xl font-bold text-ink-900">{{ $client->exists ? "Ubah: {$client->name}" : 'Klien baru' }}</h1>

    <form method="POST" action="{{ $client->exists ? route('clients.update', $client) : route('clients.store') }}" class="card max-w-3xl p-6 space-y-5">
        @csrf
        @if ($client->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="name">Nama <span class="text-rose-600">*</span></label>
                <input type="text" id="name" name="name" value="{{ old('name', $client->name) }}" required class="field">
            </div>
            <div>
                <label class="label" for="type">Jenis <span class="text-rose-600">*</span></label>
                <select id="type" name="type" class="field">
                    @foreach (\App\Models\Client::TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', $client->type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-ink-400">Keduanya dapat buku & laporan terpisah. Jenis hanya untuk pengelompokan.</p>
            </div>
            <div>
                <label class="label" for="legal_name">Nama badan hukum</label>
                <input type="text" id="legal_name" name="legal_name" value="{{ old('legal_name', $client->legal_name) }}" class="field" placeholder="PT / CV …">
            </div>
            <div>
                <label class="label" for="npwp">NPWP</label>
                <input type="text" id="npwp" name="npwp" value="{{ old('npwp', $client->npwp) }}" class="field font-mono">
            </div>
            <div>
                <label class="label" for="contact_name">Nama kontak</label>
                <input type="text" id="contact_name" name="contact_name" value="{{ old('contact_name', $client->contact_name) }}" class="field">
            </div>
            <div>
                <label class="label" for="phone">Telepon / WA</label>
                <input type="text" id="phone" name="phone" value="{{ old('phone', $client->phone) }}" class="field">
            </div>
            <div>
                <label class="label" for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $client->email) }}" class="field">
            </div>
            <div>
                <label class="label" for="fiscal_year_start">Awal tahun buku (MM-DD)</label>
                <input type="text" id="fiscal_year_start" name="fiscal_year_start"
                       value="{{ old('fiscal_year_start', $client->fiscal_year_start ?: '01-01') }}" class="field font-mono" placeholder="01-01">
            </div>
        </div>

        <div>
            <label class="label" for="address">Alamat</label>
            <textarea id="address" name="address" rows="2" class="field">{{ old('address', $client->address) }}</textarea>
        </div>
        <div>
            <label class="label" for="notes">Catatan internal</label>
            <textarea id="notes" name="notes" rows="3" class="field"
                      placeholder="Kebiasaan klien ini: fee marketplace masuk akun mana, siapa yang approve, dll.">{{ old('notes', $client->notes) }}</textarea>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $client->is_active ?? true)) class="rounded border-ink-300">
            Klien aktif
        </label>

        <div class="flex items-center gap-2 border-t border-ink-200 pt-4">
            <button class="btn-primary">{{ $client->exists ? 'Simpan' : 'Buat klien + COA standar' }}</button>
            <a href="{{ $client->exists ? route('clients.show', $client) : route('clients.index') }}" class="btn-ghost">Batal</a>

            @if ($client->exists)
                <span class="grow"></span>
                <button form="delete-client" class="btn-danger btn-sm"
                        onclick="return confirm('Hapus klien ini? Kalau sudah ada jurnal posted, klien hanya dinonaktifkan.')">Hapus klien</button>
            @endif
        </div>
    </form>

    @if ($client->exists)
        <form id="delete-client" method="POST" action="{{ route('clients.destroy', $client) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endif
@endsection
