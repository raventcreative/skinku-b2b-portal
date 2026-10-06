{{-- Input harga Rupiah bertitik ribuan. Pakai: <input type="text" inputmode="numeric" data-rupiah name="…"
     value="{{ \App\Support\Rupiah::input($harga) }}">. Saat diketik tampil "195.000"; yang TERKIRIM selalu angka
     polos "195000" lewat event formdata (juga utk form.submit(), mis. dari skrip kompres foto) — tampilan tak
     berubah. Server tetap menormalkan via Rupiah::polos() sbg jaring pengaman. Rupiah tanpa sen. --}}
@once
@push('scripts')
<script>
(function(){
    function digit(v){ return String(v || '').replace(/\D/g, '').replace(/^0+(?=\d)/, ''); }
    function titik(raw){ return raw ? Number(raw).toLocaleString('id-ID') : ''; }

    // Format saat diketik/ditempel; kursor dijaga tetap di depan digit yang sama (dihitung dari kanan).
    document.addEventListener('input', function(e){
        var el = e.target;
        if (!el.matches || !el.matches('input[data-rupiah]')) return;
        var caret = el.selectionStart == null ? el.value.length : el.selectionStart;
        var kanan = el.value.slice(caret).replace(/\D/g, '').length;
        var v = el.value = titik(digit(el.value));
        var pos = v.length, n = 0;
        while (pos > 0 && n < kanan) { pos--; if (/\d/.test(v.charAt(pos))) n++; }
        try { el.setSelectionRange(pos, pos); } catch (err) {}
    });

    // formdata tak bubble → tangkap di fase capture. Ganti nilai terkirim jadi angka polos.
    document.addEventListener('formdata', function(e){
        var form = e.target;
        if (!form || !form.elements) return;
        Array.prototype.forEach.call(form.elements, function(el){
            if (el.name && !el.disabled && el.matches && el.matches('input[data-rupiah]')) {
                e.formData.set(el.name, digit(el.value));
            }
        });
    }, true);
})();
</script>
@endpush
@endonce
