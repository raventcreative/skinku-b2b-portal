@extends('layouts.app')
@section('title', 'Struktur Jaringan')
@section('heading', 'Struktur Jaringan Mitra')

@php
$allowedParents = collect(\App\Support\PartnerHierarchy::TIERS)->keys()
    ->mapWithKeys(fn ($role) => [$role => \App\Support\PartnerHierarchy::allowedParentRoles($role)])->all();
$tierOptions = collect(\App\Support\PartnerHierarchy::TIERS)
    ->map(fn ($meta, $role) => ['role' => $role, 'label' => $meta['label']])->values()->all();
@endphp

@section('content')
<div class="space-y-3">
    <div class="flex items-center justify-between flex-wrap gap-2">
        <p class="text-xs text-stone-500">💡 <b>Seret</b> node/chip ke node lain = jadikan <b>upline</b> · seret ke <b>"Belum ditempatkan"</b> = lepas · tombol <b>⋯</b> = ubah tier · seret area kosong = geser · scroll = zoom.</p>
        <div class="flex items-center gap-1">
            <button id="sjZoomOut" class="w-7 h-7 rounded border border-stone-300 bg-white hover:bg-stone-100 text-sm">−</button>
            <button id="sjFit" class="px-2 h-7 rounded border border-stone-300 bg-white hover:bg-stone-100 text-xs">fit</button>
            <button id="sjZoomIn" class="w-7 h-7 rounded border border-stone-300 bg-white hover:bg-stone-100 text-sm">+</button>
        </div>
    </div>

    {{-- Kolam: mitra belum ditempatkan --}}
    <div id="sjPoolWrap" class="bg-white rounded-xl border border-stone-200 p-3" data-drop-pool="1">
        <div class="text-[11px] font-semibold text-stone-600 mb-1">Belum ditempatkan <span id="sjPoolCount"></span></div>
        <div id="sjPool" class="flex flex-wrap"></div>
    </div>

    {{-- Kanvas --}}
    <div id="sjCanvas" class="relative overflow-hidden rounded-xl border border-stone-200 bg-stone-50"
         style="height:70vh; cursor:grab; touch-action:none;">
        <div id="sjWorld" style="position:absolute; left:0; top:0; transform-origin:0 0;">
            <svg id="sjEdges" style="position:absolute; left:0; top:0; overflow:visible; pointer-events:none;"></svg>
        </div>
        <div id="sjEmpty" class="absolute inset-0 flex items-center justify-center text-center text-sm text-stone-400 p-6 pointer-events-none" style="display:none;">
            <div>Belum ada <b>Grand Distributor</b> — kanvas butuh minimal 1 sebagai <b>pusat matahari</b>.<br>Klik tombol <b>⋯</b> pada mitra di panel atas → <b>Ubah Tier → Grand Distributor</b>, lalu seret distributor lain ke dia.</div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const CSRF = '{{ csrf_token() }}';
    const BASE = "{{ url('struktur-jaringan') }}";
    const ALLOWED_PARENTS = {!! json_encode($allowedParents) !!};
    const TIERS = {!! json_encode($tierOptions) !!};
    const PARTNERS = {!! json_encode($partners) !!};

    const canvas = document.getElementById('sjCanvas');
    const world = document.getElementById('sjWorld');
    const svg = document.getElementById('sjEdges');
    const pool = document.getElementById('sjPool');
    const NODE_W = 190, NODE_H = 66, H_GAP = 34, V_GAP = 108;

    function esc(s) { return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    // ---- bangun pohon + kolam ----
    const byId = {};
    PARTNERS.forEach(function (p) { byId[p.id] = Object.assign({}, p, { children: [] }); });
    const roots = [], unplaced = [];
    PARTNERS.forEach(function (p) {
        const node = byId[p.id];
        if (p.role === 'grand_distributor') roots.push(node);              // grand = akar
        else if (p.upline_id && byId[p.upline_id]) byId[p.upline_id].children.push(node);
        else unplaced.push(node);                                          // non-grand tanpa upline
    });

    // ---- auto-layout: banyak matahari (tiap Grand = pusat, downline melingkar) ----
    // RING > lebar node biar anak tak numpuk induk; radius tiap ring auto-melebar
    // sesuai jumlah node di ring itu (anti-numpuk walau downline banyak).
    const RING = 240, NODE_SPACE = NODE_W + 45;
    function leaves(n) { return n._leaves = (n.children.length ? n.children.reduce(function (s, c) { return s + leaves(c); }, 0) : 1); }
    function angles(n, a0, a1) {
        n._ang = (a0 + a1) / 2;
        let a = a0;
        n.children.forEach(function (c) { const span = (a1 - a0) * (c._leaves / n._leaves); angles(c, a, a + span); a += span; });
    }
    let offsetX = 0;
    roots.forEach(function (r) {
        leaves(r);
        angles(r, -Math.PI / 2, Math.PI * 1.5); // penuh 360°, mulai dari atas
        const perDepth = {};
        (function d(n, depth) { perDepth[depth] = (perDepth[depth] || 0) + 1; n._depth = depth; n.children.forEach(function (c) { d(c, depth + 1); }); })(r, 0);
        const radAt = {}; let prev = 0;
        Object.keys(perDepth).map(Number).sort(function (a, b) { return a - b; }).forEach(function (depth) {
            if (depth === 0) { radAt[0] = 0; return; }
            radAt[depth] = prev = Math.max(depth * RING, (perDepth[depth] * NODE_SPACE) / (2 * Math.PI), prev + RING);
        });
        let maxR = 0;
        (function pos(n) { const rad = radAt[n._depth]; n._lx = rad * Math.cos(n._ang); n._ly = rad * Math.sin(n._ang); if (rad > maxR) maxR = rad; n.children.forEach(pos); })(r);
        const cx = offsetX + maxR + NODE_W, cy = maxR + NODE_H;
        (function place(n) { n.x = cx + n._lx - NODE_W / 2; n.y = cy + n._ly - NODE_H / 2; n.children.forEach(place); })(r);
        offsetX = cx + maxR + NODE_W + 120;
    });

    // ---- render node + garis + kolam ----
    function nodeHtml(n) {
        return '<div class="flex items-center justify-between gap-1">'
            + '<span class="font-semibold text-stone-800 truncate">' + esc(n.name) + '</span>'
            + '<button data-tier-btn="' + n.id + '" class="shrink-0 text-stone-400 hover:text-stone-800 px-1 leading-none" title="Ubah tier">⋯</button>'
            + '</div>'
            + '<div class="text-[10px] text-stone-500 font-mono">' + esc(n.member_id || '—') + '</div>'
            + '<div class="mt-0.5 flex items-center gap-1 flex-wrap">'
            + '<span class="text-[9px] px-1 rounded bg-emerald-100 text-emerald-800">' + esc(n.tier) + '</span>'
            + (n.region ? '<span class="text-[9px] text-stone-400">' + esc(n.region) + '</span>' : '')
            + '<span class="text-[9px] px-1 rounded ' + (n.stockist ? 'bg-amber-100 text-amber-800' : 'bg-stone-100 text-stone-500') + '">' + (n.stockist ? 'stockist' : 'non-stok') + '</span>'
            + '</div>';
    }

    function render() {
        Array.prototype.slice.call(world.querySelectorAll('[data-node]')).forEach(function (n) { n.remove(); });
        let maxX = 0, maxY = 0, edges = '';
        Object.keys(byId).forEach(function (id) {
            const n = byId[id];
            if (n.x == null) return; // node kolam, tak digambar di kanvas
            const el = document.createElement('div');
            el.dataset.node = n.id; el.dataset.dropNode = n.id; el.dataset.dropRole = n.role;
            el.dataset.dragUser = n.id; el.dataset.dragRole = n.role;
            el.setAttribute('draggable', 'true');
            el.className = 'absolute rounded-lg border border-stone-200 bg-white shadow-sm px-2 py-1 text-xs cursor-move';
            el.style.left = n.x + 'px'; el.style.top = n.y + 'px'; el.style.width = NODE_W + 'px';
            el.innerHTML = nodeHtml(n);
            world.appendChild(el);
            maxX = Math.max(maxX, n.x + NODE_W); maxY = Math.max(maxY, n.y + NODE_H);
            n.children.forEach(function (c) {
                const x1 = n.x + NODE_W / 2, y1 = n.y + NODE_H / 2, x2 = c.x + NODE_W / 2, y2 = c.y + NODE_H / 2;
                edges += '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 + '" stroke="#d6d3d1" stroke-width="1.5"/>';
            });
        });
        svg.setAttribute('width', maxX + 40); svg.setAttribute('height', maxY + 40);
        svg.innerHTML = edges;

        document.getElementById('sjPoolCount').textContent = '(' + unplaced.length + ')';
        pool.innerHTML = unplaced.length ? unplaced.map(function (n) {
            return '<span class="inline-flex items-center gap-1 rounded border border-dashed border-stone-300 px-2 py-1 text-xs mr-1 mb-1">'
                + '<span draggable="true" data-drag-user="' + n.id + '" data-drag-role="' + n.role + '" class="cursor-move">' + esc(n.name) + ' <span class="text-stone-400">' + esc(n.tier) + '</span></span>'
                + '<button data-tier-btn="' + n.id + '" class="text-stone-400 hover:text-stone-800 leading-none" title="Ubah tier">⋯</button>'
                + '</span>';
        }).join('') : '<span class="text-xs text-stone-400">Semua mitra sudah ditempatkan.</span>';
        document.getElementById('sjEmpty').style.display = roots.length ? 'none' : 'flex';
    }
    render();

    // ---- pan / zoom ----
    let view = { x: 24, y: 20, k: 1 };
    function applyView() { world.style.transform = 'translate(' + view.x + 'px,' + view.y + 'px) scale(' + view.k + ')'; }
    applyView();
    canvas.addEventListener('wheel', function (e) {
        e.preventDefault();
        const r = canvas.getBoundingClientRect(), mx = e.clientX - r.left, my = e.clientY - r.top;
        const nk = Math.min(2, Math.max(0.3, view.k * (e.deltaY < 0 ? 1.1 : 0.9)));
        view.x = mx - (mx - view.x) * (nk / view.k); view.y = my - (my - view.y) * (nk / view.k); view.k = nk;
        applyView();
    }, { passive: false });
    let pan = null;
    canvas.addEventListener('pointerdown', function (e) {
        if (e.target.closest('[data-node]') || e.target.closest('[data-drag-user]') || e.target.closest('[data-tier-btn]')) return;
        pan = { sx: e.clientX, sy: e.clientY, ox: view.x, oy: view.y };
        canvas.setPointerCapture(e.pointerId); canvas.style.cursor = 'grabbing';
    });
    canvas.addEventListener('pointermove', function (e) {
        if (!pan) return; view.x = pan.ox + (e.clientX - pan.sx); view.y = pan.oy + (e.clientY - pan.sy); applyView();
    });
    function endPan() { pan = null; canvas.style.cursor = 'grab'; }
    canvas.addEventListener('pointerup', endPan);
    canvas.addEventListener('pointercancel', endPan);
    document.getElementById('sjZoomIn').onclick = function () { view.k = Math.min(2, view.k * 1.2); applyView(); };
    document.getElementById('sjZoomOut').onclick = function () { view.k = Math.max(0.3, view.k / 1.2); applyView(); };
    document.getElementById('sjFit').onclick = function () { view = { x: 24, y: 20, k: 1 }; applyView(); };

    // ---- drag & drop reparent (HTML5) ----
    let dragged = null;
    function canDropOn(role) { return dragged && (ALLOWED_PARENTS[dragged.role] || []).indexOf(role) !== -1; }
    function clearRings() { Array.prototype.slice.call(document.querySelectorAll('.ring-2')).forEach(function (el) { el.classList.remove('ring-2', 'ring-emerald-400'); }); }
    document.addEventListener('dragstart', function (e) {
        const el = e.target.closest('[data-drag-user]');
        if (!el) return; dragged = { id: el.dataset.dragUser, role: el.dataset.dragRole }; e.dataTransfer.effectAllowed = 'move';
    });
    document.addEventListener('dragend', function () { clearRings(); dragged = null; });
    document.addEventListener('dragover', function (e) {
        if (!dragged) return;
        const node = e.target.closest('[data-drop-node]'), zone = e.target.closest('[data-drop-pool]');
        if (node && node.dataset.dropNode !== dragged.id && canDropOn(node.dataset.dropRole)) { e.preventDefault(); node.classList.add('ring-2', 'ring-emerald-400'); }
        else if (zone) { e.preventDefault(); zone.classList.add('ring-2', 'ring-emerald-400'); }
    });
    document.addEventListener('dragleave', function (e) {
        const t = e.target.closest('[data-drop-node],[data-drop-pool]'); if (t) t.classList.remove('ring-2', 'ring-emerald-400');
    });
    document.addEventListener('drop', function (e) {
        if (!dragged) return;
        const node = e.target.closest('[data-drop-node]'), zone = e.target.closest('[data-drop-pool]');
        if (node && node.dataset.dropNode !== dragged.id && canDropOn(node.dataset.dropRole)) { e.preventDefault(); post(BASE + '/' + dragged.id + '/place', { upline_id: node.dataset.dropNode }); }
        else if (zone) { e.preventDefault(); post(BASE + '/' + dragged.id + '/place', { upline_id: null }); }
    });

    // ---- menu Ubah Tier ----
    let menu = null;
    function closeMenu() { if (menu) { menu.remove(); menu = null; } }
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-tier-btn]');
        if (!btn) { closeMenu(); return; }
        e.stopPropagation(); closeMenu();
        const id = btn.dataset.tierBtn, node = byId[id];
        menu = document.createElement('div');
        menu.className = 'fixed z-50 bg-white border border-stone-200 rounded-lg shadow-lg text-xs py-1';
        menu.style.left = e.clientX + 'px'; menu.style.top = e.clientY + 'px';
        menu.innerHTML = '<div class="px-3 py-1 text-[10px] text-stone-400">Ubah tier</div>'
            + TIERS.map(function (t) { return '<button data-set="' + t.role + '" class="block w-full text-left px-3 py-1 hover:bg-stone-100 ' + (t.role === node.role ? 'font-bold text-emerald-700' : '') + '">' + esc(t.label) + '</button>'; }).join('');
        document.body.appendChild(menu);
        Array.prototype.slice.call(menu.querySelectorAll('[data-set]')).forEach(function (b) {
            b.onclick = function () { const role = b.dataset.set; closeMenu(); if (role !== node.role) post(BASE + '/' + id + '/tier', { role: role }); };
        });
    });
    window.addEventListener('resize', closeMenu);

    // ---- POST helper ----
    function post(url, body) {
        fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) { if (res.ok && res.j.ok) location.reload(); else alert((res.j && res.j.error) || 'Gagal.'); })
            .catch(function () { alert('Gagal (jaringan).'); });
    }
})();
</script>
@endpush
