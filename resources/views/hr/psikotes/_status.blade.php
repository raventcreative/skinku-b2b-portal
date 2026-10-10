{{-- Chip status sesi psikotes ($sesi). --}}
@php
    $statusSesi = $sesi->statusLabel();
    $warnaSesi = ['Selesai' => 'bg-emerald-50 text-emerald-700', 'Kedaluwarsa' => 'bg-rose-50 text-rose-700', 'Sedang dikerjakan' => 'bg-amber-100 text-amber-800'];
@endphp
<span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $warnaSesi[$statusSesi] ?? 'bg-stone-100 text-stone-700' }}">{{ $statusSesi }}</span>
