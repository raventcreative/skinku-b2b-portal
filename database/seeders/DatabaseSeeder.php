<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Default seeding only provisions the initial super admin (from .env).
     * Demo data must be requested explicitly via DevDataSeeder so production
     * never gets mock partners/products.
     */
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);
        // Chart of Account is master/reference data (not demo) — safe & idempotent.
        $this->call(ChartOfAccountSeeder::class);
    }
}
