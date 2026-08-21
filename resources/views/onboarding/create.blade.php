@extends('layouts.app')
@section('title', 'Onboarding via Paket Join')
@section('heading', 'Onboarding via Paket Join')

@section('content')
<a href="{{ route('users.index') }}" class="text-xs text-stone-500 hover:text-stone-800">← Kembali ke Kelola Anggota</a>

@php $uplines = $hierarchy->eligibleUplines(\App\Models\User::ROLE_RESELLER_BRONZE, null); @endphp

<form method="POST" action="{{ route('onboarding.store') }}" class="mt-3 space-y-5">
    @csrf

    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-2 gap-4 text-sm">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-stone-700 mb-1">Nama Lengkap *</label>
            <input name="fullname" required maxlength="150" value="{{ old('fullname') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Email *</label>
            <input type="email" name="email" required maxlength="150" value="{{ old('email') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Username *</label>
            <input name="username" required maxlength="100" value="{{ old('username') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Password *</label>
            <input type="password" name="password" required minlength="8" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Konfirmasi Password *</label>
            <input type="password" name="password_confirmation" required minlength="8" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Perusahaan</label>
            <input name="company_name" value="{{ old('company_name') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Telepon</label>
            <input name="phone" value="{{ old('phone') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-stone-700 mb-1">Region</label>
            <input name="region" value="{{ old('region') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5 grid sm:grid-cols-2 gap-4 text-sm">
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Paket Join *</label>
            <select name="join_package_id" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
                <option value="">— pilih paket —</option>
                @foreach($packages as $pkg)
                    <option value="{{ $pkg->id }}" @selected((string) old('join_package_id') === (string) $pkg->id)>
                        {{ $pkg->name }} · Rp {{ number_format($pkg->price, 0, ',', '.') }} · {{ \App\Support\PartnerHierarchy::label($pkg->target_role) }}
                    </option>
                @endforeach
            </select>
            @if($packages->isEmpty())
                <p class="text-[10px] text-rose-500 mt-1">Belum ada paket join aktif. Tambahkan dulu di menu Paket Join.</p>
            @endif
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Upline (induk di pohon)</label>
            <input type="text" list="uplineList" autocomplete="off" id="uplineSearch"
                placeholder="Ketik nama / member ID… (kosong = belum ditempatkan)"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            <input type="hidden" name="upline_id" id="uplineId" value="{{ old('upline_id') }}">
            <datalist id="uplineList"></datalist>
            <span id="uplineMiss" class="block mt-1 text-[10px] text-rose-500 hidden">Upline tak dikenali — pilih dari daftar atau kosongkan.</span>
            <p class="text-[10px] text-stone-400 mt-1">Reseller Bronze & Gold selalu ditempatkan di bawah Distributor.</p>
        </div>
        <div>
            <label class="block text-xs font-semibold text-stone-700 mb-1">Sponsor / perekrut (opsional)</label>
            <input type="text" list="sponsorList" autocomplete="off" id="sponsorSearch"
                placeholder="Ketik nama / member ID… (kosong = daftar mandiri)"
                class="w-full px-3 py-2 border border-stone-300 rounded-lg">
            <input type="hidden" name="sponsor_id" id="sponsorId" value="{{ old('sponsor_id') }}">
            <datalist id="sponsorList"></datalist>
            <span id="sponsorMiss" class="block mt-1 text-[10px] text-rose-500 hidden">Sponsor tak dikenali — pilih dari daftar atau kosongkan.</span>
            <p class="text-[10px] text-stone-400 mt-1">Bonus join 10% ke sponsor. Beda dari upline. Kosong = tak ada bonus join.</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-stone-200 p-5 text-sm">
        <label class="inline-flex items-start gap-2">
            <input type="checkbox" name="paid" value="1" required @checked(old('paid')) class="mt-0.5 accent-emerald-600">
            <span>Konfirmasi <b>sudah menerima pembayaran</b> paket join dari calon reseller ini.</span>
        </label>
    </div>

    <div class="flex justify-end gap-2">
        <a href="{{ route('users.index') }}" class="px-4 py-2 text-sm text-stone-600 rounded-lg">Batal</a>
        <button class="px-6 py-2.5 text-sm bg-emerald-600 text-white rounded-lg hover:bg-emerald-700 font-semibold">Daftarkan Reseller</button>
    </div>
</form>
@endsection

@push('scripts')
<script>
    // Upline & Sponsor: ketik-buat-cari (datalist), bukan gulir 1-1. Keduanya
    // OPSIONAL — kosong = tak dipilih. Teks → id (hidden); server validasi id.
    const UPLINES = {{ \Illuminate\Support\Js::from($uplines->map(fn ($c) => ['id' => $c->id, 'label' => trim($c->fullname.' · '.\App\Support\PartnerHierarchy::label($c->role).($c->region ? ' · '.$c->region : '').($c->member_id ? ' · '.$c->member_id : ''))])->values()) }};
    const SPONSORS = {{ \Illuminate\Support\Js::from($sponsors->map(fn ($s) => ['id' => $s->id, 'label' => trim($s->fullname.' · '.\App\Support\PartnerHierarchy::label($s->role).($s->member_id ? ' · '.$s->member_id : ''))])->values()) }};

    function wireTypeahead(searchId, hiddenId, listId, missId, data) {
        const search = document.getElementById(searchId);
        const hidden = document.getElementById(hiddenId);
        const list = document.getElementById(listId);
        const miss = document.getElementById(missId);
        const L2I = {}, I2L = {};
        data.forEach(d => {
            L2I[d.label] = d.id; I2L[d.id] = d.label;
            const o = document.createElement('option'); o.value = d.label; list.appendChild(o);
        });
        if (hidden.value && I2L[hidden.value]) search.value = I2L[hidden.value];
        search.addEventListener('input', () => {
            const v = search.value.trim();
            const id = L2I[v] || '';
            hidden.value = id;
            miss.classList.toggle('hidden', !!id || v === '');
        });
    }
    wireTypeahead('uplineSearch', 'uplineId', 'uplineList', 'uplineMiss', UPLINES);
    wireTypeahead('sponsorSearch', 'sponsorId', 'sponsorList', 'sponsorMiss', SPONSORS);
</script>
@endpush
