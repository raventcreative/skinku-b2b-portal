@extends('layouts.app')
@section('title', 'SKINKU Academy')
@section('heading', 'SKINKU Academy')

@section('content')
<div class="mb-5 flex flex-wrap items-end justify-between gap-4 rounded-2xl border border-stone-200 bg-white p-5">
    <div>
        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-red-700">Pusat belajar</p>
        <p class="mt-1 text-sm text-stone-600">Materi pelatihan SKINKU tersusun per modul. Pilih materi untuk membuka video atau dokumen.</p>
        <p class="mt-2 text-xs font-medium text-stone-400">{{ $modules->count() }} modul <span class="mx-1">·</span> {{ $lessons->count() }} materi</p>
    </div>
    @if($canManage)
        <div class="flex flex-wrap gap-2">
            <button onclick="openModule()" class="inline-flex min-h-10 items-center justify-center rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">Tambah modul</button>
            <button onclick="openLesson()" class="inline-flex min-h-10 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Tambah materi</button>
        </div>
    @endif
</div>

@php $ungrouped = $lessons->filter(fn ($l) => ! $l->module_id); @endphp

@if($modules->isEmpty() && $lessons->isEmpty())
    <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center">
        <h2 class="text-base font-bold text-stone-800">Belum ada materi belajar</h2>
        <p class="mx-auto mt-2 max-w-md text-sm text-stone-500">Materi yang dipublikasikan akan muncul di sini dan dikelompokkan berdasarkan modul.</p>
        @if($canManage)<button onclick="openModule()" class="mt-4 inline-flex min-h-10 items-center rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">Buat modul pertama</button>@endif
    </div>
@endif

@foreach($modules as $module)
    @php $mLessons = $lessons->where('module_id', $module->id); @endphp
    <section class="mb-8">
        <div class="mb-3 flex flex-wrap items-start justify-between gap-3 rounded-xl border border-stone-200 bg-white px-4 py-3">
            <div>
                <h3 class="text-base font-bold text-stone-900">{{ $module->title }} <span class="ml-1 text-xs font-medium text-stone-400">{{ $mLessons->count() }} materi</span>
                    @unless($module->is_published)<span class="ml-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold align-middle">DRAFT</span>@endunless
                </h3>
                @if($module->description)<p class="text-xs text-stone-500 mt-0.5 max-w-2xl">{{ $module->description }}</p>@endif
            </div>
            @if($canManage)
                <div class="flex flex-wrap items-center gap-2 text-xs shrink-0">
                    <button class="inline-flex min-h-8 items-center rounded-lg border border-stone-200 px-3 font-semibold text-stone-600 hover:bg-stone-50"
                        onclick='openModule({{ json_encode($module->only(["id","title","description","sort_order","is_published"])) }})'>Edit Modul</button>
                    <form method="POST" action="{{ route('learning.modules.destroy', $module) }}" onsubmit="return confirm('Hapus modul ini? Materinya tidak ikut terhapus (jadi Tanpa Modul).')">
                        @csrf @method('DELETE')
                        <button class="inline-flex min-h-8 items-center rounded-lg border border-rose-200 px-3 font-semibold text-rose-700 hover:bg-rose-50">Hapus modul</button>
                    </form>
                </div>
            @endif
        </div>
        @if($mLessons->isEmpty())
            <p class="rounded-xl border border-dashed border-stone-300 bg-white px-4 py-5 text-center text-xs text-stone-500">Belum ada materi di modul ini.</p>
        @else
            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                @foreach($mLessons as $lesson)
                    @include('learning._card', ['lesson' => $lesson, 'canManage' => $canManage])
                @endforeach
            </div>
        @endif
    </section>
@endforeach

@if($ungrouped->isNotEmpty())
    <section class="mb-8">
        <h3 class="text-base font-bold text-stone-900 mb-3">Tanpa Modul</h3>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
            @foreach($ungrouped as $lesson)
                @include('learning._card', ['lesson' => $lesson, 'canManage' => $canManage])
            @endforeach
        </div>
    </section>
@endif

@if($canManage)
{{-- Module modal --}}
<div id="moduleModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-md p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="moduleModalTitle" class="text-sm font-bold text-stone-900">Tambah Modul</h3>
            <button onclick="toggleModal('moduleModal')" class="text-stone-400 hover:text-stone-700"> Tutup </button>
        </div>
        <form method="POST" id="moduleForm" action="{{ route('learning.modules.store') }}" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="moduleMethod" value="POST">
            <div><label class="block text-xs font-semibold mb-1">Judul Modul *</label><input name="title" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Deskripsi / Tujuan Modul</label><textarea name="description" rows="3" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea></div>
            <div><label class="block text-xs font-semibold mb-1">Urutan</label><input type="number" name="sort_order" value="0" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_published" id="modulePublished" value="1" checked class="accent-red-600"> Publikasikan</label>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('moduleModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>

{{-- Lesson modal --}}
<div id="lessonModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 id="lessonModalTitle" class="text-sm font-bold text-stone-900">Tambah Materi</h3>
            <button onclick="toggleModal('lessonModal')" class="text-stone-400 hover:text-stone-700"> Tutup </button>
        </div>
        <form method="POST" id="lessonForm" action="{{ route('learning.store') }}" enctype="multipart/form-data" class="space-y-3 text-sm">
            @csrf
            <input type="hidden" name="_method" id="lessonMethod" value="POST">
            <div>
                <label class="block text-xs font-semibold mb-1">Modul</label>
                <select name="module_id" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                    <option value="">— Tanpa Modul —</option>
                    @foreach($modules as $m)<option value="{{ $m->id }}">{{ $m->title }}</option>@endforeach
                </select>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Judul Materi *</label><input name="title" required class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <p class="text-[11px] text-stone-500 bg-stone-50 border border-stone-200 rounded-lg px-3 py-2">Isi <b>video</b>, <b>dokumen</b>, atau <b>keduanya</b> — minimal salah satu.</p>
            <div><label class="block text-xs font-semibold mb-1">Link YouTube <span class="text-stone-400 font-normal">(opsional)</span></label><input name="video_url" id="lessonVideoUrl" placeholder="https://www.youtube.com/watch?v=..." class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold mb-1">File Dokumen <span class="text-stone-400 font-normal">(PPT/Word/PDF, maks 50MB, opsional)</span></label>
                    <input type="file" name="document_file" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx" class="w-full text-xs">
                    <p id="docCurrent" class="text-[10px] text-stone-400 mt-1 hidden"></p>
                    <label id="docRemoveWrap" class="hidden items-center gap-1 text-[11px] text-rose-600 mt-1"><input type="checkbox" name="remove_document" value="1" class="accent-rose-600"> Hapus dokumen yang ada</label>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">Gambar Cover <span class="text-stone-400 font-normal">(opsional, untuk thumbnail)</span></label>
                    <input type="file" name="cover_image" accept="image/*" class="w-full text-xs">
                </div>
            </div>
            <div><label class="block text-xs font-semibold mb-1">Urutan</label><input type="number" name="sort_order" value="0" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></div>
            <div><label class="block text-xs font-semibold mb-1">Deskripsi</label><textarea name="description" rows="3" class="w-full px-3 py-2 border border-stone-300 rounded-lg"></textarea></div>
            <div>
                <label class="block text-xs font-semibold mb-1">Tujukan untuk role <span class="text-stone-400 font-normal">(kosongkan = semua)</span></label>
                <div class="flex flex-wrap gap-3 text-xs">
                    @foreach($audienceRoles as $r)
                        <label class="flex items-center gap-1"><input type="checkbox" name="audience[]" value="{{ $r->name }}" class="lesson-aud accent-red-600"> {{ $r->label }}</label>
                    @endforeach
                </div>
            </div>
            <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_published" id="lessonPublished" value="1" checked class="accent-red-600"> Publikasikan (tampil ke user)</label>
            <div class="flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('lessonModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
    function openModule(m) {
        const f = document.getElementById('moduleForm');
        if (!f) return;
        f.reset();
        if (m) {
            f.action = '/learning-modules/' + m.id;
            document.getElementById('moduleMethod').value = 'PUT';
            document.getElementById('moduleModalTitle').textContent = 'Edit Modul';
            f.querySelector('[name=title]').value = m.title ?? '';
            f.querySelector('[name=description]').value = m.description ?? '';
            f.querySelector('[name=sort_order]').value = m.sort_order ?? 0;
            document.getElementById('modulePublished').checked = !!m.is_published;
        } else {
            f.action = '{{ route('learning.modules.store') }}';
            document.getElementById('moduleMethod').value = 'POST';
            document.getElementById('moduleModalTitle').textContent = 'Tambah Modul';
            document.getElementById('modulePublished').checked = true;
        }
        toggleModal('moduleModal');
    }

    function openLesson(l) {
        const f = document.getElementById('lessonForm');
        if (!f) return;
        f.reset();
        f.querySelectorAll('.lesson-aud').forEach(c => c.checked = false);
        const docCurrent = document.getElementById('docCurrent');
        const docRemoveWrap = document.getElementById('docRemoveWrap');
        docCurrent.classList.add('hidden');
        docCurrent.textContent = '';
        docRemoveWrap.classList.add('hidden');
        docRemoveWrap.classList.remove('flex');
        if (l) {
            f.action = '/learning/' + l.id;
            document.getElementById('lessonMethod').value = 'PUT';
            document.getElementById('lessonModalTitle').textContent = 'Edit Materi';
            for (const k of ['title','video_url','sort_order','description']) {
                if (f.querySelector('[name='+k+']')) f.querySelector('[name='+k+']').value = l[k] ?? '';
            }
            f.querySelector('[name=module_id]').value = l.module_id ?? '';
            document.getElementById('lessonPublished').checked = !!l.is_published;
            (l.audience || []).forEach(role => {
                const cb = f.querySelector('.lesson-aud[value="'+role+'"]');
                if (cb) cb.checked = true;
            });
            if (l.doc_name) {
                docCurrent.textContent = 'File saat ini: ' + l.doc_name + ' (biarkan kosong jika tidak ingin mengganti)';
                docCurrent.classList.remove('hidden');
                docRemoveWrap.classList.remove('hidden');
                docRemoveWrap.classList.add('flex');
            }
        } else {
            f.action = '{{ route('learning.store') }}';
            document.getElementById('lessonMethod').value = 'POST';
            document.getElementById('lessonModalTitle').textContent = 'Tambah Materi';
            document.getElementById('lessonPublished').checked = true;
        }
        toggleModal('lessonModal');
    }
</script>
@endpush
