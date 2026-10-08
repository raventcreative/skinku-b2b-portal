{{-- Pemilih periode: dropdown bulan yang ada jurnalnya + input bebas untuk bulan lain. --}}
<form method="GET" class="flex flex-wrap items-end gap-2">
    <div>
        <label class="label" for="period">Periode</label>
        <input type="month" id="period" name="period" value="{{ $period }}" class="field w-40">
    </div>
    @if (count($periods) > 1)
        <div class="flex flex-wrap gap-1 pb-1">
            @foreach (array_slice($periods, 0, 6) as $p)
                <a href="{{ request()->fullUrlWithQuery(['period' => $p]) }}"
                   class="badge {{ $p === $period ? 'bg-brand-600 text-white' : 'bg-white text-ink-600 border border-ink-200' }}">{{ $p }}</a>
            @endforeach
        </div>
    @endif
    <button class="btn-ghost">Tampilkan</button>
</form>
