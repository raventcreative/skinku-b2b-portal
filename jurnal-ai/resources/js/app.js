// Interaksi kecil tanpa framework — cukup untuk form jurnal.
// Semua perhitungan uang yang mengikat tetap di PHP (JournalPoster).

/** Format angka ke gaya Indonesia untuk tampilan total. */
const rupiah = (n) =>
    new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(n || 0);

/**
 * Hitung ulang total debit/kredit satu blok jurnal dan beri tahu kalau belum
 * balance — supaya user tahu sebelum submit, bukan setelah ditolak server.
 */
function wireBalance(root) {
    const inputs = root.querySelectorAll('[data-money]');
    const out = {
        debit: root.querySelector('[data-total="debit"]'),
        credit: root.querySelector('[data-total="credit"]'),
        state: root.querySelector('[data-balance-state]'),
    };
    if (!out.debit || !out.credit) return;

    const recalc = () => {
        let debit = 0;
        let credit = 0;
        inputs.forEach((el) => {
            const value = parseFloat(el.value) || 0;
            if (el.dataset.money === 'debit') debit += value;
            else credit += value;
        });

        out.debit.textContent = rupiah(debit);
        out.credit.textContent = rupiah(credit);

        if (!out.state) return;
        const diff = Math.round((debit - credit) * 100) / 100;
        const balanced = Math.abs(diff) < 0.005;
        out.state.textContent = balanced ? 'Balance' : `Selisih Rp${rupiah(Math.abs(diff))}`;
        out.state.className = balanced ? 'badge-ok' : 'badge-bad';
    };

    inputs.forEach((el) => el.addEventListener('input', recalc));
    recalc();
}

/** Satu baris hanya boleh debit ATAU kredit — kosongkan sisi lain otomatis. */
function wireExclusiveSides(root) {
    root.querySelectorAll('[data-money]').forEach((el) => {
        el.addEventListener('input', () => {
            if (!el.value) return;
            const row = el.closest('[data-line]');
            const other = row?.querySelector(
                `[data-money="${el.dataset.money === 'debit' ? 'credit' : 'debit'}"]`,
            );
            if (other && parseFloat(el.value)) other.value = '';
        });
    });
}

/** Tambah baris kosong di form jurnal manual. */
function wireAddLine() {
    document.querySelectorAll('[data-add-line]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const body = document.querySelector(btn.dataset.addLine);
            const template = body?.querySelector('[data-line]');
            if (!body || !template) return;

            const clone = template.cloneNode(true);
            const index = body.querySelectorAll('[data-line]').length;
            clone.querySelectorAll('[name]').forEach((el) => {
                el.name = el.name.replace(/\[\d+\]/, `[${index}]`);
                if (el.tagName === 'SELECT') el.selectedIndex = 0;
                else el.value = '';
            });
            body.appendChild(clone);

            const form = body.closest('[data-journal]');
            if (form) {
                wireBalance(form);
                wireExclusiveSides(form);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-journal]').forEach((root) => {
        wireBalance(root);
        wireExclusiveSides(root);
    });
    wireAddLine();
});
