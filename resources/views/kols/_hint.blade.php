{{-- Tombol (?) — buka catatan penjelasan (ala comment Excel). Pasangannya: kols._hint-dialog (sekali per halaman). --}}
<button type="button" onclick="event.stopPropagation(); event.preventDefault(); kolHint('{{ $key }}', this)" title="Apa ini?"
    class="ml-0.5 inline-flex items-center justify-center w-3.5 h-3.5 rounded-full bg-stone-200 text-stone-600 text-[9px] font-bold normal-case align-middle hover:bg-red-600 hover:text-white">?</button>
