@extends('layouts.app')
@section('title', 'Pengumuman')
@section('heading', 'Pengumuman Dashboard')

@section('content')
<div class="max-w-4xl">
    @if($errors->any())
        <p class="mb-4 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
    @endif

    {{-- Filter role (opsional) + tambah. Semua pengumuman tampil di satu layar. --}}
    <form method="GET" class="flex flex-wrap items-end gap-2 mb-3">
        <label class="text-[11px] font-semibold text-stone-500">Filter role
            <select name="role" onchange="this.form.submit()" class="mt-1 block px-3 py-2 border border-stone-300 rounded-lg text-sm">
                <option value="">— semua role —</option>
                @foreach($roles as $r)<option value="{{ $r }}" @selected($filter === $r)>{{ $r }}</option>@endforeach
            </select>
        </label>
        <a href="{{ route('announcements.manage', array_filter(['role' => $filter])) }}#form"
            class="px-4 py-2 text-sm bg-stone-700 text-white rounded-lg hover:bg-stone-800">+ Tambah pengumuman</a>
    </form>

    <div class="bg-white rounded-2xl border border-stone-200 overflow-hidden mb-6">
        <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
                <tr>
                    <th class="text-left px-4 py-2">Role</th>
                    <th class="text-left">Pengumuman</th>
                    <th class="text-left">Tipe</th>
                    <th class="text-left">Status</th>
                    <th class="text-right px-2">Urut</th>
                    <th class="text-right px-4">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $it)
                    <tr class="border-t border-stone-100 {{ $editing->id === $it->id ? 'bg-amber-50' : '' }}">
                        <td class="px-4 py-2 font-semibold text-stone-700">{{ $it->role }}</td>
                        <td class="text-stone-600">{{ $it->label() }}</td>
                        <td>
                            @if($it->note_enabled)<span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px] mr-1">box</span>@endif
                            @if($it->banner_enabled)<span class="px-1.5 py-0.5 rounded bg-indigo-100 text-indigo-700 text-[10px]">popup</span>@endif
                        </td>
                        <td>
                            @if($it->note_enabled || $it->banner_enabled)
                                <span class="text-emerald-600 font-semibold">aktif</span>
                            @else
                                <span class="text-stone-400">nonaktif</span>
                            @endif
                        </td>
                        <td class="text-right px-2 text-stone-500">{{ $it->sort_order }}</td>
                        <td class="text-right px-4">
                            <a href="{{ route('announcements.manage', array_filter(['role' => $filter, 'item' => $it->id])) }}#form" class="text-indigo-600 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('announcements.destroy', $it) }}" class="inline ml-2" onsubmit="return confirm('Hapus pengumuman ini?')">
                                @csrf @method('DELETE')
                                <button class="text-rose-600 hover:underline">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-stone-400">Belum ada pengumuman{{ $filter ? ' untuk role '.$filter : '' }}. Tambah di bawah.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    {{-- Form tambah / edit satu pengumuman --}}
    <div id="form" class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm font-bold text-stone-800 mb-3">{{ $editing->id ? 'Edit pengumuman' : 'Tambah pengumuman baru' }}</p>
        <form method="POST" action="{{ route('announcements.save') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            @if($editing->id)<input type="hidden" name="id" value="{{ $editing->id }}">@endif

            <div class="grid sm:grid-cols-3 gap-3">
                <label class="text-[11px] font-semibold text-stone-500">Untuk role
                    <select name="role" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                        @foreach($roles as $r)<option value="{{ $r }}" @selected(old('role', $editing->role) === $r)>{{ $r }}</option>@endforeach
                    </select>
                </label>
                <label class="text-[11px] font-semibold text-stone-500">Urutan (kecil = atas)
                    <input type="number" name="sort_order" min="0" value="{{ old('sort_order', $editing->sort_order ?? 0) }}"
                        class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                </label>
            </div>

            {{-- Box catatan --}}
            <div class="border-t border-stone-100 pt-4">
                <label class="flex items-center gap-2 text-sm font-semibold text-stone-700">
                    <input type="checkbox" name="note_enabled" value="1" @checked(old('note_enabled', $editing->note_enabled))>
                    Box catatan (nempel di dashboard)
                </label>
                <div class="mt-2 space-y-2">
                    <input name="note_title" maxlength="150" placeholder="Judul (opsional)" value="{{ old('note_title', $editing->note_title) }}"
                        class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <textarea name="note_body" rows="3" maxlength="5000" placeholder="Isi catatan… (URL otomatis jadi tautan)"
                        class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">{{ old('note_body', $editing->note_body) }}</textarea>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <input name="note_link" type="url" maxlength="255" placeholder="Link tombol (opsional)" value="{{ old('note_link', $editing->note_link) }}"
                            class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                        <input name="note_link_label" maxlength="60" placeholder="Teks tombol (mis. Klik di sini)" value="{{ old('note_link_label', $editing->note_link_label) }}"
                            class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </div>
                </div>
            </div>

            {{-- Popup banner --}}
            <div class="border-t border-stone-100 pt-4">
                <label class="flex items-center gap-2 text-sm font-semibold text-stone-700">
                    <input type="checkbox" name="banner_enabled" value="1" @checked(old('banner_enabled', $editing->banner_enabled))>
                    Popup banner (muncul sekali tiap login)
                </label>
                <div class="mt-2 space-y-2">
                    @if($editing->bannerUrl())
                        <div>
                            <img src="{{ $editing->bannerUrl() }}" alt="banner" class="max-h-40 rounded-lg border border-stone-200">
                            <label class="flex items-center gap-2 text-[11px] text-rose-600 mt-1"><input type="checkbox" name="remove_banner" value="1"> hapus gambar ini</label>
                        </div>
                    @endif
                    <input type="file" name="banner" accept="image/*" class="block text-xs">
                    <input name="banner_link" type="url" maxlength="500" placeholder="Link saat banner diklik (opsional)" value="{{ old('banner_link', $editing->banner_link) }}"
                        class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                </div>
            </div>

            <div class="flex items-center gap-2">
                <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">{{ $editing->id ? 'Simpan Perubahan' : 'Tambah' }}</button>
                @if($editing->id)
                    <a href="{{ route('announcements.manage', array_filter(['role' => $filter])) }}" class="px-4 py-2.5 text-sm text-stone-500 hover:text-stone-800">Batal edit</a>
                @endif
            </div>
        </form>
    </div>

    {{-- Komunitas WA per role: tombol hijau di sidebar tiap role. Satu per role. --}}
    <div id="komunitas" class="bg-white rounded-2xl border border-stone-200 p-5 mt-6">
        <p class="text-sm font-bold text-stone-800">Komunitas WA per Role</p>
        <p class="text-[11px] text-stone-500 mb-4">Kalau aktif, tombol hijau <b>“Gabung Komunitas WA”</b> muncul di sidebar role tersebut. QR opsional (tinggal unduh QR grup dari WhatsApp) — kalau ada, klik tombolnya buka popup QR; kalau tidak, langsung ke link.</p>

        <div class="space-y-3">
            @foreach($roles as $r)
                @php($c = $communities->get($r) ?? new \App\Models\CommunityLink(['role' => $r]))
                <form method="POST" action="{{ route('announcements.community.save') }}" enctype="multipart/form-data"
                    class="border border-stone-200 rounded-xl p-3">
                    @csrf
                    <input type="hidden" name="role" value="{{ $r }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <div class="flex items-center gap-3">
                            <span class="text-xs font-bold text-stone-700">{{ $r }}</span>
                            <label class="flex items-center gap-1.5 text-[11px] font-semibold text-stone-600">
                                <input type="checkbox" name="enabled" value="1" @checked($c->enabled)> aktifkan
                            </label>
                        </div>
                        <button class="px-3 py-1.5 text-xs bg-green-600 text-white rounded-lg hover:bg-green-700 font-semibold">Simpan</button>
                    </div>
                    <div class="grid sm:grid-cols-2 gap-2">
                        <input name="label" maxlength="60" placeholder="Teks tombol (default: Gabung Komunitas WA)" value="{{ $c->label }}"
                            class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                        <input name="link" type="url" maxlength="500" placeholder="https://chat.whatsapp.com/…" value="{{ $c->link }}"
                            class="block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    </div>
                    <div class="flex flex-wrap items-center gap-3 mt-2">
                        @if($c->qrUrl())
                            <img src="{{ $c->qrUrl() }}" alt="QR {{ $r }}" class="w-14 h-14 object-contain rounded border border-stone-200">
                            <label class="flex items-center gap-1 text-[11px] text-rose-600"><input type="checkbox" name="remove_qr" value="1"> hapus QR</label>
                        @endif
                        <label class="text-[11px] text-stone-500">QR (opsional)
                            <input type="file" name="qr" accept="image/*" class="block text-xs mt-0.5">
                        </label>
                    </div>
                </form>
            @endforeach
        </div>
    </div>
</div>
@endsection
