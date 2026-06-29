<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden flex flex-col">
    <a href="{{ route('learning.show', $lesson) }}" class="block relative group">
        <div class="aspect-video bg-stone-100 overflow-hidden">
            @if($lesson->thumbnailUrl())
                <img src="{{ $lesson->thumbnailUrl() }}" class="w-full h-full object-cover group-hover:scale-105 transition" alt="{{ $lesson->title }}">
            @endif
        </div>
        <span class="absolute inset-0 flex items-center justify-center">
            <span class="w-12 h-12 rounded-full bg-red-600/90 text-white flex items-center justify-center text-xl shadow-lg">▶</span>
        </span>
        @unless($lesson->is_published)<span class="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-amber-500 text-white text-[10px] font-bold">DRAFT</span>@endunless
    </a>
    <div class="p-4 flex-1 flex flex-col">
        <a href="{{ route('learning.show', $lesson) }}" class="font-bold text-stone-800 text-sm hover:text-red-600 line-clamp-2">{{ $lesson->title }}</a>
        @if($lesson->description)<p class="text-xs text-stone-500 mt-1 line-clamp-2">{{ $lesson->description }}</p>@endif
        @if($canManage)
            <div class="mt-3 pt-3 border-t border-stone-100 flex items-center gap-3 text-xs">
                <button class="text-stone-500 hover:text-stone-900 font-semibold"
                    onclick='openLesson({{ json_encode($lesson->only(["id","module_id","title","description","video_url","sort_order","is_published"]) + ["audience" => $lesson->audience ?? []]) }})'>Edit</button>
                <form method="POST" action="{{ route('learning.destroy', $lesson) }}" onsubmit="return confirm('Hapus materi ini?')">
                    @csrf @method('DELETE')
                    <button class="text-rose-600 hover:text-rose-800 font-semibold">Hapus</button>
                </form>
                @if($lesson->audience)<span class="text-[10px] text-stone-400 ml-auto">utk: {{ implode(', ', $lesson->audience) }}</span>@endif
            </div>
        @endif
    </div>
</div>
