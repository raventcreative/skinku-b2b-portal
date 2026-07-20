@extends('layouts.app')
@section('title', 'Screening KOL')
@section('heading', 'Screening / Kurasi KOL')

@section('content')
<div class="max-w-3xl">
    <a href="{{ route('kols.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Database KOL</a>

    <form method="POST" action="{{ route('kol-screenings.store') }}" class="bg-white rounded-2xl border border-stone-200 p-5 mt-3">@csrf
        <p class="text-sm text-stone-600 mb-4">
            Masukkan ratecard dan views <b>7 video terakhir</b>. Sistem menghitung median, CPM, ratio,
            dan verdict otomatis — hasilnya tampil di riwayat screening KOL.
        </p>

        <div class="grid sm:grid-cols-3 gap-3 mb-4 text-sm">
            <label class="text-[11px] font-semibold text-stone-500">KOL
                <select name="kol_id" required class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
                    <option value="">— pilih KOL —</option>
                    @foreach($kols as $k)
                        <option value="{{ $k->id }}" @selected(old('kol_id', $selectedKolId) == $k->id)>
                            {{ '@'.$k->tiktok_username }} ({{ number_format($k->followers, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Tanggal listing
                <input type="date" name="tanggal_listing" required value="{{ old('tanggal_listing', now()->format('Y-m-d')) }}"
                    class="mt-1 block w-full px-3 py-2 border border-stone-300 rounded-lg text-sm">
            </label>
            <label class="text-[11px] font-semibold text-stone-500">Ratecard (Rp)
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
@endsection
