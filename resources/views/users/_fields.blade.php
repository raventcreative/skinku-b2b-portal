@php $edit = $edit ?? false; $scope = $scope ?? ''; @endphp
<div class="col-span-2">
    <label class="block text-xs font-semibold text-stone-700 mb-1">Nama Lengkap *</label>
    <input name="fullname" required value="{{ old('fullname') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Email *</label>
    <input type="email" name="email" required value="{{ old('email') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Username *</label>
    <input name="username" required value="{{ old('username') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Role *</label>
    <select name="role" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        @foreach($roles as $r)
            @if(in_array($r->name, ['super_admin','admin'], true) && !$isSuper) @continue @endif
            <option value="{{ $r->name }}">{{ $r->label }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Status *</label>
    <select name="status" required class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        <option value="active">active</option>
        <option value="inactive">inactive</option>
    </select>
</div>
<div class="col-span-2" data-memberid-wrap style="display:none">
    <label class="block text-xs font-semibold text-stone-700 mb-1">Member ID</label>
    <input type="text" readonly data-memberid-display value="{{ $edit ? '' : ($nextMemberId ?? '') }}"
           class="w-full px-3 py-2 border border-stone-200 rounded-lg bg-stone-50 text-stone-500 font-mono">
    <p class="text-[10px] text-stone-400 mt-1">{{ $edit ? 'ID permanen anggota — tidak berubah.' : 'Dibuat otomatis saat disimpan (khusus mitra).' }}</p>
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Perusahaan</label>
    <input name="company_name" value="{{ old('company_name') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Telepon</label>
    <input name="phone" value="{{ old('phone') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Region (Provinsi)</label>
    <select name="region" data-region-prov class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        <option value="">— pilih provinsi —</option>
        @foreach(config('regions.provinces') as $prov)
            <option value="{{ $prov }}" @selected(old('region') === $prov)>{{ $prov }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Kota / Kabupaten</label>
    <input type="text" name="city" list="cityList{{ $scope }}" data-region-city autocomplete="off"
           value="{{ old('city') }}" placeholder="Ketik / pilih kota…"
           class="w-full px-3 py-2 border border-stone-300 rounded-lg">
    <datalist id="cityList{{ $scope }}" data-region-citylist>
        @foreach(config('regions.cities')[old('region')] ?? [] as $kota)
            <option value="{{ $kota }}"></option>
        @endforeach
    </datalist>
    <span data-region-citymiss class="block mt-1 text-[10px] text-rose-500 hidden">Kota tak ada di provinsi ini — pilih dari daftar.</span>
</div>
<div class="col-span-2" data-upline-wrap style="display:none">
    <label class="block text-xs font-semibold text-stone-700 mb-1">Upline (induk di pohon)</label>
    <select name="upline_id" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
        <option value="">— belum ditempatkan —</option>
        @foreach(($uplineCandidates ?? collect()) as $cand)
            <option value="{{ $cand->id }}" data-role="{{ $cand->role }}">{{ $cand->fullname }} · {{ \App\Support\PartnerHierarchy::label($cand->role) }}{{ $cand->region ? ' · '.$cand->region : '' }}{{ $cand->member_id ? ' · '.$cand->member_id : '' }}</option>
        @endforeach
    </select>
    <p class="text-[10px] text-stone-400 mt-1">Otomatis disaring 1 tingkat di atas role terpilih. Kosongkan bila belum ada pohon di atasnya.</p>
</div>
<div class="col-span-2">
    <label class="block text-xs font-semibold text-stone-700 mb-1">Alamat</label>
    <textarea name="address" rows="2" class="w-full px-3 py-2 border border-stone-300 rounded-lg">{{ old('address') }}</textarea>
</div>
