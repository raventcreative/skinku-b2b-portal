@php $edit = $edit ?? false; @endphp
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
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Perusahaan</label>
    <input name="company_name" value="{{ old('company_name') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Telepon</label>
    <input name="phone" value="{{ old('phone') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div>
    <label class="block text-xs font-semibold text-stone-700 mb-1">Region</label>
    <input name="region" value="{{ old('region') }}" class="w-full px-3 py-2 border border-stone-300 rounded-lg">
</div>
<div class="col-span-2">
    <label class="block text-xs font-semibold text-stone-700 mb-1">Alamat</label>
    <textarea name="address" rows="2" class="w-full px-3 py-2 border border-stone-300 rounded-lg">{{ old('address') }}</textarea>
</div>
