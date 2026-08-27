@extends('layouts.app')
@section('title', $content->exists ? 'Edit Konten' : 'Tambah Konten')
@section('heading', $content->exists ? 'Edit Konten KOL' : 'Tambah Konten KOL')

@section('content')
<a href="{{ route('kol-konten.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Konten &amp; Views</a>

<div class="max-w-2xl mt-3">
    @if($errors->any())
        <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <form method="POST" action="{{ $content->exists ? route('kol-konten.update', $content) : route('kol-konten.store') }}" class="space-y-4">
            @csrf
            @if($content->exists) @method('PUT') @endif

            <div class="grid sm:grid-cols-2 gap-3 text-sm">
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">KOL</span>
                    <select name="kol_id" id="kolSelect" required class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        <option value="">— pilih KOL —</option>
                        @foreach($kols as $k)
                            <option value="{{ $k->id }}" @selected(old('kol_id', $content->kol_id) == $k->id)>{{ '@'.$k->tiktok_username }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Deal (opsional → jadi paid)</span>
                    <select name="kol_deal_id" id="dealSelect" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        <option value="">— tanpa deal (earned) —</option>
                        @foreach($deals as $d)
                            <option value="{{ $d->id }}" data-kol="{{ $d->kol_id }}" @selected(old('kol_deal_id', $content->kol_deal_id) == $d->id)>{{ $d->kode }} · {{ '@'.$d->kol?->tiktok_username }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">URL konten</span>
                <div class="mt-1 flex gap-2">
                    <input name="url" id="urlInput" required maxlength="255" value="{{ old('url', $content->url) }}"
                        placeholder="https://www.tiktok.com/@.../video/..." class="flex-1 px-3 py-2 border border-stone-300 rounded-lg">
                    <button type="button" id="fetchTitle" class="px-3 py-2 text-xs font-semibold rounded-lg border border-stone-300 text-stone-600 hover:bg-stone-50 whitespace-nowrap">Ambil judul</button>
                </div>
            </label>

            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Judul (opsional)</span>
                <input name="title" id="titleInput" maxlength="255" value="{{ old('title', $content->title) }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
            </label>

            <div class="grid sm:grid-cols-3 gap-3 text-sm">
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Platform</span>
                    <select name="platform" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        @foreach($platforms as $val => $p)
                            <option value="{{ $val }}" @selected(old('platform', $content->platform ?? 'tiktok') === $val)>{{ $p['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Label</span>
                    <select name="label" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
                        <option value="earned" @selected(old('label', $content->label ?? 'earned') === 'earned')>Earned (organik)</option>
                        <option value="paid" @selected(old('label', $content->label) === 'paid')>Paid (dari deal)</option>
                    </select>
                    <span class="text-[10px] text-stone-400">Kalau ada deal, otomatis jadi paid.</span>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Tanggal posting</span>
                    <input type="date" name="posted_at" required max="{{ now()->toDateString() }}"
                        value="{{ old('posted_at', optional($content->posted_at)->format('Y-m-d')) }}" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">{{ $content->exists ? 'Simpan Perubahan' : 'Tambah Konten' }}</button>
                <a href="{{ route('kol-konten.index') }}" class="text-xs text-stone-500 hover:text-stone-800">Batal</a>
            </div>
        </form>
    </div>
</div>

<script>
    // Filter opsi deal sesuai KOL terpilih (deal harus milik KOL yang sama).
    (function () {
        var kolSel = document.getElementById('kolSelect');
        var dealSel = document.getElementById('dealSelect');
        function filterDeals() {
            var kid = kolSel.value;
            Array.from(dealSel.options).forEach(function (o) {
                if (!o.value) return;
                var show = o.getAttribute('data-kol') === kid;
                o.hidden = !show;
                if (!show && o.selected) { dealSel.value = ''; }
            });
        }
        kolSel.addEventListener('change', filterDeals);
        filterDeals();

        // Ambil judul via oEmbed (server, host allowlist tiktok.com).
        document.getElementById('fetchTitle').addEventListener('click', function () {
            var url = document.getElementById('urlInput').value;
            if (!url) return;
            this.disabled = true; this.textContent = '...';
            var btn = this;
            fetch('{{ route('kol-konten.oembed') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                body: JSON.stringify({ url: url })
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (d.title) document.getElementById('titleInput').value = d.title;
            }).finally(function () { btn.disabled = false; btn.textContent = 'Ambil judul'; });
        });
    })();
</script>
@endsection
