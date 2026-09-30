{{-- Badge status konten / target. $status + $label. Kelas ditulis utuh supaya terdeteksi Tailwind. --}}
@php
    $cls = match ($status) {
        'draft', 'pending' => 'bg-stone-100 text-stone-600',
        'manual_pending' => 'bg-amber-100 text-amber-800',
        'failed' => 'bg-rose-100 text-rose-700',
        'scheduled', 'queued' => 'bg-red-50 text-red-800',
        'publishing', 'partial' => 'bg-blue-100 text-blue-800',
        'done', 'published' => 'bg-emerald-100 text-emerald-700',
        default => 'bg-stone-100 text-stone-600',
    };
@endphp
<span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full text-xs leading-none font-semibold whitespace-nowrap {{ $cls }}">{{ $label }}</span>
