@php
    $map = [
        \App\Models\Document::STATUS_UPLOADED => ['badge-mute', 'Belum dibaca'],
        \App\Models\Document::STATUS_EXTRACTED => ['badge-warn', 'Perlu review'],
        \App\Models\Document::STATUS_FAILED => ['badge-bad', 'Gagal dibaca'],
        \App\Models\Document::STATUS_POSTED => ['badge-ok', 'Sudah diposting'],
    ];
    [$class, $label] = $map[$document->status] ?? ['badge-mute', $document->status];
@endphp
<span class="{{ $class }} shrink-0">{{ $label }}</span>
