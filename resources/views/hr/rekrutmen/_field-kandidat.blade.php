{{-- Field data kandidat (form tambah & ubah). Butuh $candidate, $openings, $input, $label. --}}
<label class="{{ $label }} sm:col-span-2">Nama lengkap *<input name="name" value="{{ old('name', $candidate->name) }}" required maxlength="120" class="{{ $input }}"></label>
<label class="{{ $label }}">Lowongan
    <select name="job_opening_id" class="{{ $input }}">
        <option value="">— tanpa lowongan —</option>
        @foreach($openings as $o)
            <option value="{{ $o->id }}" @selected((string) old('job_opening_id', $candidate->job_opening_id) === (string) $o->id)>{{ $o->title }}{{ $o->status === 'tutup' ? ' (tutup)' : '' }}</option>
        @endforeach
    </select>
</label>
<label class="{{ $label }}">Sumber lamaran<input name="source" value="{{ old('source', $candidate->source) }}" maxlength="60" list="daftarSumber" placeholder="Instagram, JobStreet, referensi…" class="{{ $input }}"></label>
<datalist id="daftarSumber">@foreach(['Instagram', 'TikTok', 'LinkedIn', 'JobStreet', 'Glints', 'Referensi karyawan', 'Walk-in'] as $s)<option value="{{ $s }}">@endforeach</datalist>
<label class="{{ $label }}">Telepon / WA<input name="phone" value="{{ old('phone', $candidate->phone) }}" maxlength="30" class="{{ $input }}"></label>
<label class="{{ $label }}">Email<input type="email" name="email" value="{{ old('email', $candidate->email) }}" maxlength="120" class="{{ $input }}"></label>
<label class="{{ $label }} sm:col-span-2">Ringkasan CV
    <textarea name="cv_summary" rows="{{ filled(old('cv_summary', $candidate->cv_summary)) ? 14 : 4 }}" maxlength="5000" placeholder="Poin pengalaman kerja, pendidikan, keahlian… — terisi otomatis lewat Baca CV dengan AI, atau ketik sendiri." class="mt-1 w-full px-3 py-2 text-xs leading-relaxed border border-stone-300 rounded-lg">{{ old('cv_summary', $candidate->cv_summary) }}</textarea>
</label>
