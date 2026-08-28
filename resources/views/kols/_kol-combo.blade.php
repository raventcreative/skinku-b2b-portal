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
            @php
                // Metadata tampil hanya bila kolomnya di-load (peran/status/followers).
                $label = ($atPrefix ? '@' : '').$k->tiktok_username;
                $roleLbl = $k->getAttribute('role') ? (\App\Models\Kol::ROLE_LABELS[$k->role] ?? null) : null;
                $lvl = array_key_exists('followers', $k->getAttributes()) ? $k->level : null;
                $st = $k->getAttribute('status');
                $blk = $st === \App\Models\Kol::STATUS_BLACKLIST;
                $nonaktif = $st === \App\Models\Kol::STATUS_NON_AKTIF;
                $meta = collect([$roleLbl, $lvl])->filter()->implode(' · ');
            @endphp
            <div class="combo-opt px-3 py-1.5 hover:bg-stone-100 cursor-pointer flex items-center gap-1.5 flex-wrap" data-value="{{ $k->id }}" data-label="{{ $label }}"
                data-median="{{ $k->relationLoaded('latestScreening') && $k->latestScreening ? (int) $k->latestScreening->median_views : 0 }}"
                data-rate="{{ $k->relationLoaded('latestScreening') && $k->latestScreening ? (int) $k->latestScreening->ratecard : 0 }}">
                <span class="font-medium {{ $blk ? 'text-rose-600' : 'text-stone-700' }}">{{ $label }}</span>
                @if($meta)<span class="text-[11px] text-stone-400">· {{ $meta }}</span>@endif
                @if($blk)<span class="text-[10px] font-semibold text-rose-500">⛔ blacklist</span>
                @elseif($nonaktif)<span class="text-[10px] text-stone-400">(nonaktif)</span>@endif
            </div>
        @endforeach
    </div>
</div>
