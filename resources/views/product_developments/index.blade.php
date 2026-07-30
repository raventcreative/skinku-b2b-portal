@extends('layouts.app')
@section('title', 'Pipeline Produk Baru')
@section('heading', 'Pipeline Produk Baru')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <form method="GET" class="flex gap-2">
        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari produk/kategori" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
        <select name="stage" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua tahap</option>
            @foreach(\App\Models\ProductDevelopment::STAGES as $key => $label)<option value="{{ $key }}" @selected(($filters['stage'] ?? '') === $key)>{{ $label }}</option>@endforeach
        </select>
        <button class="px-4 py-2 text-sm bg-stone-200 rounded-lg">Filter</button>
    </form>
    <button type="button" onclick="openPipeline()" class="px-4 py-2 text-sm bg-red-600 text-white rounded-lg">+ Calon Produk</button>
</div>

<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2 mb-4">
    @foreach(\App\Models\ProductDevelopment::STAGES as $key => $label)
        <div class="bg-white border border-stone-200 rounded-xl p-3"><p class="text-[10px] text-stone-500">{{ $label }}</p><p class="text-xl font-bold text-stone-900">{{ \App\Models\ProductDevelopment::where('stage', $key)->count() }}</p></div>
    @endforeach
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-xs whitespace-nowrap">
            <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]"><tr><th class="text-left px-4 py-3">Calon produk</th><th class="text-left">Kategori</th><th class="text-left">Tahap</th><th class="text-left">PIC</th><th class="text-left">Target launch</th><th class="text-left">Produk master</th><th class="text-right px-4">Aksi</th></tr></thead>
            <tbody>
                @forelse($projects as $project)
                    <tr class="border-t border-stone-100">
                        <td class="px-4 py-3"><p class="font-semibold text-stone-800">{{ $project->name }}</p><p class="text-[10px] text-stone-400 max-w-xs truncate">{{ $project->notes }}</p></td>
                        <td>{{ $project->category ?: '—' }}</td><td><span class="px-2 py-1 rounded-full bg-indigo-50 text-indigo-700">{{ $project->stageLabel() }}</span></td><td>{{ $project->owner?->displayName() ?: '—' }}</td><td>{{ $project->target_launch_date?->format('d M Y') ?: '—' }}</td><td>{{ $project->product?->name ?: '—' }}</td>
                        <td class="px-4 text-right">
                            <button type="button" onclick='openPipeline(@json($project->only(["id","name","category","stage","owner_user_id","target_launch_date","product_id","notes"])))' class="text-indigo-600 font-semibold">Edit</button>
                            <form method="POST" action="{{ route('product-developments.destroy', $project) }}" class="inline ml-2" onsubmit="return confirm('Hapus item pipeline ini?')">@csrf @method('DELETE')<button class="text-rose-600 font-semibold">Hapus</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-stone-400">Belum ada calon produk. Tambahkan target 15 item dan gerakkan tahapnya secara nyata.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-4">{{ $projects->links() }}</div>

<div id="pipelineModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-xl p-6">
        <div class="flex justify-between mb-4"><h3 id="pipelineTitle" class="font-bold">Tambah Calon Produk</h3><button type="button" onclick="toggleModal('pipelineModal')">✕</button></div>
        <form id="pipelineForm" method="POST" action="{{ route('product-developments.store') }}" class="grid grid-cols-2 gap-3 text-sm">
            @csrf <input id="pipelineMethod" type="hidden" name="_method" value="POST">
            <label class="col-span-2"><span class="text-xs text-stone-500">Nama calon produk *</span><input name="name" required maxlength="255" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
            <label><span class="text-xs text-stone-500">Kategori</span><input name="category" maxlength="100" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
            <label><span class="text-xs text-stone-500">Tahap *</span><select name="stage" required class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg">@foreach(\App\Models\ProductDevelopment::STAGES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <label><span class="text-xs text-stone-500">PIC</span><select name="owner_user_id" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"><option value="">—</option>@foreach($members as $member)<option value="{{ $member->id }}">{{ $member->displayName() }}</option>@endforeach</select></label>
            <label><span class="text-xs text-stone-500">Target launch</span><input type="date" name="target_launch_date" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></label>
            <label class="col-span-2"><span class="text-xs text-stone-500">Tautkan Produk Master setelah siap</span><select name="product_id" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"><option value="">Belum ditautkan</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} · {{ $product->sku }}</option>@endforeach</select></label>
            <label class="col-span-2"><span class="text-xs text-stone-500">Catatan/gate validasi</span><textarea name="notes" rows="3" maxlength="4000" class="mt-1 w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea></label>
            <div class="col-span-2 text-right"><button type="button" onclick="toggleModal('pipelineModal')" class="px-4 py-2">Batal</button><button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button></div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function openPipeline(row) {
    const form = document.getElementById('pipelineForm');
    form.reset();
    const edit = row && row.id;
    form.action = edit ? '/product-developments/' + row.id : '{{ route('product-developments.store') }}';
    document.getElementById('pipelineMethod').value = edit ? 'PUT' : 'POST';
    document.getElementById('pipelineTitle').textContent = edit ? 'Edit Pipeline Produk' : 'Tambah Calon Produk';
    if (edit) {
        for (const key of ['name','category','stage','owner_user_id','target_launch_date','product_id','notes']) {
            const input = form.querySelector('[name=' + key + ']');
            if (input) input.value = row[key] ?? '';
        }
    }
    toggleModal('pipelineModal');
}
</script>
@endpush
