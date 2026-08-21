{{-- Dropdown wilayah bertingkat: provinsi ([data-region-prov]) → kota
     ([data-region-city]). Sertakan sekali di halaman yang punya form wilayah.
     openEditUser (modal edit) memanggil window.regionFillCity sendiri. --}}
<script>
(function () {
    const CITIES = @json(config('regions.cities'));
    function fill(prov, citySel, selected) {
        let html = '<option value="">— pilih kota —</option>';
        for (const c of (CITIES[prov] || [])) {
            html += '<option value="' + c + '"' + (c === selected ? ' selected' : '') + '>' + c + '</option>';
        }
        citySel.innerHTML = html;
    }
    window.regionFillCity = fill;
    document.querySelectorAll('[data-region-prov]').forEach(function (prov) {
        const form = prov.closest('form') || document;
        const city = form.querySelector('[data-region-city]');
        if (city) prov.addEventListener('change', function () { fill(prov.value, city, ''); });
    });
})();
</script>
