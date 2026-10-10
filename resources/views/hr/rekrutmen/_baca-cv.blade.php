{{-- Tombol "Baca CV dengan AI": pilih file → langsung terkirim → AI mengisi form tambah kandidat ($teksTombol opsional). --}}
<form method="POST" action="{{ route('hr.rekrutmen.baca-cv') }}" enctype="multipart/form-data">
    @csrf
    <label class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold bg-violet-700 text-white rounded-lg hover:bg-violet-800 cursor-pointer" title="PDF atau foto CV, maks 5 MB">
        <span data-label>✨ {{ $teksTombol ?? 'Baca CV dengan AI' }}</span>
        <input type="file" name="cv" accept=".pdf,image/jpeg,image/png,image/webp" class="hidden"
            onchange="if (this.files.length) { const l = this.closest('label'); l.classList.add('opacity-60', 'pointer-events-none'); l.querySelector('[data-label]').textContent = 'AI sedang membaca CV… tunggu sebentar'; this.form.submit(); }">
    </label>
</form>
