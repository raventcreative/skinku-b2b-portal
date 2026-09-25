{{-- Tombol "lihat password" otomatis untuk SEMUA <input type="password"> di halaman.
     Di-include sekali di layout + halaman auth — view lain tak perlu diubah. --}}
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var eye = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>';
        var eyeOff = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>';

        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            var wrap = document.createElement('span');
            wrap.className = 'relative ' + (input.classList.contains('w-full') || getComputedStyle(input).display === 'block' ? 'block' : 'inline-block');
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            input.style.paddingRight = '2.5rem';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'absolute inset-y-0 right-0 px-3 flex items-center text-stone-400 hover:text-stone-700';
            btn.setAttribute('aria-label', 'Tampilkan password');
            btn.setAttribute('aria-pressed', 'false');
            btn.innerHTML = eye;
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.innerHTML = show ? eyeOff : eye;
                btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
            wrap.appendChild(btn);
        });
    });
</script>
