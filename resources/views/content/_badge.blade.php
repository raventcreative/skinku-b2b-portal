{{-- Badge status konten / target. $status + $label. Kelas ditulis utuh supaya terdeteksi Tailwind. --}}
@php
    $cls = match ($status) {
        'draft', 'pending' => 'bg-stone-100 text-stone-600',
        'in_review', 'manual_pending' => 'bg-amber-100 text-amber-800',
        'rejected', 'failed' => 'bg-rose-100 text-rose-700',
        'scheduled', 'queued' => 'bg-blue-100 text-blue-700',
        'publishing', 'partial' => 'bg-indigo-100 text-indigo-700',
        'done', 'published' => 'bg-emerald-100 text-emerald-700',
        default => 'bg-stone-100 text-stone-600',
    };
@endphp
<span class="inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold whitespace-nowrap {{ $cls }}">{{ $label }}</span>
