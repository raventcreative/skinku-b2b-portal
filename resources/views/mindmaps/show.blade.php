@extends('layouts.app')
@section('title', $map->title)
@section('heading', 'Mindmap')

@section('content')
@php
    $routes = [
        'state'   => route('mindmaps.state', $map),
        'nodes'   => route('mindmaps.nodes.store', $map),
        'node'    => url('/mindmaps/'.$map->id.'/nodes'),   // + /{id} untuk PATCH/DELETE
        'edges'   => route('mindmaps.edges.store', $map),
        'edge'    => url('/mindmaps/'.$map->id.'/edges'),   // + /{id}
        'index'   => route('mindmaps.index'),
    ];
    $colors = ['kuning' => '#fef9c3', 'hijau' => '#dcfce7', 'biru' => '#dbeafe', 'rose' => '#ffe4e6', 'stone' => '#f5f5f4', 'putih' => '#ffffff'];
@endphp

<div class="flex flex-col h-[calc(100vh-8rem)]">
    <div class="flex flex-wrap items-center gap-2 mb-2">
        <a href="{{ route('mindmaps.index') }}" class="text-xs text-stone-500 hover:text-red-600">← Semua papan</a>
        <span class="text-stone-300">·</span>
        <h3 class="text-sm font-bold text-stone-900">{{ $map->title }}</h3>
        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $canEdit ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ $canEdit ? 'bisa edit' : 'lihat saja' }}</span>

        @if($isOwner)
        <button type="button" onclick="document.getElementById('mmMembers').classList.toggle('hidden')"
            class="ml-auto px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-stone-50 font-semibold">Anggota ({{ $map->members->count() }})</button>
        @endif

        @if($canEdit)
        <div class="{{ $isOwner ? '' : 'ml-auto' }} flex items-center gap-1.5">
            <button id="mmAddSticky" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-stone-50 font-semibold">+ Sticky</button>
            <div class="flex items-center gap-1 px-2">
                @foreach($colors as $key => $hex)
                    <button class="mm-color w-5 h-5 rounded-full border border-stone-300" data-color="{{ $key }}" style="background: {{ $hex }}" title="{{ $key }}"></button>
                @endforeach
            </div>
            <button id="mmDelete" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-rose-50 text-rose-600 font-semibold">Hapus</button>
            <span class="text-stone-300">·</span>
        </div>
        @else
        <div class="ml-auto"></div>
        @endif
        <button id="mmZoomOut" class="w-7 h-7 bg-white border border-stone-300 rounded-lg text-sm">−</button>
        <button id="mmZoomFit" class="px-2 h-7 bg-white border border-stone-300 rounded-lg text-xs">fit</button>
        <button id="mmZoomIn" class="w-7 h-7 bg-white border border-stone-300 rounded-lg text-sm">+</button>
        <span id="mmRefreshChip" class="hidden px-2 py-1 text-[11px] bg-amber-100 text-amber-800 rounded-lg cursor-pointer">papan diperbarui — muat ulang</span>
    </div>

    @if($isOwner)
    <div id="mmMembers" class="hidden mb-2 p-3 bg-white border border-stone-200 rounded-2xl">
        <div class="flex flex-wrap items-start gap-6">
            <div class="min-w-56">
                <p class="text-[11px] font-bold text-stone-500 uppercase tracking-wide mb-1.5">Anggota papan</p>
                @forelse($map->members as $m)
                    <div class="flex items-center gap-2 py-0.5">
                        <span class="text-xs text-stone-700">{{ $m->user?->fullname ?? $m->user?->name ?? 'user #'.$m->user_id }}</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $m->can_edit ? 'bg-emerald-100 text-emerald-700' : 'bg-stone-100 text-stone-500' }}">{{ $m->can_edit ? 'edit' : 'lihat' }}</span>
                        <form method="POST" action="{{ route('mindmaps.members.destroy', [$map, $m->user_id]) }}" class="ml-auto">
                            @csrf @method('DELETE')
                            <button class="text-[11px] text-rose-500 hover:text-rose-700">keluarkan</button>
                        </form>
                    </div>
                @empty
                    <p class="text-xs text-stone-400">Belum ada anggota. Undang rekan tim di sebelah.</p>
                @endforelse
            </div>
            <div>
                <p class="text-[11px] font-bold text-stone-500 uppercase tracking-wide mb-1.5">Undang anggota</p>
                <form method="POST" action="{{ route('mindmaps.members.store', $map) }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <select name="user_id" required class="px-2 py-1.5 text-xs border border-stone-300 rounded-lg">
                        <option value="">Pilih staf…</option>
                        @foreach($staffOptions as $s)
                            @if($s->id !== $map->created_by)
                                <option value="{{ $s->id }}">{{ $s->fullname ?? $s->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    <label class="flex items-center gap-1 text-xs text-stone-600">
                        <input type="checkbox" name="can_edit" value="1" checked class="rounded border-stone-300"> boleh edit
                    </label>
                    <button class="px-3 py-1.5 text-xs bg-red-600 text-white rounded-lg hover:bg-red-700 font-semibold">Undang</button>
                </form>
            </div>
        </div>
    </div>
    @endif

    <div id="mmCanvas" class="relative flex-1 overflow-hidden bg-stone-100 rounded-2xl border border-stone-200 select-none" style="cursor: grab;">
        <div id="mmWorld" class="absolute top-0 left-0" style="transform-origin: 0 0;">
            <svg id="mmSvg" class="absolute top-0 left-0 overflow-visible" style="pointer-events: none;" width="1" height="1">
                <defs>
                    <marker id="mmArrow" markerWidth="10" markerHeight="10" refX="8" refY="3" orient="auto">
                        <path d="M0,0 L8,3 L0,6 Z" fill="#78716c"></path>
                    </marker>
                </defs>
                <g id="mmEdges"></g>
            </svg>
            <div id="mmNodes"></div>
        </div>
        <p id="mmHint" class="absolute bottom-3 left-3 text-[11px] text-stone-400">{{ $canEdit ? 'Double-click kanvas = sticky baru · seret latar = geser · scroll = zoom · tarik titik biru node = sambung' : 'Mode lihat saja' }}</p>
    </div>
</div>

<script>
(function () {
    var R = {{ \Illuminate\Support\Js::from($routes) }};
    var COLORS = {{ \Illuminate\Support\Js::from($colors) }};
    var CAN_EDIT = {{ $canEdit ? 'true' : 'false' }};

    var canvas = document.getElementById('mmCanvas'),
        world = document.getElementById('mmWorld'),
        nodesLayer = document.getElementById('mmNodes'),
        edgesLayer = document.getElementById('mmEdges'),
        svg = document.getElementById('mmSvg'),
        chip = document.getElementById('mmRefreshChip');

    var view = { x: 0, y: 0, k: 1 };
    var nodes = {}, edges = {}, selected = null, lastUpdated = null, dirty = false;

    function csrf() { return document.querySelector('meta[name="csrf-token"]').content; }
    function api(url, method, body) {
        return fetch(url, {
            method: method,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                       'X-CSRF-TOKEN': csrf(), 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
        }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); });
    }
    function applyView() { world.style.transform = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.k + ')'; }
    function toWorld(clientX, clientY) {
        var b = canvas.getBoundingClientRect();
        return { x: (clientX - b.left - view.x) / view.k, y: (clientY - b.top - view.y) / view.k };
    }

    // ---- render node ----
    function renderNode(n) {
        var el = document.getElementById('mmn-' + n.id);
        if (!el) {
            el = document.createElement('div');
            el.id = 'mmn-' + n.id;
            el.className = 'absolute rounded-xl border border-stone-300 shadow-sm p-2 text-xs text-stone-800 overflow-hidden';
            el.dataset.id = n.id;
            nodesLayer.appendChild(el);
            attachNode(el);
        }
        el.style.left = n.x + 'px'; el.style.top = n.y + 'px';
        el.style.width = n.width + 'px'; el.style.height = n.height + 'px';
        el.style.background = COLORS[n.color] || COLORS.kuning;
        if (document.activeElement !== el.querySelector('.mm-text')) {
            el.innerHTML = '';
            var t = document.createElement('div');
            t.className = 'mm-text w-full h-full outline-none whitespace-pre-wrap break-words';
            t.contentEditable = CAN_EDIT ? 'true' : 'false';
            t.textContent = n.text || '';
            el.appendChild(t);
            if (CAN_EDIT) {
                var h = document.createElement('div');
                h.className = 'mm-port absolute -right-1.5 top-1/2 -mt-1.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white cursor-crosshair';
                el.appendChild(h);
                attachPort(h, n.id);
            }
        }
    }
    function removeNodeEl(id) { var el = document.getElementById('mmn-' + id); if (el) el.remove(); }

    // ---- render edge ----
    function renderEdge(e) {
        var from = nodes[e.from_node_id], to = nodes[e.to_node_id];
        if (!from || !to) return;
        var g = document.getElementById('mme-' + e.id);
        if (!g) {
            g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.id = 'mme-' + e.id; g.dataset.id = e.id; g.style.pointerEvents = 'stroke';
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('fill', 'none'); path.setAttribute('stroke', '#78716c');
            path.setAttribute('stroke-width', '2'); path.setAttribute('marker-end', 'url(#mmArrow)');
            path.style.cursor = 'pointer';
            g.appendChild(path);
            edgesLayer.appendChild(g);
            if (CAN_EDIT) g.addEventListener('click', function () { editEdge(e.id); });
        }
        var x1 = from.x + from.width, y1 = from.y + from.height / 2;
        var x2 = to.x, y2 = to.y + to.height / 2;
        g.querySelector('path').setAttribute('d', 'M' + x1 + ',' + y1 + ' C' + (x1 + 60) + ',' + y1 + ' ' + (x2 - 60) + ',' + y2 + ' ' + x2 + ',' + y2);
    }
    function removeEdgeEl(id) { var g = document.getElementById('mme-' + id); if (g) g.remove(); }
    function redrawEdgesFor(nodeId) {
        Object.values(edges).forEach(function (e) { if (e.from_node_id == nodeId || e.to_node_id == nodeId) renderEdge(e); });
    }

    // ---- load & sync ----
    function load(initial) {
        api(R.state, 'GET').then(function (s) {
            if (!initial && s.updated_at === lastUpdated) return;
            if (!initial && dirty) { chip.classList.remove('hidden'); return; }
            lastUpdated = s.updated_at;
            nodesLayer.innerHTML = ''; edgesLayer.innerHTML = ''; nodes = {}; edges = {};
            s.nodes.forEach(function (n) { nodes[n.id] = n; renderNode(n); });
            s.edges.forEach(function (e) { edges[e.id] = e; renderEdge(e); });
        }).catch(function () {});
    }
    setInterval(function () { load(false); }, 10000);
    chip.addEventListener('click', function () { chip.classList.add('hidden'); dirty = false; load(true); });

    // ---- pan & zoom ----
    var panning = null;
    canvas.addEventListener('mousedown', function (ev) {
        if (ev.target === canvas || ev.target === world || ev.target === svg) {
            panning = { sx: ev.clientX, sy: ev.clientY, ox: view.x, oy: view.y };
            canvas.style.cursor = 'grabbing'; select(null);
        }
    });
    window.addEventListener('mousemove', function (ev) {
        if (panning) { view.x = panning.ox + (ev.clientX - panning.sx); view.y = panning.oy + (ev.clientY - panning.sy); applyView(); }
    });
    window.addEventListener('mouseup', function () { panning = null; canvas.style.cursor = 'grab'; });
    canvas.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        var w = toWorld(ev.clientX, ev.clientY);
        var factor = ev.deltaY < 0 ? 1.1 : 0.9;
        view.k = Math.min(3, Math.max(0.2, view.k * factor));
        var b = canvas.getBoundingClientRect();
        view.x = (ev.clientX - b.left) - w.x * view.k;
        view.y = (ev.clientY - b.top) - w.y * view.k;
        applyView();
    }, { passive: false });
    document.getElementById('mmZoomIn').onclick = function () { view.k = Math.min(3, view.k * 1.2); applyView(); };
    document.getElementById('mmZoomOut').onclick = function () { view.k = Math.max(0.2, view.k / 1.2); applyView(); };
    document.getElementById('mmZoomFit').onclick = function () { view = { x: 0, y: 0, k: 1 }; applyView(); };

    // ---- select ----
    function select(el) {
        if (selected) selected.classList.remove('ring-2', 'ring-red-500');
        selected = el;
        if (el) el.classList.add('ring-2', 'ring-red-500');
    }

    if (!CAN_EDIT) { applyView(); load(true); return; }

    // ---- create sticky (double-click) ----
    canvas.addEventListener('dblclick', function (ev) {
        if (ev.target !== canvas && ev.target !== world && ev.target !== svg) return;
        var p = toWorld(ev.clientX, ev.clientY);
        api(R.nodes, 'POST', { type: 'sticky', x: Math.round(p.x), y: Math.round(p.y), color: 'kuning', text: '' })
            .then(function (res) { nodes[res.node.id] = res.node; renderNode(res.node); markDirty(); });
    });
    document.getElementById('mmAddSticky').onclick = function () {
        var b = canvas.getBoundingClientRect();
        var p = toWorld(b.left + 200, b.top + 150);
        api(R.nodes, 'POST', { type: 'sticky', x: Math.round(p.x), y: Math.round(p.y), color: 'kuning', text: '' })
            .then(function (res) { nodes[res.node.id] = res.node; renderNode(res.node); markDirty(); });
    };

    function markDirty() { dirty = true; }

    // ---- node interactions (drag, edit, select) ----
    function attachNode(el) {
        var id = el.dataset.id, drag = null;
        el.addEventListener('mousedown', function (ev) {
            if (ev.target.classList.contains('mm-port')) return;         // sambung, bukan geser
            if (ev.target.classList.contains('mm-text') && document.activeElement === ev.target) return; // sedang edit
            ev.stopPropagation(); select(el);
            drag = { sx: ev.clientX, sy: ev.clientY, ox: nodes[id].x, oy: nodes[id].y };
        });
        window.addEventListener('mousemove', function (ev) {
            if (!drag) return;
            nodes[id].x = drag.ox + (ev.clientX - drag.sx) / view.k;
            nodes[id].y = drag.oy + (ev.clientY - drag.sy) / view.k;
            el.style.left = nodes[id].x + 'px'; el.style.top = nodes[id].y + 'px';
            redrawEdgesFor(id);
        });
        window.addEventListener('mouseup', function () {
            if (!drag) return; drag = null;
            api(R.node + '/' + id, 'PATCH', { x: Math.round(nodes[id].x), y: Math.round(nodes[id].y) }).then(markDirty);
        });
        el.addEventListener('blur', function (ev) {
            if (!ev.target.classList.contains('mm-text')) return;
            var txt = ev.target.textContent;
            if (txt !== nodes[id].text) { nodes[id].text = txt; api(R.node + '/' + id, 'PATCH', { text: txt }).then(markDirty); }
        }, true);
    }

    // ---- connect (drag from port to a node) ----
    var linking = null, tempPath = null;
    function attachPort(handle, fromId) {
        handle.addEventListener('mousedown', function (ev) {
            ev.stopPropagation();
            linking = { from: fromId };
            tempPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            tempPath.setAttribute('fill', 'none'); tempPath.setAttribute('stroke', '#3b82f6');
            tempPath.setAttribute('stroke-dasharray', '4'); tempPath.setAttribute('stroke-width', '2');
            edgesLayer.appendChild(tempPath);
        });
    }
    window.addEventListener('mousemove', function (ev) {
        if (!linking) return;
        var f = nodes[linking.from], p = toWorld(ev.clientX, ev.clientY);
        tempPath.setAttribute('d', 'M' + (f.x + f.width) + ',' + (f.y + f.height / 2) + ' L' + p.x + ',' + p.y);
    });
    window.addEventListener('mouseup', function (ev) {
        if (!linking) return;
        var target = ev.target.closest ? ev.target.closest('[data-id]') : null;
        var toId = target && target.parentNode === nodesLayer ? target.dataset.id : null;
        if (tempPath) { tempPath.remove(); tempPath = null; }
        if (toId && toId != linking.from) {
            api(R.edges, 'POST', { from_node_id: Number(linking.from), to_node_id: Number(toId) })
                .then(function (res) { edges[res.edge.id] = res.edge; renderEdge(res.edge); markDirty(); }).catch(function () {});
        }
        linking = null;
    });

    // ---- color / delete ----
    document.querySelectorAll('.mm-color').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!selected) return;
            var id = selected.dataset.id, c = btn.dataset.color;
            nodes[id].color = c; selected.style.background = COLORS[c];
            api(R.node + '/' + id, 'PATCH', { color: c }).then(markDirty);
        });
    });
    document.getElementById('mmDelete').onclick = function () {
        if (!selected) return;
        var id = selected.dataset.id;
        api(R.node + '/' + id, 'DELETE').then(function () {
            removeNodeEl(id); delete nodes[id];
            Object.values(edges).forEach(function (e) { if (e.from_node_id == id || e.to_node_id == id) { removeEdgeEl(e.id); delete edges[e.id]; } });
            select(null); markDirty();
        });
    };

    function editEdge(id) {
        var label = prompt('Label garis (kosongkan untuk hapus label):', edges[id].label || '');
        if (label === null) return;
        edges[id].label = label;
        api(R.edge + '/' + id, 'PATCH', { label: label }).then(markDirty);
    }

    applyView(); load(true);
})();
</script>
@endsection
