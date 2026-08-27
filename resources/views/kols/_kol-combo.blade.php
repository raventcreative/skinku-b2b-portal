{{-- Combobox KOL: 1 field — ketik untuk nyaring + klik untuk pilih. Set hidden
     input $name ke id KOL. Dipakai di pipeline, konten, KSS. Var: kols, name,
     id, selected, selectedLabel, placeholder. --}}
@php
    $name = $name ?? 'kol_id';
    $comboId = $id ?? 'kolcombo'.\Illuminate\Support\Str::random(5);
    $selected = $selected ?? null;
    $selectedLabel = $selectedLabel ?? null;
    $placeholder = $placeholder ?? '🔎 ketik / pilih KOL…';
    $atPrefix = $atPrefix ?? true;   // tampilkan "@username"; false = username polos
@endphp
<div id="{{ $comboId }}" class="skinku-combo relative mt-1">
    <input type="hidden" name="{{ $name }}" value="{{ $selected }}" class="combo-value">
    <input type="text" autocomplete="off" placeholder="{{ $placeholder }}" value="{{ $selectedLabel }}"
        class="combo-input w-full px-3 py-2 border border-stone-300 rounded-lg bg-white">
    <div class="combo-list hidden absolute z-30 mt-1 w-full max-h-56 overflow-auto bg-white border border-stone-200 rounded-lg shadow-lg text-sm">
        @foreach($kols as $k)
            <div class="combo-opt px-3 py-1.5 hover:bg-stone-100 cursor-pointer" data-value="{{ $k->id }}"
                data-median="{{ $k->relationLoaded('latestScreening') && $k->latestScreening ? (int) $k->latestScreening->median_views : 0 }}">{{ ($atPrefix ? '@' : '').$k->tiktok_username }}</div>
        @endforeach
    </div>
</div>
