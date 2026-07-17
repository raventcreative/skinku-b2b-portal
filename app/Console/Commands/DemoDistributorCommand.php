<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Buat akun distributor DEMO untuk peragaan, supaya akun mitra sungguhan tak
 * pernah perlu disentuh.
 *
 * Password-nya diacak dan TIDAK disimpan di mana pun: akun ini dipakai lewat
 * tombol "Masuk sebagai" milik super admin, yang tak butuh password sama sekali.
 * Kredensial demo yang bisa dipakai login justru jadi pintu masuk permanen yang
 * tak pernah diganti. Kalau nanti benar-benar perlu login langsung, reset lewat
 * Kelola Anggota — tercatat atas nama yang mereset.
 *
 * SENGAJA tanpa stok bawaan. Kolom "Stok Mitra" pada grafik Stok HQ vs Mitra
 * menjumlahkan seluruh baris inventory apa adanya — stok demo akan tercampur ke
 * angka nyata dan jadi persis kebingungan yang bikin 295 botol MIZU itu diusut
 * setengah hari.
 */
class DemoDistributorCommand extends Command
{
    protected $signature = 'demo:distributor
        {--username=demo_distributor : Username akun demo}
        {--nama=DEMO DISTRIBUTOR : Nama & nama perusahaan yang tampil}
        {--reset-password : Acak ulang password akun demo yang sudah ada}';

    protected $description = 'Buat/segarkan akun distributor demo untuk peragaan (tanpa menyentuh akun mitra asli)';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $nama = (string) $this->option('nama');
        $email = $username.'@demo.skinku.id';

        $ada = User::withTrashed()->where('username', $username)->first();

        if ($ada && ! $this->option('reset-password')) {
            if ($ada->trashed()) {
                $ada->restore();
            }

            $ada->status = User::STATUS_ACTIVE;
            $ada->role = User::ROLE_DISTRIBUTOR;
            $ada->save();

            $this->info("Akun demo \"{$username}\" sudah ada — dipastikan aktif & berperan distributor.");
            $this->petunjuk($ada);

            return self::SUCCESS;
        }

        $password = Str::password(20);

        $user = $ada ?: new User;
        $user->fill([
            'name' => $nama,
            'fullname' => $nama,
            'username' => $username,
            'email' => $email,
            'company_name' => $nama,
            'role' => User::ROLE_DISTRIBUTOR,
            'status' => User::STATUS_ACTIVE,
            'region' => 'Demo',
        ]);
        $user->password = Hash::make($password);
        $user->save();

        if ($user->trashed()) {
            $user->restore();
        }

        AuditService::log(
            action: $ada ? 'demo_distributor_reset' : 'demo_distributor_create',
            targetType: 'user',
            targetId: $user->id,
            after: ['username' => $username, 'role' => User::ROLE_DISTRIBUTOR],
            targetUserId: $user->id,
            targetEmail: $email,
        );

        $this->info(($ada ? 'Password akun demo diacak ulang: ' : 'Akun demo dibuat: ')."\"{$username}\"");
        $this->newLine();
        $this->warn('Password acak (muncul SEKALI, tidak disimpan di mana pun):');
        $this->line("  <options=bold>{$password}</>");
        $this->line('  Tak perlu dicatat: pakai tombol "Masuk sebagai" di Kelola Anggota — tanpa password.');

        $this->petunjuk($user);

        return self::SUCCESS;
    }

    private function petunjuk(User $user): void
    {
        $this->newLine();
        $this->line("<options=bold>{$user->fullname}</> · {$user->username} · {$user->email} · {$user->role}");
        $this->newLine();
        $this->line('Cara pakai: Kelola Anggota → cari baris ini → tombol <fg=cyan>Masuk sebagai</>.');
        $this->newLine();
        $this->warn('Akun demo aman, stok HQ TIDAK. Menyelesaikan PO dari akun ini memotong stok pusat');
        $this->warn('sungguhan — demonya palsu, potongan stoknya nyata. Bereskan dengan: artisan po:purge <nomor-po>');
    }
}
