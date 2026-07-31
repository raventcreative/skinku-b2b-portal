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
            <button id="mmUndo" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-stone-50 font-semibold" title="Ctrl+Z">↶ Undo</button>
            <div class="flex items-center gap-1 px-2">
                @foreach($colors as $key => $hex)
                    <button class="mm-color w-5 h-5 rounded-full border border-stone-300" data-color="{{ $key }}" style="background: {{ $hex }}" title="{{ $key }}"></button>
                @endforeach
            </div>
            <button id="mmDelete" class="px-3 py-1.5 text-xs bg-white border border-stone-300 rounded-lg hover:bg-rose-50 text-rose-600 font-semibold" title="Delete">Hapus</button>
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
        <p id="mmHint" class="absolute bottom-3 left-3 text-[11px] text-stone-400">{{ $canEdit ? 'Dobel-klik/ketuk = sticky / ketik · seret = geser/pindah · scroll atau cubit = zoom · titik biru = sambung · Delete = hapus · Ctrl+Z = undo' : 'Mode lihat saja' }}</p>
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

    canvas.style.touchAction = 'none'; // gestur sentuh ditangani sendiri

    var view = { x: 0, y: 0, k: 1 };
    var nodes = {}, edges = {};
    var selected = null;               // { kind:'node'|'edge', id }
    var lastUpdated = null, dirty = false;
    var undoStack = [];

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
    function toWorld(cx, cy) {
        var b = canvas.getBoundingClientRect();
        return { x: (cx - b.left - view.x) / view.k, y: (cy - b.top - view.y) / view.k };
    }
    function markDirty() { dirty = true; }
    function pushUndo(fn) { undoStack.push(fn); if (undoStack.length > 60) undoStack.shift(); }
    function isTyping() {
        var a = document.activeElement;
        if (!a) return false;
        return a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable;
    }
    function onFocusedText(ev) {
        return ev.target && ev.target.classList && ev.target.classList.contains('mm-text') && document.activeElement === ev.target;
    }

    // ---------- render node ----------
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
        el.style.width = (n.width || 200) + 'px'; el.style.height = (n.height || 120) + 'px';
        el.style.background = COLORS[n.color] || COLORS.kuning;
        if (document.activeElement !== el.querySelector('.mm-text')) {
            el.innerHTML = '';
            var t = document.createElement('div');
            t.className = 'mm-text w-full h-full outline-none whitespace-pre-wrap break-words';
            t.style.touchAction = 'auto';
            t.contentEditable = CAN_EDIT ? 'true' : 'false';
            t.textContent = n.text || '';
            el.appendChild(t);
            if (CAN_EDIT) {
                var h = document.createElement('div');
                h.className = 'mm-port absolute -right-1.5 top-1/2 -mt-1.5 w-3 h-3 rounded-full bg-blue-500 border-2 border-white cursor-crosshair';
                el.appendChild(h);
            }
        }
        markNodeRing(el);
    }
    function markNodeRing(el) {
        var on = selected && selected.kind === 'node' && selected.id == el.dataset.id;
        el.classList.toggle('ring-2', !!on);
        el.classList.toggle('ring-red-500', !!on);
    }
    function removeNodeEl(id) { var el = document.getElementById('mmn-' + id); if (el) el.remove(); }

    // ---------- render edge ----------
    function renderEdge(e) {
        var from = nodes[e.from_node_id], to = nodes[e.to_node_id];
        if (!from || !to) return;
        var g = document.getElementById('mme-' + e.id);
        if (!g) {
            g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.id = 'mme-' + e.id; g.dataset.id = e.id; g.style.pointerEvents = 'stroke';
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('fill', 'none'); path.setAttribute('marker-end', 'url(#mmArrow)');
            path.style.cursor = 'pointer';
            g.appendChild(path);
            edgesLayer.appendChild(g);
            if (CAN_EDIT) g.addEventListener('dblclick', function (ev) { ev.stopPropagation(); editEdge(e.id); });
        }
        var x1 = from.x + from.width, y1 = from.y + from.height / 2, x2 = to.x, y2 = to.y + to.height / 2;
        var p = g.querySelector('path');
        p.setAttribute('d', 'M' + x1 + ',' + y1 + ' C' + (x1 + 60) + ',' + y1 + ' ' + (x2 - 60) + ',' + y2 + ' ' + x2 + ',' + y2);
        var on = selected && selected.kind === 'edge' && selected.id == e.id;
        p.setAttribute('stroke', on ? '#ef4444' : '#78716c');
        p.setAttribute('stroke-width', on ? '3' : '2');
    }
    function removeEdgeEl(id) { var g = document.getElementById('mme-' + id); if (g) g.remove(); }
    function redrawEdgesFor(nodeId) {
        Object.values(edges).forEach(function (e) { if (e.from_node_id == nodeId || e.to_node_id == nodeId) renderEdge(e); });
    }

    // ---------- selection ----------
    function select(item) {
        var prev = selected;
        selected = item;
        if (prev && prev.kind === 'node') { var pe = document.getElementById('mmn-' + prev.id); if (pe) markNodeRing(pe); }
        if (prev && prev.kind === 'edge') { var ped = edges[prev.id]; if (ped) renderEdge(ped); }
        if (item && item.kind === 'node') { var el = document.getElementById('mmn-' + item.id); if (el) markNodeRing(el); }
        if (item && item.kind === 'edge') { var ed = edges[item.id]; if (ed) renderEdge(ed); }
    }

    // ---------- load & sync ----------
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

    // ---------- zoom ----------
    function zoomTo(cx, cy, newK, worldPt) {
        newK = Math.min(3, Math.max(0.2, newK));
        var w = worldPt || toWorld(cx, cy);
        view.k = newK;
        var b = canvas.getBoundingClientRect();
        view.x = (cx - b.left) - w.x * view.k;
        view.y = (cy - b.top) - w.y * view.k;
        applyView();
    }
    canvas.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        zoomTo(ev.clientX, ev.clientY, view.k * (ev.deltaY < 0 ? 1.1 : 0.9));
    }, { passive: false });
    document.getElementById('mmZoomIn').onclick = function () { view.k = Math.min(3, view.k * 1.2); applyView(); };
    document.getElementById('mmZoomOut').onclick = function () { view.k = Math.max(0.2, view.k / 1.2); applyView(); };
    document.getElementById('mmZoomFit').onclick = function () { view = { x: 0, y: 0, k: 1 }; applyView(); };

    // ---------- apa yang ada di titik ----------
    function hit(target) {
        var port = target && target.closest ? target.closest('.mm-port') : null;
        var box = target && target.closest ? target.closest('[data-id]') : null;
        if (box && box.parentNode === nodesLayer) {
            return { kind: 'node', id: box.dataset.id, port: !!port,
                     typing: (target.classList && target.classList.contains('mm-text') && document.activeElement === target) };
        }
        if (box && box.parentNode === edgesLayer) return { kind: 'edge', id: box.dataset.id };
        return { kind: 'bg' };
    }

    // ---------- pointer (dipakai mouse & sentuh) ----------
    var mode = null, pan0, node0, link0, tempPath = null, moved = false;

    function pointerDown(cx, cy, target) {
        moved = false;
        if (!CAN_EDIT) { mode = 'pan'; pan0 = { sx: cx, sy: cy, ox: view.x, oy: view.y }; canvas.style.cursor = 'grabbing'; return; }
        var h = hit(target);
        if (h.kind === 'node') {
            if (h.typing) { mode = null; return; }           // biarkan kursor teks
            if (h.port) {                                     // mulai tarik garis
                mode = 'link'; link0 = { from: h.id };
                tempPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                tempPath.setAttribute('fill', 'none'); tempPath.setAttribute('stroke', '#3b82f6');
                tempPath.setAttribute('stroke-dasharray', '4'); tempPath.setAttribute('stroke-width', '2');
                edgesLayer.appendChild(tempPath);
                return;
            }
            select({ kind: 'node', id: h.id });
            mode = 'node'; node0 = { id: h.id, sx: cx, sy: cy, ox: nodes[h.id].x, oy: nodes[h.id].y };
            return;
        }
        if (h.kind === 'edge') { select({ kind: 'edge', id: h.id }); mode = null; return; }
        mode = 'pan'; pan0 = { sx: cx, sy: cy, ox: view.x, oy: view.y }; canvas.style.cursor = 'grabbing'; select(null);
    }
    function pointerMove(cx, cy) {
        if (!mode) return;
        moved = true;
        if (mode === 'pan') { view.x = pan0.ox + (cx - pan0.sx); view.y = pan0.oy + (cy - pan0.sy); applyView(); }
        else if (mode === 'node') {
            var id = node0.id;
            if (!nodes[id]) return;
            nodes[id].x = node0.ox + (cx - node0.sx) / view.k;
            nodes[id].y = node0.oy + (cy - node0.sy) / view.k;
            var el = document.getElementById('mmn-' + id);
            if (el) { el.style.left = nodes[id].x + 'px'; el.style.top = nodes[id].y + 'px'; }
            redrawEdgesFor(id);
        }
        else if (mode === 'link') {
            var f = nodes[link0.from]; if (!f) return;
            var p = toWorld(cx, cy);
            tempPath.setAttribute('d', 'M' + (f.x + f.width) + ',' + (f.y + f.height / 2) + ' L' + p.x + ',' + p.y);
        }
    }
    function pointerUp(cx, cy) {
        canvas.style.cursor = 'grab';
        if (mode === 'node' && moved && nodes[node0.id]) {
            var id = node0.id, ox = node0.ox, oy = node0.oy;
            api(R.node + '/' + id, 'PATCH', { x: Math.round(nodes[id].x), y: Math.round(nodes[id].y) }).then(markDirty);
            pushUndo(function () {
                if (!nodes[id]) return;
                nodes[id].x = ox; nodes[id].y = oy; renderNode(nodes[id]); redrawEdgesFor(id);
                return api(R.node + '/' + id, 'PATCH', { x: Math.round(ox), y: Math.round(oy) }).then(markDirty);
            });
        }
        if (mode === 'link') {
            if (tempPath) { tempPath.remove(); tempPath = null; }
            var relEl = document.elementFromPoint(cx, cy);
            var box = relEl && relEl.closest ? relEl.closest('[data-id]') : null;
            var toId = (box && box.parentNode === nodesLayer) ? box.dataset.id : null;
            if (toId && toId != link0.from) {
                api(R.edges, 'POST', { from_node_id: Number(link0.from), to_node_id: Number(toId) })
                    .then(function (res) { edges[res.edge.id] = res.edge; renderEdge(res.edge); markDirty(); pushUndo(function () { return rawDeleteEdge(res.edge.id); }); })
                    .catch(function () {});
            }
        }
        mode = null;
    }

    // mouse
    canvas.addEventListener('mousedown', function (ev) { if (ev.button === 0) pointerDown(ev.clientX, ev.clientY, ev.target); });
    window.addEventListener('mousemove', function (ev) { if (mode) pointerMove(ev.clientX, ev.clientY); });
    window.addEventListener('mouseup', function (ev) { if (mode) pointerUp(ev.clientX, ev.clientY); });

    // sentuh (mobile)
    var pinch = null, lastTap = 0, lastTapXY = null;
    function d2(a, b) { var dx = a.clientX - b.clientX, dy = a.clientY - b.clientY; return Math.sqrt(dx * dx + dy * dy); }
    canvas.addEventListener('touchstart', function (ev) {
        if (onFocusedText(ev)) return;                          // sedang edit teks: biarkan browser
        if (ev.touches.length === 2) {
            var t0 = ev.touches[0], t1 = ev.touches[1];
            var mx = (t0.clientX + t1.clientX) / 2, my = (t0.clientY + t1.clientY) / 2;
            pinch = { d: d2(t0, t1), k0: view.k, mx: mx, my: my, w: toWorld(mx, my) };
            mode = null; if (tempPath) { tempPath.remove(); tempPath = null; }
        } else if (ev.touches.length === 1) {
            pinch = null;
            var t = ev.touches[0];
            pointerDown(t.clientX, t.clientY, ev.target);
        }
        ev.preventDefault();
    }, { passive: false });
    canvas.addEventListener('touchmove', function (ev) {
        if (onFocusedText(ev)) return;
        if (pinch && ev.touches.length === 2) {
            var t0 = ev.touches[0], t1 = ev.touches[1];
            zoomTo(pinch.mx, pinch.my, pinch.k0 * (d2(t0, t1) / pinch.d), pinch.w);
        } else if (ev.touches.length === 1) {
            var t = ev.touches[0];
            pointerMove(t.clientX, t.clientY);
        }
        ev.preventDefault();
    }, { passive: false });
    canvas.addEventListener('touchend', function (ev) {
        if (pinch) { if (ev.touches.length === 0) pinch = null; return; }
        var ct = ev.changedTouches[0];
        var wasMove = moved;
        pointerUp(ct.clientX, ct.clientY);
        if (!wasMove) {   // deteksi ketuk-ganda (bukan geser)
            var now = Date.now();
            if (now - lastTap < 320 && lastTapXY && Math.abs(ct.clientX - lastTapXY.x) < 28 && Math.abs(ct.clientY - lastTapXY.y) < 28) {
                doubleTap(ct.clientX, ct.clientY); lastTap = 0;
            } else { lastTap = now; lastTapXY = { x: ct.clientX, y: ct.clientY }; }
        }
    }, { passive: false });
    function doubleTap(cx, cy) {
        if (!CAN_EDIT) return;
        var h = hit(document.elementFromPoint(cx, cy));
        if (h.kind === 'node') {
            var el = document.getElementById('mmn-' + h.id), t = el && el.querySelector('.mm-text');
            if (t) { t.focus(); var r = document.createRange(); r.selectNodeContents(t); r.collapse(false); var s = window.getSelection(); s.removeAllRanges(); s.addRange(r); }
        } else if (h.kind === 'bg') {
            var p = toWorld(cx, cy); createSticky(p.x, p.y);
        }
    }

    if (!CAN_EDIT) { applyView(); load(true); return; }

    // ---------- buat sticky ----------
    function createSticky(wx, wy) {
        return api(R.nodes, 'POST', { type: 'sticky', x: Math.round(wx), y: Math.round(wy), color: 'kuning', text: '' })
            .then(function (res) { var n = res.node; nodes[n.id] = n; renderNode(n); markDirty(); pushUndo(function () { return rawDeleteNode(n.id); }); return res.node; });
    }
    canvas.addEventListener('dblclick', function (ev) {
        if (hit(ev.target).kind !== 'bg') return;
        var p = toWorld(ev.clientX, ev.clientY); createSticky(p.x, p.y);
    });
    document.getElementById('mmAddSticky').onclick = function () {
        var b = canvas.getBoundingClientRect(); var p = toWorld(b.left + 200, b.top + 150); createSticky(p.x, p.y);
    };

    // ---------- node: dobel-klik = ketik, blur = simpan (dengan undo) ----------
    function attachNode(el) {
        var id = el.dataset.id;
        el.addEventListener('dblclick', function (ev) {
            ev.stopPropagation();
            var t = el.querySelector('.mm-text'); if (!t) return;
            t.focus(); var r = document.createRange(); r.selectNodeContents(t); r.collapse(false);
            var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
        });
        el.addEventListener('focus', function (ev) { if (ev.target.classList.contains('mm-text') && nodes[id]) el.dataset.textBefore = nodes[id].text || ''; }, true);
        el.addEventListener('blur', function (ev) {
            if (!ev.target.classList.contains('mm-text') || !nodes[id]) return;
            var txt = ev.target.textContent;
            if (txt !== nodes[id].text) {
                var before = el.dataset.textBefore != null ? el.dataset.textBefore : (nodes[id].text || '');
                nodes[id].text = txt;
                api(R.node + '/' + id, 'PATCH', { text: txt }).then(markDirty);
                pushUndo((function (b) { return function () { if (!nodes[id]) return; nodes[id].text = b; renderNode(nodes[id]); return api(R.node + '/' + id, 'PATCH', { text: b }).then(markDirty); }; })(before));
            }
        }, true);
    }

    // ---------- hapus (tombol, keyboard, undo) ----------
    function rawDeleteNode(id) {
        return api(R.node + '/' + id, 'DELETE').then(function () {
            removeNodeEl(id); delete nodes[id];
            Object.values(edges).forEach(function (e) { if (e.from_node_id == id || e.to_node_id == id) { removeEdgeEl(e.id); delete edges[e.id]; } });
            if (selected && selected.kind === 'node' && selected.id == id) selected = null;
            markDirty();
        });
    }
    function rawDeleteEdge(id) {
        return api(R.edge + '/' + id, 'DELETE').then(function () {
            removeEdgeEl(id); delete edges[id];
            if (selected && selected.kind === 'edge' && selected.id == id) selected = null;
            markDirty();
        });
    }
    function deleteNode(id) {
        var n = nodes[id]; if (!n) return;
        var related = Object.values(edges).filter(function (e) { return e.from_node_id == id || e.to_node_id == id; });
        rawDeleteNode(id).then(function () {
            pushUndo(function () {
                return api(R.nodes, 'POST', { type: n.type || 'sticky', x: Math.round(n.x), y: Math.round(n.y), color: n.color || 'kuning', text: n.text || '' })
                    .then(function (res) {
                        var nn = res.node; nodes[nn.id] = nn; renderNode(nn); markDirty();
                        related.forEach(function (e) {
                            var f = (e.from_node_id == id) ? nn.id : e.from_node_id;
                            var t = (e.to_node_id == id) ? nn.id : e.to_node_id;
                            if (!nodes[f] || !nodes[t]) return;
                            api(R.edges, 'POST', { from_node_id: Number(f), to_node_id: Number(t), label: e.label || null })
                                .then(function (er) { edges[er.edge.id] = er.edge; renderEdge(er.edge); }).catch(function () {});
                        });
                    });
            });
        });
    }
    function deleteEdge(id) {
        var e = edges[id]; if (!e) return;
        rawDeleteEdge(id).then(function () {
            pushUndo(function () {
                return api(R.edges, 'POST', { from_node_id: e.from_node_id, to_node_id: e.to_node_id, label: e.label || null })
                    .then(function (er) { edges[er.edge.id] = er.edge; renderEdge(er.edge); markDirty(); }).catch(function () {});
            });
        });
    }
    function deleteSelected() {
        if (!selected) return;
        if (selected.kind === 'node') deleteNode(selected.id); else deleteEdge(selected.id);
    }
    document.getElementById('mmDelete').onclick = deleteSelected;

    // ---------- warna ----------
    document.querySelectorAll('.mm-color').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!selected || selected.kind !== 'node' || !nodes[selected.id]) return;
            var id = selected.id, c = btn.dataset.color, before = nodes[id].color;
            nodes[id].color = c; var el = document.getElementById('mmn-' + id); if (el) el.style.background = COLORS[c];
            api(R.node + '/' + id, 'PATCH', { color: c }).then(markDirty);
            pushUndo((function (b) { return function () { if (!nodes[id]) return; nodes[id].color = b; var e = document.getElementById('mmn-' + id); if (e) e.style.background = COLORS[b] || COLORS.kuning; return api(R.node + '/' + id, 'PATCH', { color: b }).then(markDirty); }; })(before));
        });
    });

    // ---------- label garis ----------
    function editEdge(id) {
        if (!edges[id]) return;
        var label = prompt('Label garis (kosongkan untuk hapus label):', edges[id].label || '');
        if (label === null) return;
        var before = edges[id].label || '';
        edges[id].label = label;
        api(R.edge + '/' + id, 'PATCH', { label: label }).then(markDirty);
        pushUndo((function (b) { return function () { if (!edges[id]) return; edges[id].label = b; return api(R.edge + '/' + id, 'PATCH', { label: b }).then(markDirty); }; })(before));
    }

    // ---------- undo ----------
    function doUndo() { if (undoStack.length) { var fn = undoStack.pop(); try { Promise.resolve(fn()).catch(function () {}); } catch (e) {} } }
    document.getElementById('mmUndo').onclick = doUndo;

    // ---------- keyboard ----------
    document.addEventListener('keydown', function (ev) {
        if (isTyping()) return;
        if ((ev.ctrlKey || ev.metaKey) && (ev.key === 'z' || ev.key === 'Z')) { ev.preventDefault(); doUndo(); return; }
        if (ev.key === 'Delete' || ev.key === 'Backspace') { if (selected) { ev.preventDefault(); deleteSelected(); } }
    });

    applyView(); load(true);
})();
</script>
@endsection
