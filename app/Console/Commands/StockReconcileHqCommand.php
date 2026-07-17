<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Setel ulang products.hq_stock agar cocok dengan saldo gerakan terakhirnya.
 *
 * Dipakai saat saldo dan riwayat berselisih — yaitu ketika ada yang mengubah
 * stok TANPA menulis gerakan. Riwayat gerakan yang dianggap benar, bukan
 * saldonya: tiap gerakan punya tanggal, sebab, dan pelaku; saldo cuma satu
 * angka tanpa cerita.
 *
 * SENGAJA tidak menulis gerakan koreksi. Yang diperbaiki di sini adalah
 * perubahan yang memang tak berjejak; menambahinya gerakan baru justru
 * mengarang kejadian yang tak pernah terjadi di gudang. Jejaknya masuk Audit
 * Log — di situlah tempatnya, karena ini tindakan administratif, bukan
 * pergerakan barang.
 *
 * BUKAN pengganti stok opname. Ini menyamakan angka sistem dengan catatan
 * sistem sendiri; kalau yang meleset justru hitungan fisiknya, yang benar
 * adalah opname, bukan perintah ini.
 */
class StockReconcileHqCommand extends Command
{
    protected $signature = 'stock:reconcile-hq
        {cari? : Nama/SKU produk (kosong = periksa semua)}
        {--as= : Username super admin sebagai pelaku (wajib bila --force)}
        {--force : Benar-benar setel. Tanpa ini hanya simulasi.}';

    protected $description = 'Cocokkan stok HQ dengan saldo gerakan terakhir (deteksi & perbaiki perubahan tanpa jejak)';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if ($force && ! $this->authenticate()) {
            return self::FAILURE;
        }

        $cari = $this->argument('cari');

        $products = Product::query()
            ->when($cari, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$cari}%")->orWhere('sku', 'like', "%{$cari}%")))
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            $this->error('Tidak ada produk yang cocok.');

            return self::FAILURE;
        }

        $temuan = [];

        foreach ($products as $product) {
            $terakhir = StockMovement::query()
                ->whereNull('user_id')
                ->where('product_id', $product->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            // Tanpa gerakan sama sekali tak ada pembanding — diamkan, jangan
            // nolkan stok yang mungkin benar hanya karena riwayatnya kosong.
            if (! $terakhir) {
                continue;
            }

            $seharusnya = (int) $terakhir->after_qty;
            $sekarang = (int) $product->hq_stock;

            if ($seharusnya === $sekarang) {
                continue;
            }

            $temuan[] = [$product, $sekarang, $seharusnya, $terakhir];
        }

        if (! $temuan) {
            $this->info('Semua stok HQ sudah cocok dengan riwayat gerakannya.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($force ? '<fg=green>Disetel:</>' : '<fg=yellow>SIMULASI</> — belum ada yang berubah:');

        $this->table(
            ['Produk', 'Stok sekarang', 'Menurut riwayat', 'Selisih', 'Gerakan terakhir'],
            collect($temuan)->map(function (array $t) {
                [$product, $sekarang, $seharusnya, $terakhir] = $t;
                $selisih = $sekarang - $seharusnya;

                return [
                    $product->name,
                    number_format($sekarang, 0, ',', '.'),
                    number_format($seharusnya, 0, ',', '.'),
                    ($selisih > 0 ? '+' : '−').number_format(abs($selisih), 0, ',', '.'),
                    $terakhir->created_at?->format('d M Y H:i').' · '.($terakhir->reference_type ?: 'manual'),
                ];
            })->all(),
        );

        if (! $force) {
            $this->newLine();
            $this->warn('Jalankan ulang dengan --force --as=USERNAME untuk benar-benar menyetel.');
            $this->line('Pastikan dulu yang keliru memang SALDO-nya, bukan riwayatnya. Kalau hitungan');
            $this->line('fisik yang berbeda, yang benar Stok Opname — bukan perintah ini.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($temuan) {
            foreach ($temuan as [$product, $sekarang, $seharusnya, $terakhir]) {
                $p = Product::lockForUpdate()->find($product->id);
                $p->hq_stock = $seharusnya;
                $p->save();

                AuditService::log(
                    action: 'reconcile_hq_stock',
                    targetType: 'product',
                    targetId: $p->id,
                    before: ['hq_stock' => $sekarang],
                    after: [
                        'hq_stock' => $seharusnya,
                        'dasar' => 'saldo gerakan terakhir '.$terakhir->created_at?->toDateTimeString(),
                    ],
                );
            }
        });

        $this->newLine();
        $this->info(count($temuan).' produk disetel ulang mengikuti riwayat gerakannya. Tercatat di Audit Log.');

        return self::SUCCESS;
    }

    private function authenticate(): bool
    {
        $username = $this->option('as');

        if (! $username) {
            $this->error('--as wajib diisi saat --force: koreksi stok harus tercatat pelakunya.');
            $this->daftarSuperAdmin();

            return false;
        }

        $actor = User::where('username', $username)->orWhere('email', $username)->first();

        if (! $actor || ! $actor->isSuperAdmin()) {
            $this->error("\"{$username}\" tidak ditemukan atau bukan super admin.");
            $this->daftarSuperAdmin();

            return false;
        }

        Auth::login($actor);

        return true;
    }

    private function daftarSuperAdmin(): void
    {
        $daftar = User::where('role', User::ROLE_SUPER_ADMIN)->where('status', User::STATUS_ACTIVE)->orderBy('username')->get();

        if ($daftar->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('Super admin aktif — pakai salah satu (tanpa kurung siku):');
        foreach ($daftar as $u) {
            $this->line("  --as={$u->username}   <fg=gray>({$u->fullname})</>");
        }
    }
}
