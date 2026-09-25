<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Akun demo Content Creator (FR-03) — DEV ONLY. Terpisah dari DevDataSeeder
 * supaya bisa dijalankan di DB lokal berisi data asli tanpa ikut membuat
 * produk/PO demo:
 *   php artisan db:seed --class=Database\\Seeders\\ContentCreatorDemoSeeder
 */
class ContentCreatorDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('ContentCreatorDemoSeeder dilewati di environment production.');

            return;
        }

        User::firstOrCreate(['username' => 'creator_demo'], [
            'name' => 'Creator Demo',
            'fullname' => 'Creator Demo',
            'email' => 'creator.demo@skinku.id',
            'password' => Hash::make('password123'),
            'role' => 'content_creator',
            'status' => User::STATUS_ACTIVE,
        ]);
    }
}
