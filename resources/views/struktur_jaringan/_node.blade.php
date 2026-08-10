<li class="mt-1">
    <div class="inline-flex items-center gap-2 rounded border border-stone-200 bg-white px-2 py-1 text-sm">
        <span class="font-medium text-stone-800">{{ $node->fullname }}</span>
        <span class="text-xs text-stone-500 font-mono">{{ $node->member_id ?? '—' }}</span>
        <span class="text-xs px-1.5 rounded bg-emerald-100 text-emerald-800">{{ \App\Support\PartnerHierarchy::label($node->role) }}</span>
        @if($node->region)<span class="text-xs text-stone-400">{{ $node->region }}</span>@endif
        <span class="text-xs text-stone-400">{{ $node->downlines->count() }} downline</span>
        <span class="text-xs px-1.5 rounded {{ \App\Support\PartnerHierarchy::holdsStock($node->role) ? 'bg-amber-100 text-amber-800' : 'bg-stone-100 text-stone-500' }}">
            {{ \App\Support\PartnerHierarchy::holdsStock($node->role) ? 'stockist' : 'non-stok' }}
        </span>
    </div>
    @if($node->downlines->isNotEmpty())
        <ul class="ml-6 border-l border-stone-200 pl-3">
            @foreach($node->downlines as $child)
                @include('struktur_jaringan._node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
