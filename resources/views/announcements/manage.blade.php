@extends('layouts.app')
@section('title', 'Pengumuman')
@section('heading', 'Pengumuman Dashboard')

@section('content')
<div class="max-w-3xl">
    {{-- Pesan sukses "disimpan" sudah dirender global oleh layout — tak diulang di sini. --}}
    @if($errors->any())
        <p class="mb-4 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
    @endif

    {{-- Pilih role — tiap role diatur terpisah, isinya boleh beda. --}}
    <form method="GET" class="flex items-end gap-2 mb-2">
        <label class="text-[11px] font-semibold text-stone-500">Atur pengumuman untuk role
            <select name="role" onchange="this.form.submit()" class="mt-1 block px-3 py-2 border border-stone-300 rounded-lg text-sm">
                @foreach($roles as $r)<option value="{{ $r }}" @selected($role === $r)>{{ $r }}</option>@endforeach
            </select>
        </label>
        <span class="text-[10px] text-stone-400 pb-2">tiap role diatur & disimpan sendiri-sendiri</span>
    </form>

    {{-- Penanda role yang sudah punya pengumuman AKTIF — biar jelas ini per role. --}}
    <p class="text-[11px] text-stone-500 mb-4">
        Sudah aktif:
        @forelse($active as $a)
            <span class="inline-block px-2 py-0.5 rounded bg-emerald-50 border border-emerald-200 text-emerald-700 mr-1 mb-1">{{ $a->role }}{{ $a->note_enabled ? ' · catatan' : '' }}{{ $a->banner_enabled ? ' · banner' : '' }}</span>
        @empty
            <span class="text-stone-400">belum ada role yang aktif — centang "Aktifkan" di bawah lalu Simpan.</span>
        @endforelse
    </p>

    @if($role)
    <form method="POST" action="{{ route('announcements.save') }}" enctype="multipart/form-data"
        class="bg-white rounded-2xl border border-stone-200 p-5 space-y-5">
        @csrf
        <input type="hidden" name="role" value="{{ $role }}">

        {{-- Box catatan (teks, nempel di dashboard) --}}
        <div>
            <label class="flex items-center gap-2 text-sm font-semibold text-stone-700">
                <input type="checkbox" name="note_enabled" value="1" @checked(old('note_enabled', $announcement->note_enabled))>
                Aktifkan box catatan di dashboard
            </label>
            <p class="text-[11px] text-stone-400 mb-2">Teks yang selalu tampil di dashboard role <b>{{ $role }}</b>.</p>
            <input name="note_title" maxlength="150" placeholder="Judul (opsional)" value="{{ old('note_title', $announcement->note_title) }}"
                class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm mb-2">
            <textarea name="note_body" rows="3" maxlength="5000" placeholder="Isi catatan…"
                class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('note_body', $announcement->note_body) }}</textarea>
            <p class="text-[10px] text-stone-400 mt-1">URL yang ditulis di isi otomatis jadi tautan yang bisa diklik.</p>

            <div class="grid sm:grid-cols-2 gap-2 mt-2">
                <input name="note_link" type="url" maxlength="255" placeholder="Link tombol (opsional — mis. https://…)"
                    value="{{ old('note_link', $announcement->note_link) }}"
                    class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                <input name="note_link_label" maxlength="60" placeholder="Teks tombol (mis. Klik di sini)"
                    value="{{ old('note_link_label', $announcement->note_link_label) }}"
                    class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </div>
            <p class="text-[10px] text-stone-400 mt-1">Isi "Link tombol" kalau mau tombol klik terpisah — isi catatan jadi bersih tanpa URL panjang.</p>
        </div>

        {{-- Popup banner (gambar, muncul sekali tiap login) --}}
        <div class="border-t border-stone-100 pt-4">
            <label class="flex items-center gap-2 text-sm font-semibold text-stone-700">
                <input type="checkbox" name="banner_enabled" value="1" @checked(old('banner_enabled', $announcement->banner_enabled))>
                Aktifkan popup banner (muncul sekali tiap login)
            </label>
            <p class="text-[11px] text-stone-400 mb-2">Gambar yang nongol saat masuk. Otomatis diperkecil biar hemat.</p>

            @if($announcement->bannerUrl())
                <div class="mb-2">
                    <img src="{{ $announcement->bannerUrl() }}" alt="banner" class="max-h-40 rounded-lg border border-stone-200">
                    <label class="flex items-center gap-2 text-[11px] text-rose-600 mt-1">
                        <input type="checkbox" name="remove_banner" value="1"> hapus gambar ini
                    </label>
                </div>
            @endif

            <input type="file" name="banner" accept="image/*" class="block text-xs mb-2">
            <input name="banner_link" type="url" maxlength="500" placeholder="Link saat banner diklik (opsional — mis. https://…)"
                value="{{ old('banner_link', $announcement->banner_link) }}"
                class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
        </div>

        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Simpan Pengumuman</button>
    </form>
    @else
        <p class="text-sm text-stone-400">Belum ada role yang bisa diatur.</p>
    @endif
</div>
@endsection
