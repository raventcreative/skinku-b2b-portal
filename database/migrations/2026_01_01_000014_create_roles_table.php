<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();   // slug, e.g. affiliator
            $table->string('label');             // display name
            $table->boolean('is_system')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $system = [
            ['super_admin', 'Super Admin', 0],
            ['admin', 'Admin', 1],
            ['gudang', 'Gudang', 2],
            ['distributor', 'Distributor', 3],
            ['reseller', 'Reseller', 4],
        ];
        foreach ($system as [$name, $label, $order]) {
            DB::table('roles')->insert([
                'name' => $name, 'label' => $label, 'is_system' => true,
                'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
