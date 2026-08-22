{{-- Dropdown wilayah bertingkat: provinsi ([data-region-prov]) → kota
     ([data-region-city], native <select> biar seragam dgn provinsi). Isi kota
     di-refresh per provinsi. openEditUser memanggil window.regionFillCity sendiri. --}}
<script>
(function () {
    const CITIES = @json(config('regions.cities'));
    function opts(prov, selected) {
        let html = '<option value="">— pilih kota —</option>';
        for (const c of (CITIES[prov] || [])) {
            html += '<option value="' + c + '"' + (c === selected ? ' selected' : '') + '>' + c + '</option>';
        }
        return html;
    }
    window.regionFillCity = function (prov, sel, selected) { if (sel) sel.innerHTML = opts(prov, selected || ''); };
    document.querySelectorAll('[data-region-prov]').forEach(function (prov) {
        const form = prov.closest('form') || document;
        const city = form.querySelector('[data-region-city]');
        if (city) prov.addEventListener('change', function () { city.innerHTML = opts(prov.value, ''); });
    });
})();
</script>
