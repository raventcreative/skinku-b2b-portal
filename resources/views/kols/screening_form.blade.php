@extends('layouts.app')
@section('title', 'Screening KOL')
@section('heading', 'Screening / Kurasi KOL')

@section('content')
<div class="max-w-3xl">
    <a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Database KOL</a>

    <form method="POST" action="{{ route('kol-screenings.store') }}" class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">@csrf
        <p class="text-sm text-stone-600 mb-4">
            Satu form untuk semuanya — persis satu baris di Excel: data KOL + ratecard + views
            <b>7 video terakhir</b>. Username yang sudah terdaftar otomatis dipakai ulang (ketik untuk
            melihat saran); yang baru langsung dibuatkan. Tak perlu input dua kali.
        </p>

        <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400 mb-2">Data KOL</p>
        <div class="grid sm:grid-cols-3 gap-3 mb-4 text-sm">
            <label class="text-[11px] font-semibold text-stone-500">Username TikTok
                <input name="tiktok_username" id="usernameInput" required maxlength="100" list="kolList"
                    autocomplete="off" placeholder="tanpa @" value="{{ old('tiktok_username', $selectedKol?->tiktok_username) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                <datalist id="kolList">
                    @foreach($kols as $k)<option value="{{ $k->tiktok_username }}">@endforeach
                </datalist>
                <span id="kolHint" class="block mt-1 text-[10px] text-stone-400"></span>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Link TikTok
                <input name="tiktok_link" id="linkInput" type="url" maxlength="255" placeholder="https://tiktok.com/@…"
                    value="{{ old('tiktok_link', $selectedKol?->tiktok_link) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Followers
                <input name="followers" id="followersInput" type="number" required min="0"
                    value="{{ old('followers', $selectedKol?->followers) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Kategori
                <select name="kategori" id="kategoriInput" class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— kategori —</option>
                    @foreach($kategoriList as $kat)
                        <option value="{{ $kat }}" @selected(old('kategori', $selectedKol?->kategori) === $kat)>{{ $kat }}</option>
                    @endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Provinsi
                <input name="provinsi" id="provinsiInput" maxlength="100" placeholder="opsional"
                    value="{{ old('provinsi', $selectedKol?->provinsi) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Agency / Non Agency
                <input name="agency" id="agencyInput" maxlength="150" placeholder="kosongkan bila non-agency"
                    value="{{ old('agency', $selectedKol?->agency) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
        </div>

        <p class="text-[11px] font-semibold uppercase tracking-wide text-stone-400 mb-2">Kurasi</p>
        <div class="grid sm:grid-cols-2 gap-3 mb-4 text-sm">
            <label class="text-[11px] font-semibold text-stone-500">Tanggal listing
                <input type="date" name="tanggal_listing" required value="{{ old('tanggal_listing', now()->format('Y-m-d')) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Ratecard (Rp, harga kerjasama per video)
                <input type="number" name="ratecard" required min="0" value="{{ old('ratecard') }}" placeholder="mis. 1500000"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
        </div>

        <p class="text-[11px] font-semibold text-stone-500 mb-1">Views 7 video terakhir</p>
        <div class="grid grid-cols-4 sm:grid-cols-7 gap-2 mb-4">
            @for($i = 1; $i <= 7; $i++)
                <input type="number" name="views_{{ $i }}" required min="0" value="{{ old('views_'.$i) }}"
                    placeholder="video {{ $i }}" class="px-2 py-2 border border-stone-300 rounded-lg text-sm text-right">
            @endfor
        </div>

        @if($errors->any())
            <p class="mb-3 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
        @endif

        <button class="px-5 py-2.5 text-sm bg-red-600 text-white rounded-xl hover:bg-red-700 font-semibold">Simpan &amp; Hitung Verdict</button>
    </form>
</div>

<script>
// Username lama dipilih → pra-isi link/followers/kategori/provinsi dari data
// tersimpan, dan beri tahu bahwa KOL-nya sudah terdaftar. Ini pemanis saja —
// server yang memutuskan pakai-ulang vs buat-baru berdasarkan username.
const KOLS = @json($kols);
const inp = document.getElementById('usernameInput');
const hint = document.getElementById('kolHint');

inp.addEventListener('input', () => {
    const name = inp.value.trim().replace(/^@/, '');
    const k = KOLS.find(x => x.tiktok_username.toLowerCase() === name.toLowerCase());
    if (k) {
        hint.textContent = '✓ sudah terdaftar — screening ditambahkan ke KOL ini';
        for (const [id, val] of [['linkInput', k.tiktok_link], ['followersInput', k.followers], ['kategoriInput', k.kategori], ['provinsiInput', k.provinsi], ['agencyInput', k.agency]]) {
            const el = document.getElementById(id);
            if (el && val !== null && val !== '') el.value = val;
        }
    } else {
        hint.textContent = name ? 'KOL baru — akan dibuatkan otomatis' : '';
    }
});
</script>
@endsection
