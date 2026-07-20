@extends('layouts.app')
@section('title', 'Kanban · '.$board->name)
@section('heading', 'Papan: '.$board->name)

@push('head')
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
@endpush

@section('content')
@php $u = auth()->user(); @endphp

<div class="flex flex-wrap items-center gap-3 mb-4">
    <a href="{{ route('kanban.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Semua papan</a>
    <details class="relative">
        <summary class="text-xs text-stone-500 cursor-pointer select-none hover:text-stone-800">ubah nama papan</summary>
        <form method="POST" action="{{ route('kanban.update', $board) }}" class="absolute z-20 mt-1 bg-white border border-stone-200 rounded-lg shadow p-2 flex gap-1">
            @csrf @method('PUT')
            <input name="name" value="{{ $board->name }}" required maxlength="150" class="px-2 py-1 border border-stone-300 rounded text-xs w-48">
            <button class="px-2 py-1 bg-stone-700 text-white rounded text-xs">Simpan</button>
        </form>
    </details>
    <span class="ml-auto text-[11px] text-stone-400">Geser kartu antar kolom — tersimpan otomatis & tercatat di Audit Log.</span>
</div>

@if($errors->any())
    <p class="mb-4 px-3 py-2 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs">{{ $errors->first() }}</p>
@endif

{{-- Papan: kolom berdampingan, scroll horizontal seperti Trello. --}}
<div id="boardColumns" class="flex gap-4 items-start overflow-x-auto pb-4">
    @foreach($board->columns as $column)
        <div class="w-72 shrink-0 bg-stone-100 rounded-2xl border border-stone-200" data-column="{{ $column->id }}">
            <div class="px-4 py-3 flex items-center gap-2 cursor-grab" data-col-handle>
                <p class="font-bold text-stone-800 text-sm flex-1">{{ $column->name }}
                    <span class="font-normal text-stone-400">({{ $column->cards->count() }})</span>
                </p>
                <details class="relative">
                    <summary class="text-stone-400 hover:text-stone-700 cursor-pointer select-none text-xs px-1">⋯</summary>
                    <div class="absolute right-0 z-20 mt-1 bg-white border border-stone-200 rounded-lg shadow p-2 w-52 space-y-2">
                        <form method="POST" action="{{ route('kanban.columns.update', $column) }}" class="flex gap-1">
                            @csrf @method('PUT')
                            <input name="name" value="{{ $column->name }}" required maxlength="100" class="flex-1 px-2 py-1 border border-stone-300 rounded text-xs">
                            <button class="px-2 py-1 bg-stone-700 text-white rounded text-xs">OK</button>
                        </form>
                        <form method="POST" action="{{ route('kanban.columns.destroy', $column) }}"
                            onsubmit="return confirm('Hapus kolom {{ $column->name }}? (hanya bisa bila kosong)')">
                            @csrf @method('DELETE')
                            <button class="w-full text-left text-[11px] text-rose-500 hover:text-rose-700">hapus kolom (harus kosong)</button>
                        </form>
                    </div>
                </details>
            </div>

            <div class="px-2 pb-2 space-y-2 min-h-[2.5rem]" data-cards data-column-id="{{ $column->id }}">
                @foreach($column->cards as $card)
                    @php $overdue = $card->due_date && $card->due_date->isPast(); @endphp
                    <div class="bg-white rounded-xl border border-stone-200 shadow-sm p-3 cursor-grab" data-card="{{ $card->id }}">
                        <details>
                            <summary class="list-none cursor-pointer">
                                <p class="text-sm font-semibold text-stone-800">{{ $card->title }}</p>
                                <div class="flex flex-wrap items-center gap-2 mt-1 text-[10px]">
                                    @if($card->assignee)
                                        <span class="px-1.5 py-0.5 rounded bg-indigo-50 text-indigo-700 font-semibold">{{ $card->assignee->fullname }}</span>
                                    @endif
                                    @if($card->due_date)
                                        <span class="{{ $overdue ? 'text-rose-600 font-bold' : 'text-stone-400' }}">📅 {{ $card->due_date->format('d M') }}{{ $overdue ? ' — lewat!' : '' }}</span>
                                    @endif
                                </div>
                                @if($card->description)<p class="text-[11px] text-stone-500 mt-1 line-clamp-2">{{ $card->description }}</p>@endif
                            </summary>
                            {{-- Klik kartu → form edit lengkap. --}}
                            <form method="POST" action="{{ route('kanban.cards.update', $card) }}" class="mt-2 pt-2 border-t border-stone-100 space-y-2">
                                @csrf @method('PUT')
                                <input name="title" value="{{ $card->title }}" required maxlength="255" class="w-full px-2 py-1 border border-stone-300 rounded text-xs">
                                <textarea name="description" rows="2" maxlength="5000" placeholder="deskripsi…" class="w-full px-2 py-1 border border-stone-300 rounded text-xs">{{ $card->description }}</textarea>
                                <select name="assignee_user_id" class="w-full px-2 py-1 border border-stone-300 rounded text-xs">
                                    <option value="">— penanggung jawab —</option>
                                    @foreach($assignees as $a)
                                        <option value="{{ $a->id }}" @selected($card->assignee_user_id === $a->id)>{{ $a->fullname }}</option>
                                    @endforeach
                                </select>
                                <input type="date" name="due_date" value="{{ $card->due_date?->format('Y-m-d') }}" class="w-full px-2 py-1 border border-stone-300 rounded text-xs">
                                <div class="flex justify-between items-center">
                                    <button class="px-3 py-1 bg-stone-700 text-white rounded text-xs">Simpan</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('kanban.cards.destroy', $card) }}" class="mt-1 text-right"
                                onsubmit="return confirm('Hapus kartu {{ $card->title }}?')">
                                @csrf @method('DELETE')
                                <button class="text-[10px] text-rose-500 hover:text-rose-700">hapus kartu</button>
                            </form>
                        </details>
                    </div>
                @endforeach
            </div>

            <form method="POST" action="{{ route('kanban.cards.store', $column) }}" class="p-2 pt-0">@csrf
                <input name="title" required maxlength="255" placeholder="+ tambah kartu…"
                    class="w-full px-3 py-2 bg-transparent border border-dashed border-stone-300 rounded-xl text-xs placeholder-stone-400 focus:bg-white">
            </form>
        </div>
    @endforeach

    {{-- Tambah kolom --}}
    <form method="POST" action="{{ route('kanban.columns.store', $board) }}" class="w-64 shrink-0">@csrf
        <input name="name" required maxlength="100" placeholder="+ tambah kolom…"
            class="w-full px-3 py-2.5 bg-stone-100 border border-dashed border-stone-300 rounded-2xl text-sm placeholder-stone-400 focus:bg-white">
    </form>
</div>

<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
const post = (url, body) => fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
    body: JSON.stringify(body),
}).then(r => { if (!r.ok) { alert('Gagal menyimpan perpindahan — muat ulang halaman.'); location.reload(); } });

// Drag kartu antar/dalam kolom.
document.querySelectorAll('[data-cards]').forEach(el => {
    new Sortable(el, {
        group: 'cards',
        animation: 150,
        onEnd: (evt) => {
            const cardId = evt.item.dataset.card;
            const target = evt.to;
            const orderedIds = [...target.querySelectorAll('[data-card]')].map(c => c.dataset.card);
            post(`{{ url('/kanban-cards') }}/${cardId}/move`, {
                column_id: parseInt(target.dataset.columnId),
                ordered_ids: orderedIds,
            });
        },
    });
});

// Drag urutan kolom (pegang header-nya).
new Sortable(document.getElementById('boardColumns'), {
    animation: 150,
    handle: '[data-col-handle]',
    draggable: '[data-column]',
    onEnd: () => {
        const ids = [...document.querySelectorAll('[data-column]')].map(c => c.dataset.column);
        post(`{{ route('kanban.columns.reorder', $board) }}`, { ordered_ids: ids });
    },
});
</script>
@endsection
