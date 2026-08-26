@extends('layouts.app')
@section('title', 'Edit Screening · '.$kol->tiktok_username)
@section('heading', 'Edit Screening KOL')

@section('content')
<a href="{{ route('kols.show', $kol) }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Detail KOL</a>

<div class="max-w-3xl mt-3">
    @if($errors->any())
        <div class="mb-4 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">{{ $errors->first() }}</div>
    @endif

    <div class="bg-white rounded-2xl border border-stone-200 p-5">
        <p class="text-sm text-stone-500 mb-4">
            Edit screening <b>{{ '@'.$kol->tiktok_username }}</b> ({{ $screening->tanggal_listing->format('d M Y') }}).
            Angka <b>median / CPM / verdict</b> otomatis dihitung ulang begitu disimpan.
        </p>

        <form method="POST" action="{{ route('kol-screenings.update', $screening) }}" class="space-y-4">
            @csrf @method('PUT')

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Tanggal listing</span>
                    <input type="date" name="tanggal_listing" required max="{{ now()->toDateString() }}"
                        value="{{ old('tanggal_listing', $screening->tanggal_listing->format('Y-m-d')) }}"
                        class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">Ratecard (Rp, opsional)</span>
                    <input type="number" name="ratecard" min="0" value="{{ old('ratecard', $screening->ratecard) }}"
                        placeholder="kosong kalau belum nego" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold text-stone-600">GMV aktual (Rp, opsional)</span>
                    <input type="number" name="gmv" min="0" value="{{ old('gmv', $screening->gmv) }}"
                        class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
                </label>
            </div>

            <label class="block text-sm">
                <span class="text-xs font-semibold text-stone-600">Benefit / deliverable (opsional)</span>
                <input name="benefit" maxlength="500" value="{{ old('benefit', $screening->benefit) }}"
                    placeholder="mis. 1 video + 1 slide story" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">
            </label>

            <div>
                <span class="text-xs font-semibold text-stone-600">Views 7 video terakhir</span>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-2 mt-1">
                    @for($i = 1; $i <= 7; $i++)
                        <label class="block">
                            <span class="text-[10px] text-stone-400">Video {{ $i }}</span>
                            <input type="number" name="views_{{ $i }}" required min="0"
                                value="{{ old('views_'.$i, $screening->{'views_'.$i}) }}"
                                class="w-full px-2 py-1.5 border border-stone-300 rounded-lg text-right text-sm">
                        </label>
                    @endfor
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold rounded-xl">Simpan Perubahan</button>
                <a href="{{ route('kols.show', $kol) }}" class="text-xs text-stone-500 hover:text-stone-800">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
