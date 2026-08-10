@extends('layouts.app')
@section('title', 'Struktur Jaringan')
@section('heading', 'Struktur Jaringan Mitra')

@php
$allowedParents = collect(\App\Support\PartnerHierarchy::TIERS)->keys()
    ->mapWithKeys(fn ($role) => [$role => \App\Support\PartnerHierarchy::allowedParentRoles($role)])->all();
@endphp

@section('content')
<div class="space-y-6">
    <p class="text-xs text-stone-500">💡 <b>Seret</b> mitra ke node lain untuk jadikan <b>upline</b>-nya · seret ke area <b>"Belum ditempatkan"</b> untuk melepas. Perubahan langsung tersimpan ke data anggota.</p>

    <div class="bg-white rounded-2xl border border-stone-200 p-4">
        @forelse($roots as $root)
            <ul class="list-none">@include('struktur_jaringan._node', ['node' => $root])</ul>
        @empty
            <p class="text-sm text-stone-500">Belum ada Grand Distributor yang ditempatkan. Mulai tempatkan mitra lewat Kelola Anggota atau seret dari panel di bawah.</p>
        @endforelse
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-4" data-drop-unplaced="1">
        <h2 class="text-sm font-semibold text-stone-600 mb-2">Belum ditempatkan ({{ $unplaced->count() }})</h2>
        @if($unplaced->isEmpty())
            <p class="text-xs text-stone-400">Semua mitra sudah ditempatkan di pohon.</p>
        @else
            <ul class="flex flex-wrap gap-2">
                @foreach($unplaced as $u)
                    <li draggable="true" data-drag-user="{{ $u->id }}" data-drag-role="{{ $u->role }}"
                        class="rounded border border-dashed border-stone-300 px-2 py-1 text-sm cursor-move">
                        {{ $u->fullname }} <span class="text-xs text-stone-400">{{ \App\Support\PartnerHierarchy::label($u->role) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const CSRF = '{{ csrf_token() }}';
    const BASE = "{{ url('struktur-jaringan') }}";
    const ALLOWED_PARENTS = {!! json_encode($allowedParents) !!};
    let dragged = null;

    function canDropOn(role) {
        if (!dragged) return false;
        return (ALLOWED_PARENTS[dragged.role] || []).indexOf(role) !== -1;
    }
    function clearRings() {
        document.querySelectorAll('.ring-2.ring-emerald-400').forEach(function (el) {
            el.classList.remove('ring-2', 'ring-emerald-400');
        });
    }

    document.addEventListener('dragstart', function (e) {
        const el = e.target.closest('[data-drag-user]');
        if (!el) return;
        dragged = { id: el.dataset.dragUser, role: el.dataset.dragRole };
        e.dataTransfer.effectAllowed = 'move';
        el.classList.add('opacity-50');
    });
    document.addEventListener('dragend', function (e) {
        const el = e.target.closest('[data-drag-user]');
        if (el) el.classList.remove('opacity-50');
        clearRings();
        dragged = null;
    });
    document.addEventListener('dragover', function (e) {
        if (!dragged) return;
        const node = e.target.closest('[data-drop-node]');
        const zone = e.target.closest('[data-drop-unplaced]');
        if (node && node.dataset.dropNode !== dragged.id && canDropOn(node.dataset.dropRole)) {
            e.preventDefault();
            node.classList.add('ring-2', 'ring-emerald-400');
        } else if (zone) {
            e.preventDefault();
            zone.classList.add('ring-2', 'ring-emerald-400');
        }
    });
    document.addEventListener('dragleave', function (e) {
        const t = e.target.closest('[data-drop-node],[data-drop-unplaced]');
        if (t) t.classList.remove('ring-2', 'ring-emerald-400');
    });
    document.addEventListener('drop', function (e) {
        if (!dragged) return;
        const node = e.target.closest('[data-drop-node]');
        const zone = e.target.closest('[data-drop-unplaced]');
        if (node && node.dataset.dropNode !== dragged.id && canDropOn(node.dataset.dropRole)) {
            e.preventDefault();
            place(dragged.id, node.dataset.dropNode);
        } else if (zone) {
            e.preventDefault();
            place(dragged.id, null);
        }
    });

    function place(userId, uplineId) {
        fetch(BASE + '/' + userId + '/place', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ upline_id: uplineId }),
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
              if (res.ok && res.j.ok) { location.reload(); }
              else { alert((res.j && res.j.error) || 'Gagal memindah.'); }
          }).catch(function () { alert('Gagal memindah (jaringan).'); });
    }
})();
</script>
@endpush
