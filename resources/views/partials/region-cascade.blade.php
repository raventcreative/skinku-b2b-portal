{{-- Wilayah bertingkat: provinsi ([data-region-prov]) → kota ketik-cari.
     Kota = <input list> + <datalist [data-region-citylist]> yang di-refresh per
     provinsi, plus penanda [data-region-citymiss] kalau ketik di luar daftar.
     openEditUser (modal edit) memanggil window.regionFillCity sendiri. --}}
<script>
(function () {
    const CITIES = @json(config('regions.cities'));
    function opts(prov) {
        return (CITIES[prov] || []).map(function (c) { return '<option value="' + c + '"></option>'; }).join('');
    }
    // Isi ulang datalist kota untuk provinsi terpilih (dipakai juga oleh openEditUser).
    window.regionFillCity = function (prov, dl) { if (dl) dl.innerHTML = opts(prov); };

    document.querySelectorAll('[data-region-prov]').forEach(function (prov) {
        const form = prov.closest('form') || document;
        const input = form.querySelector('[data-region-city]');
        const dl = form.querySelector('[data-region-citylist]');
        const miss = form.querySelector('[data-region-citymiss]');
        if (!input || !dl) return;

        function validate() {
            const v = input.value.trim();
            const ok = !v || (CITIES[prov.value] || []).includes(v);
            if (miss) miss.classList.toggle('hidden', ok);
        }
        prov.addEventListener('change', function () {
            dl.innerHTML = opts(prov.value);
            input.value = '';                       // reset kota saat ganti provinsi
            if (miss) miss.classList.add('hidden');
        });
        input.addEventListener('input', validate);
    });
})();
</script>
