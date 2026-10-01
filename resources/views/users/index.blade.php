@extends('layouts.app')
@section('title', 'Kelola Anggota')
@section('heading', 'Kelola Mitra & Tim')

@section('content')
@php
    $isSuper = auth()->user()->isSuperAdmin();
    // Header = tautan sort (pola sama dengan Database KOL). Klik pertama asc,
    // klik lagi balik arah. Kolom divalidasi whitelist di controller.
    $sortLink = function (string $col, string $label) use ($sort, $dir, $filters) {
        $nextDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        $arrow = $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $url = route('users.index', array_merge(array_filter($filters), ['sort' => $col, 'dir' => $nextDir]));

        return '<a href="'.$url.'" class="hover:text-stone-800 '.($sort === $col ? 'text-stone-800 font-bold' : '').'">'.e($label).$arrow.'</a>';
    };
@endphp

<div class="flex justify-between items-center mb-4">
    <form method="GET" class="flex flex-wrap gap-2">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="dir" value="{{ $dir }}">
        <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama/username/email…"
               class="px-3 py-2 text-sm border border-stone-300 rounded-lg w-64">
        <select name="role" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua Role</option>
            @foreach($roles as $r)<option value="{{ $r->name }}" @selected(($filters['role'] ?? '')===$r->name)>{{ $r->label }}</option>@endforeach
        </select>
        <select name="status" class="px-3 py-2 text-sm border border-stone-300 rounded-lg">
            <option value="">Semua Status</option>
            @foreach(['active','inactive','deleted'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '')===$s)>{{ $s }}</option>@endforeach
        </select>
        <button class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-stone-100 border border-stone-200 rounded-lg hover:bg-stone-200 focus:outline-none focus:ring-2 focus:ring-stone-400" aria-label="Terapkan filter">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.3-4.3M19 10.5a8.5 8.5 0 1 1-17 0 8.5 8.5 0 0 1 17 0Z"/></svg>Filter
        </button>
    </form>
    <div class="flex gap-2">
        @if(auth()->user()->canDo('manage_users'))
            <a href="{{ route('onboarding.create') }}" class="inline-flex items-center gap-2 px-4 py-2 text-sm bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-semibold focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">
                <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>Onboarding via Paket Join
            </a>
        @endif
        <button onclick="openCreateUser()" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m6-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm10-3v6m-3-3h6"/></svg>Tambah User
        </button>
    </div>
</div>

<div class="bg-white rounded-2xl border border-stone-200 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full text-xs whitespace-nowrap">
        <thead class="bg-stone-50 text-stone-500 uppercase text-[10px]">
            <tr>
                <th class="text-left px-4 py-3">{!! $sortLink('fullname', 'Nama') !!}</th>
                <th class="text-left">{!! $sortLink('username', 'Username') !!}</th>
                <th class="text-left">{!! $sortLink('email', 'Email') !!}</th>
                <th class="text-left">{!! $sortLink('role', 'Role') !!}</th>
                <th class="text-left">{!! $sortLink('member_id', 'Member ID') !!}</th>
                <th class="text-left">{!! $sortLink('company_name', 'Perusahaan') !!}</th>
                <th class="text-left">{!! $sortLink('status', 'Status') !!}</th>
                <th class="text-right px-4">Aksi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $row)
                <tr class="border-t border-stone-100 hover:bg-stone-50">
                    <td class="px-4 py-3 font-semibold text-stone-800">{{ $row->fullname ?? $row->name }}</td>
                    <td class="text-stone-600">{{ $row->username }}</td>
                    <td class="text-stone-600">{{ $row->email }}</td>
                    <td><span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full bg-stone-100 text-stone-800 text-xs font-semibold">{{ $row->role }}</span></td>
                    <td class="text-stone-600 font-mono">{{ $row->member_id ?? '—' }}</td>
                    <td class="text-stone-600">{{ $row->company_name ?? '-' }}</td>
                    <td>
                        <span class="inline-flex min-h-6 items-center px-2.5 py-1 rounded-full text-xs leading-none font-semibold
                            {{ $row->status === 'active' ? 'bg-emerald-100 text-emerald-800' : ($row->status === 'deleted' ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                            {{ $row->status }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        @if($row->status !== 'deleted')
                            <button type="button" aria-label="Edit {{ $row->fullname }}" title="Edit anggota" class="inline-flex items-center justify-center w-9 h-9 text-stone-600 bg-white border border-stone-200 rounded-lg hover:text-red-700 hover:border-red-200 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500"
                                onclick='openEditUser({{ json_encode($row->only(["id","fullname","email","username","role","company_name","phone","address","region","city","status","upline_id","member_id"])) }})'><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m15.5 5.5 3 3M4 20l4.5-.9L19.3 8.3a2.1 2.1 0 0 0-3-3L5.5 16.1 4 20Z"/></svg></button>
                            <form method="POST" action="{{ route('users.toggle-status', $row) }}" class="inline ml-1">
                                @csrf
                                <button aria-label="{{ $row->status === 'active' ? 'Nonaktifkan' : 'Aktifkan' }} {{ $row->fullname }}" title="{{ $row->status === 'active' ? 'Nonaktifkan anggota' : 'Aktifkan anggota' }}" class="inline-flex items-center justify-center w-9 h-9 text-amber-700 bg-white border border-stone-200 rounded-lg hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v9m6.4-6.4a9 9 0 1 1-12.8 0"/></svg></button>
                            </form>
                            <button type="button" aria-label="Reset password {{ $row->fullname }}" title="Reset password" class="inline-flex items-center justify-center w-9 h-9 ml-1 text-blue-700 bg-white border border-stone-200 rounded-lg hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                onclick='openResetPw({{ $row->id }}, {{ json_encode($row->fullname) }})'><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 2.6-6.4L3 8m0-5v5h5m4-1v5l3 2"/></svg></button>
                            {{-- Syaratnya sengaja dicerminkan di ImpersonationService juga:
                                 menyembunyikan tombol bukan pengamanan, rutenya tetap bisa
                                 dipanggil langsung. --}}
                            @if($isSuper && !$row->isSuperAdmin() && $row->id !== auth()->id() && $row->status === 'active')
                                <form method="POST" action="{{ route('users.impersonate', $row) }}" class="inline"
                                    onsubmit="return confirm('Masuk sebagai {{ $row->fullname }}?\n\nSemua tindakan Anda akan tercatat atas nama mereka. Tercatat di Audit Log.')">
                                    @csrf
                                    <button aria-label="Masuk sebagai {{ $row->fullname }}" title="Masuk sebagai anggota" class="inline-flex items-center justify-center w-9 h-9 ml-1 text-indigo-700 bg-white border border-stone-200 rounded-lg hover:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-indigo-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15 3h6v6m-11 5L21 3M19 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h6"/></svg></button>
                                </form>
                            @endif
                            @if($isSuper && !$row->isSuperAdmin() && $row->id !== auth()->id())
                                <form method="POST" action="{{ route('users.destroy', $row) }}" class="inline" onsubmit="return confirm('Hapus user ini (soft delete)?')">
                                    @csrf @method('DELETE')
                                    <button aria-label="Hapus {{ $row->fullname }}" title="Hapus anggota" class="inline-flex items-center justify-center w-9 h-9 ml-1 text-rose-600 bg-white border border-stone-200 rounded-lg hover:border-rose-200 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
                                </form>
                            @endif
                            @if($row->activeJoinTransaction)
                                <form method="POST" action="{{ route('join-transactions.cancel', $row->activeJoinTransaction) }}" class="inline"
                                    onsubmit="return confirm('Batalkan join {{ $row->fullname }}?\n\nBonus join ke perekrut akan ditarik & stok paket dikembalikan ke HQ. Tercatat di Audit Log.')">
                                    @csrf
                                    <button aria-label="Batalkan join {{ $row->fullname }}" title="Batalkan join" class="inline-flex items-center justify-center w-9 h-9 ml-1 text-orange-700 bg-white border border-stone-200 rounded-lg hover:bg-orange-50 focus:outline-none focus:ring-2 focus:ring-orange-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="m7 7 10 10M17 7 7 17M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg></button>
                                </form>
                            @endif
                        @else
                            @if($isSuper)
                                <form method="POST" action="{{ route('users.restore', $row) }}" class="inline"
                                    onsubmit="return confirm('Pulihkan {{ $row->fullname }}? Akun akan aktif & bisa login lagi.')">
                                    @csrf
                                    <button aria-label="Pulihkan {{ $row->fullname }}" title="Pulihkan anggota" class="inline-flex items-center justify-center w-9 h-9 text-emerald-700 bg-white border border-stone-200 rounded-lg hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 2.6-6.4L3 8m0-5v5h5m-1 4a3 3 0 1 0 3-3"/></svg></button>
                                </form>
                                <form method="POST" action="{{ route('users.force-destroy', $row) }}" class="inline"
                                    onsubmit="return confirm('HAPUS PERMANEN {{ $row->fullname }}? Data akun hilang selamanya & tidak bisa dipulihkan (email/username bisa dipakai lagi). Ditolak otomatis bila akun punya riwayat transaksi. Lanjut?')">
                                    @csrf @method('DELETE')
                                    <button aria-label="Hapus permanen {{ $row->fullname }}" title="Hapus permanen" class="inline-flex items-center justify-center w-9 h-9 ml-1 text-rose-700 bg-white border border-stone-200 rounded-lg hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16m-10 4v6m4-6v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg></button>
                                </form>
                            @else
                                <span class="text-stone-400">—</span>
                            @endif
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-6 text-center text-stone-400">Tidak ada user.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="mt-4">{{ $users->links() }}</div>

{{-- Create modal --}}
<div id="createUserModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-stone-900">Tambah User Baru</h3>
            <button onclick="toggleModal('createUserModal')" class="text-stone-400 hover:text-stone-700"> Tutup </button>
        </div>
        <form method="POST" id="createUserForm" action="{{ route('users.store') }}" class="grid grid-cols-2 gap-3 text-sm">
            @csrf
            @include('users._fields', ['roles' => $roles, 'isSuper' => $isSuper])
            <div class="col-span-2">
                <label class="block text-xs font-semibold text-stone-700 mb-1">Password (dibuat otomatis — bisa diganti)</label>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="text" name="password" id="genPassword" required
                               class="w-full px-3 py-2 pr-24 border border-stone-300 rounded-lg font-mono tracking-wide">
                        <button type="button" onclick="togglePw()" id="pwEyeBtn" title="Lihat / sembunyikan"
                                class="absolute right-2 top-1/2 -translate-y-1/2 text-stone-500 hover:text-stone-800 text-[10px]">Sembunyikan</button>
                    </div>
                    <button type="button" onclick="regenPw()" title="Buat ulang" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 rounded-lg text-xs">Buat ulang</button>
                    <button type="button" onclick="copyPw()" id="pwCopyBtn" title="Salin" class="px-3 py-2 bg-stone-100 hover:bg-stone-200 rounded-lg text-xs">Salin</button>
                </div>
                <input type="hidden" name="password_confirmation" id="genPasswordConfirm">
                <p class="text-[10px] text-stone-400 mt-1">Salin password ini & berikan ke user. User dapat menggantinya sendiri lewat menu "Ubah Password" setelah login.</p>
            </div>
            <div class="col-span-2 flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('createUserModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Simpan</button>
            </div>
        </form>
    </div>
</div>

{{-- Edit modal --}}
<div id="editUserModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-stone-900">Edit User</h3>
            <button onclick="toggleModal('editUserModal')" class="text-stone-400 hover:text-stone-700"> Tutup </button>
        </div>
        <form method="POST" id="editUserForm" class="grid grid-cols-2 gap-3 text-sm">
            @csrf @method('PUT')
            @include('users._fields', ['roles' => $roles, 'isSuper' => $isSuper, 'edit' => true])
            <div class="col-span-2 flex justify-end gap-2 mt-2">
                <button type="button" onclick="toggleModal('editUserModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Update</button>
            </div>
        </form>
    </div>
</div>

{{-- Reset password modal --}}
<div id="resetPwModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl w-full max-w-sm p-6">
        <h3 class="text-sm font-bold text-stone-900 mb-1">Reset Password</h3>
        <p id="resetPwName" class="text-xs text-stone-500 mb-4"></p>
        <form method="POST" id="resetPwForm" class="space-y-3 text-sm">
            @csrf
            <input type="password" name="password" placeholder="Password baru" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            <input type="password" name="password_confirmation" placeholder="Konfirmasi password" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            <div class="flex justify-end gap-2">
                <button type="button" onclick="toggleModal('resetPwModal')" class="px-4 py-2 text-stone-600 rounded-lg">Batal</button>
                <button class="px-5 py-2 bg-red-600 text-white rounded-lg">Reset</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function openEditUser(u) {
        const f = document.getElementById('editUserForm');
        f.action = '/users/' + u.id;
        f.querySelector('[name=fullname]').value = u.fullname ?? '';
        f.querySelector('[name=email]').value = u.email ?? '';
        f.querySelector('[name=username]').value = u.username ?? '';
        f.querySelector('[name=role]').value = u.role ?? '';
        f.querySelector('[name=company_name]').value = u.company_name ?? '';
        f.querySelector('[name=phone]').value = u.phone ?? '';
        f.querySelector('[name=address]').value = u.address ?? '';
        const regionSel = f.querySelector('[data-region-prov]');
        const citySel = f.querySelector('[data-region-city]');
        if (regionSel) {
            const rv = u.region ?? '';
            // Region lama yang bukan provinsi (data lama) → tambah opsi sementara biar tak hilang.
            if (rv && !Array.from(regionSel.options).some(o => o.value === rv)) {
                regionSel.add(new Option(rv + ' (lama)', rv));
            }
            regionSel.value = rv;
        }
        if (citySel && window.regionFillCity) {
            const cv = u.city ?? '';
            window.regionFillCity(u.region ?? '', citySel, cv);
            // Kota lama yang tak ada di daftar provinsi → tambah opsi biar tak hilang.
            if (cv && !Array.from(citySel.options).some(o => o.value === cv)) {
                citySel.add(new Option(cv + ' (lama)', cv));
                citySel.value = cv;
            }
        }
        f.querySelector('[name=status]').value = u.status ?? 'active';
        refreshUplineOptions(f);
        const uplEl = f.querySelector('[name=upline_id]');
        if (uplEl) uplEl.value = u.upline_id ?? '';
        const midEl = f.querySelector('[data-memberid-display]');
        if (midEl) midEl.value = u.member_id ?? '';
        refreshMemberId(f);
        toggleModal('editUserModal');
    }
    function openResetPw(id, name) {
        const f = document.getElementById('resetPwForm');
        f.action = '/users/' + id + '/reset-password';
        document.getElementById('resetPwName').textContent = name;
        toggleModal('resetPwModal');
    }

    // ---- Upline picker: saring kandidat induk sesuai role terpilih ----
    @php
        $allowedParents = collect(\App\Support\PartnerHierarchy::TIERS)->keys()
            ->mapWithKeys(fn ($role) => [$role => \App\Support\PartnerHierarchy::allowedParentRoles($role)])->all();
    @endphp
    const ALLOWED_PARENTS = {!! json_encode($allowedParents) !!};
    const PARTNER_ROLES = {!! json_encode(\App\Models\User::PARTNER_ROLES) !!};
    function refreshMemberId(form) {
        const roleSel = form.querySelector('[name=role]');
        const wrap = form.querySelector('[data-memberid-wrap]');
        if (!roleSel || !wrap) return;
        wrap.style.display = PARTNER_ROLES.indexOf(roleSel.value) !== -1 ? '' : 'none';
    }
    function refreshUplineOptions(form) {
        const roleSel = form.querySelector('[name=role]');
        const uplineSel = form.querySelector('[name=upline_id]');
        if (!roleSel || !uplineSel) return;
        const wrap = uplineSel.closest('[data-upline-wrap]');
        const role = roleSel.value;
        const isTier = Object.prototype.hasOwnProperty.call(ALLOWED_PARENTS, role);
        const allowed = ALLOWED_PARENTS[role] || [];
        if (!isTier || allowed.length === 0) {
            if (wrap) wrap.style.display = 'none';
            uplineSel.value = '';
            return;
        }
        if (wrap) wrap.style.display = '';
        Array.from(uplineSel.options).forEach(function (opt) {
            if (opt.value === '') { opt.hidden = false; return; }
            const ok = allowed.indexOf(opt.dataset.role) !== -1;
            opt.hidden = !ok;
            if (!ok && uplineSel.value === opt.value) uplineSel.value = '';
        });
    }
    ['createUserForm', 'editUserForm'].forEach(function (id) {
        const form = document.getElementById(id);
        if (!form) return;
        const roleSel = form.querySelector('[name=role]');
        if (roleSel) roleSel.addEventListener('change', function () { refreshUplineOptions(form); refreshMemberId(form); });
    });

    // ---- Auto-generate username from full name on the CREATE form ----
    function slugUsername(s) {
        return (s || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')                       // non-alnum -> underscore
            .replace(/^_+|_+$/g, '')                           // trim underscores
            .slice(0, 30);
    }
    // Short role labels for the username combo.
    const ROLE_SHORT = { super_admin: 'sadmin', admin: 'admin', gudang: 'gudang', distributor: 'dist', reseller: 'resel' };
    function firstWordSlug(s) { return slugUsername(((s || '').trim().split(/\s+/)[0]) || ''); }
    // username = nama(1 kata) + role(singkat) + region(1 kata), ringkas.
    function buildUsername(name, role, region) {
        const parts = [firstWordSlug(name), (ROLE_SHORT[role] || slugUsername(role || '')), firstWordSlug(region)].filter(Boolean);
        return parts.join('_').slice(0, 30);
    }

    (function () {
        const cf = document.getElementById('createUserForm');
        if (!cf) return;
        const nameInput = cf.querySelector('[name=fullname]');
        const roleInput = cf.querySelector('[name=role]');
        const regionInput = cf.querySelector('[name=region]');
        const userInput = cf.querySelector('[name=username]');
        // Mark the username as manually edited so we stop overwriting it.
        userInput.addEventListener('input', function () { userInput.dataset.touched = '1'; });
        function refreshUsername() {
            if (userInput.dataset.touched === '1') return;
            userInput.value = buildUsername(
                nameInput.value,
                roleInput ? roleInput.value : '',
                regionInput ? regionInput.value : ''
            );
        }
        nameInput.addEventListener('input', refreshUsername);
        if (roleInput) roleInput.addEventListener('change', refreshUsername);
        if (regionInput) regionInput.addEventListener('change', refreshUsername);
        // keep hidden confirmation synced if admin edits password manually
        const pw = cf.querySelector('#genPassword');
        const pwc = cf.querySelector('#genPasswordConfirm');
        if (pw && pwc) pw.addEventListener('input', function () { pwc.value = pw.value; });

        window.openCreateUser = function () {
            cf.reset();
            userInput.dataset.touched = '';
            refreshUplineOptions(cf);
            refreshMemberId(cf);
            regenPw();                 // fresh auto-generated password
            if (pw) pw.type = 'text';  // visible by default so it can be copied
            if (pwEyeBtn) pwEyeBtn.textContent = 'Sembunyikan';
            toggleModal('createUserModal');
        };
    })();
    // Fallback if create form is absent for some reason.
    if (!window.openCreateUser) { window.openCreateUser = function () { toggleModal('createUserModal'); }; }

    // ---- Auto-generated password helpers ----
    const pwEyeBtn = document.getElementById('pwEyeBtn');
    function makePassword(len) {
        len = len || 10;
        const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        let out = '';
        try {
            const arr = new Uint32Array(len);
            (window.crypto || window.msCrypto).getRandomValues(arr);
            for (let i = 0; i < len; i++) out += chars[arr[i] % chars.length];
        } catch (e) {
            for (let i = 0; i < len; i++) out += chars[Math.floor(Math.random() * chars.length)];
        }
        return out;
    }
    function regenPw() {
        const pw = document.getElementById('genPassword');
        const pwc = document.getElementById('genPasswordConfirm');
        if (!pw) return;
        pw.value = makePassword(10);
        if (pwc) pwc.value = pw.value;
    }
    function togglePw() {
        const pw = document.getElementById('genPassword');
        if (!pw) return;
        pw.type = pw.type === 'password' ? 'text' : 'password';
        if (pwEyeBtn) pwEyeBtn.textContent = pw.type === 'password' ? 'Tampilkan' : 'Sembunyikan';
    }
    function copyPw() {
        const pw = document.getElementById('genPassword');
        if (!pw) return;
        const done = () => { const b = document.getElementById('pwCopyBtn'); if (b) { b.textContent = 'Tersalin'; setTimeout(() => b.textContent = 'Salin', 1200); } };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(pw.value).then(done).catch(() => { pw.select(); document.execCommand('copy'); done(); });
        } else {
            pw.select(); document.execCommand('copy'); done();
        }
    }
</script>
@include('partials.region-cascade')
@endpush
