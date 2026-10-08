<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use App\Services\Accounting\ChartOfAccounts;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Isi awal: satu akun admin + klien contoh (brand sendiri & klien eksternal),
     * masing-masing sudah dapat COA standar. Idempoten — aman dijalankan ulang.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('SEED_ADMIN_EMAIL', 'admin@jurnal.test')],
            [
                'name' => env('SEED_ADMIN_NAME', 'Admin'),
                'password' => env('SEED_ADMIN_PASSWORD', 'password123'),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
            ],
        );

        $coa = app(ChartOfAccounts::class);

        foreach ([
            ['name' => 'SKINKU', 'type' => Client::TYPE_INTERNAL, 'notes' => 'Brand body care. Fee marketplace TikTok/Shopee ke akun 6108.'],
            ['name' => 'Klien Contoh', 'type' => Client::TYPE_EXTERNAL, 'notes' => 'Contoh klien jasa pembukuan — hapus kalau tidak dipakai.'],
        ] as $attributes) {
            $client = Client::firstOrCreate(
                ['slug' => \Illuminate\Support\Str::slug($attributes['name'])],
                $attributes + ['is_active' => true],
            );
            $coa->seedFor($client);
        }

        $this->command?->info("Admin: {$admin->email}");
    }
}
