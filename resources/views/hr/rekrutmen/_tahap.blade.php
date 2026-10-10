{{-- Chip tahap seleksi kandidat ($stage). --}}
@php
    $warnaTahap = [
        'lamar' => 'bg-stone-100 text-stone-700',
        'psikotes' => 'bg-sky-50 text-sky-700',
        'interview' => 'bg-amber-100 text-amber-800',
        'diterima' => 'bg-emerald-50 text-emerald-700',
        'ditolak' => 'bg-rose-50 text-rose-700',
    ];
@endphp
<span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $warnaTahap[$stage] ?? 'bg-stone-100 text-stone-700' }}">{{ \App\Models\Candidate::STAGES[$stage] ?? $stage }}</span>
