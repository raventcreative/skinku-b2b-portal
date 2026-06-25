<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * DEVELOPMENT-ONLY demo data (products + sample partners/admin/gudang).
 * Never run automatically in production — call explicitly:
 *   php artisan db:seed --class=Database\\Seeders\\DevDataSeeder
 */
class DevDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('DevDataSeeder dilewati di environment production.');

            return;
        }

        $demoPassword = Hash::make('password123');

        $partners = [
            ['fullname' => 'Admin Operasional', 'username' => 'admin', 'email' => 'admin@skinku.id', 'role' => User::ROLE_ADMIN, 'company' => 'SKINKU HQ', 'region' => 'Jakarta'],
            ['fullname' => 'Staf Gudang', 'username' => 'gudang', 'email' => 'gudang@skinku.id', 'role' => User::ROLE_GUDANG, 'company' => 'Gudang Pusat', 'region' => 'Jakarta'],
            ['fullname' => 'Distributor Bali', 'username' => 'dist_bali', 'email' => 'dist.bali@skinku.id', 'role' => User::ROLE_DISTRIBUTOR, 'company' => 'CV Bali Cantik', 'region' => 'Bali'],
            ['fullname' => 'Distributor Jakarta', 'username' => 'dist_jkt', 'email' => 'dist.jkt@skinku.id', 'role' => User::ROLE_DISTRIBUTOR, 'company' => 'PT Jaya Kosmetik', 'region' => 'Jakarta'],
            ['fullname' => 'Reseller Santi', 'username' => 'res_santi', 'email' => 'santi@skinku.id', 'role' => User::ROLE_RESELLER, 'company' => 'Santi Beauty', 'region' => 'Bandung'],
        ];

        foreach ($partners as $p) {
            User::firstOrCreate(
                ['username' => $p['username']],
                [
                    'name' => $p['fullname'],
                    'fullname' => $p['fullname'],
                    'email' => $p['email'],
                    'password' => $demoPassword,
                    'role' => $p['role'],
                    'company_name' => $p['company'],
                    'region' => $p['region'],
                    'status' => User::STATUS_ACTIVE,
                ]
            );
        }

        $products = [
            ['Amino Body Wash', 'SKN-ABW-01', 'Sabun Cair', 45000, 55000, 75000, 28000, 500],
            ['Brightening Body Serum', 'SKN-BBS-02', 'Serum', 80000, 95000, 135000, 50000, 300],
            ['Glow Body Lotion', 'SKN-GBL-03', 'Lotion', 38000, 47000, 65000, 22000, 420],
            ['Charcoal Bar Soap', 'SKN-CBS-04', 'Sabun Batang', 15000, 20000, 30000, 9000, 800],
            ['Hydra Mist Spray', 'SKN-HMS-05', 'Mist', 33000, 42000, 60000, 19000, 260],
        ];

        foreach ($products as [$name, $sku, $cat, $dist, $res, $ret, $cogs, $stock]) {
            Product::firstOrCreate(
                ['sku' => $sku],
                [
                    'name' => $name,
                    'category' => $cat,
                    'price_distributor' => $dist,
                    'price_reseller' => $res,
                    'price_retail' => $ret,
                    'cogs' => $cogs,
                    'hq_stock' => $stock,
                    'status' => Product::STATUS_ACTIVE,
                    'description' => "Produk demo: {$name}.",
                ]
            );
        }

        // Sample Purchase Orders so dashboard & reports aren't empty.
        try {
            $svc = app(PurchaseOrderService::class);
            $distBali = User::where('username', 'dist_bali')->first();
            $distJkt = User::where('username', 'dist_jkt')->first();
            $resSanti = User::where('username', 'res_santi')->first();
            $prodA = Product::where('sku', 'SKN-ABW-01')->first();
            $prodB = Product::where('sku', 'SKN-BBS-02')->first();

            if ($distBali && $prodA && $prodB && PurchaseOrder::count() === 0) {
                // 1) Completed PO — runs full inventory transaction (populates stock + reports).
                $po1 = $svc->createForPartner($distBali, [
                    ['product_id' => $prodA->id, 'qty' => 20],
                    ['product_id' => $prodB->id, 'qty' => 10],
                ], $distBali->address, 'Contoh PO selesai');
                $svc->setShipping($po1, 25000);
                $po1->update(['payment_status' => PurchaseOrder::PAYMENT_PAID, 'paid_at' => now()]);
                foreach (['approved', 'processing', 'shipped', 'completed'] as $st) {
                    $svc->updateStatus($po1->fresh(), $st);
                }

                // 2) Pending, ongkir set, waiting for payment.
                $po2 = $svc->createForPartner($distJkt, [['product_id' => $prodA->id, 'qty' => 15]], $distJkt->address, 'Contoh PO menunggu pembayaran');
                $svc->setShipping($po2, 18000);

                // 3) Brand-new pending PO from reseller.
                $svc->createForPartner($resSanti, [['product_id' => $prodB->id, 'qty' => 5]], $resSanti->address, 'Contoh PO baru');
            }
        } catch (\Throwable $e) {
            $this->command?->warn('Seeding PO contoh dilewati: '.$e->getMessage());
        }

        $this->command?->info('Demo data (5 produk + 5 user + 3 PO contoh) berhasil dibuat. Password demo: password123');
    }
}
