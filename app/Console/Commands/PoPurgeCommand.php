<?php

namespace App\Console\Commands;

use App\Exceptions\PurgeBlockedException;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Hapus PO uji coba SEOLAH TAK PERNAH ADA: batalkan efek stoknya, buang jejak
 * gerakannya, hapus permanen.
 *
 * Bedanya dengan tombol hapus di UI:
 *   - Hapus (soft delete): PO hilang dari daftar, stok DIBIARKAN. Inilah yang
 *     bikin 300 botol nyangkut di mitra padahal PO-nya sudah "dihapus".
 *   - Hapus permanen lama: PO lenyap tapi stock_movements-nya tetap ada dan
 *     menunjuk id yang sudah tidak ada — stok tetap terpotong, asalnya tak
 *     bisa dilacak lagi. Lebih buruk.
 *   - purge (ini): stok dikembalikan, jejak dibuang, PO dihapus permanen.
 *
 * Default DRY-RUN. Tanpa --force tak ada satu baris pun yang berubah.
 */
class PoPurgeCommand extends Command
{
    protected $signature = 'po:purge
        {nomor : Nomor PO, mis. SKN-PO-20260627-5881}
        {--as= : Username super admin sebagai pelaku (wajib bila --force)}
        {--force : Benar-benar jalankan. Tanpa ini hanya simulasi.}';

    protected $description = 'Batalkan efek stok sebuah PO, buang jejaknya, lalu hapus permanen (untuk data uji coba)';

    public function handle(PurchaseOrderService $service): int
    {
        $po = PurchaseOrder::withTrashed()->where('po_number', $this->argument('nomor'))->first();

        if (! $po) {
            $this->error("PO {$this->argument('nomor')} tidak ditemukan (termasuk yang sudah dihapus).");

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');

        if ($force && ! $this->authenticate()) {
            return self::FAILURE;
        }

        $this->line("PO <options=bold>{$po->po_number}</> — {$po->company_name} — Rp ".number_format((float) $po->total_amount, 0, ',', '.'));
        $this->line('Status: '.$po->status.($po->trashed() ? ' (sudah di-soft-delete)' : '').' | Tanggal: '.($po->orderDate()?->format('d M Y') ?? '-'));

        try {
            $hasil = $service->purge($po, dryRun: ! $force);
        } catch (PurgeBlockedException $e) {
            $this->newLine();
            $this->error('DIBATALKAN — tidak ada yang diubah:');
            foreach ($e->blockers as $b) {
                $this->line('  <fg=red>✗</> '.$b);
            }
            $this->newLine();
            $this->warn('Saldo negatif berarti ada gerakan stok LAIN di luar PO ini. Membulatkannya diam-diam');
            $this->warn('sama saja mengarang unit. Periksa dulu: artisan stock:holders "<produk>" --trace');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line($force ? '<fg=green>Dijalankan:</>' : '<fg=yellow>SIMULASI</> — belum ada yang berubah:');
        foreach ($hasil['actions'] as $a) {
            $this->line('  • '.$a);
        }
        $this->line('  • Hapus '.$hasil['movements'].' gerakan stok milik PO ini');
        $this->line('  • Hapus permanen PO beserta item & lampirannya');

        if (! $force) {
            $this->newLine();
            $this->warn('Jalankan ulang dengan --force --as=<username-super-admin> untuk benar-benar menghapus.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("PO {$po->po_number} dihapus permanen. Stok sudah dikembalikan seperti sebelum PO ini ada.");

        return self::SUCCESS;
    }

    /**
     * Penghapusan permanen wajib punya nama pelaku: AuditService mengambilnya
     * dari Auth::user(), dan lewat CLI itu null kalau tidak di-login-kan.
     */
    private function authenticate(): bool
    {
        $username = $this->option('as');

        if (! $username) {
            $this->error('--as=<username> wajib diisi saat --force: penghapusan permanen harus tercatat pelakunya.');

            return false;
        }

        $actor = User::where('username', $username)->first();

        if (! $actor) {
            $this->error("Pengguna \"{$username}\" tidak ditemukan.");

            return false;
        }

        if (! $actor->isSuperAdmin()) {
            $this->error("\"{$username}\" bukan super admin. Hanya super admin yang boleh menghapus permanen.");

            return false;
        }

        Auth::login($actor);

        return true;
    }
}
