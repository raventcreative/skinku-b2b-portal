<?php

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrasi data lama → master (hanya bila tabel lama ada & berisi).
        if (Schema::hasTable('marketplace_stocks')) {
            foreach (DB::table('marketplace_stocks')->get() as $old) {
                $product = DB::table('products')->where('id', $old->product_id)->first();
                if (! $product) {
                    continue;
                }
                $m = MarketplaceMaster::firstOrCreate(
                    ['master_sku' => $product->sku],
                    ['name' => $product->name, 'product_id' => $product->id],
                );
                $m->update(['base_stock' => $old->quantity, 'seeded_at' => $old->seeded_at]);
                // Tautkan listing yg seller_sku-nya == product.sku bila belum termaster.
                MarketplaceListing::where('seller_sku', $product->sku)->whereNull('master_id')->update(['master_id' => $m->id]);
            }
        }
        if (Schema::hasTable('marketplace_channel_overrides')) {
            foreach (DB::table('marketplace_channel_overrides')->get() as $ov) {
                $product = DB::table('products')->where('id', $ov->product_id)->first();
                if (! $product) {
                    continue;
                }
                $m = MarketplaceMaster::where('master_sku', $product->sku)->first();
                if (! $m) {
                    continue;
                }
                MarketplaceMasterChannel::updateOrCreate(
                    ['master_id' => $m->id, 'channel' => $ov->channel],
                    ['stock' => $ov->quantity, 'seeded_at' => $ov->seeded_at],
                );
            }
        }

        Schema::dropIfExists('marketplace_channel_overrides');
        Schema::dropIfExists('marketplace_stocks');
    }

    public function down(): void
    {
        // Rekreasi skema lama (tanpa data) supaya rollback tak fatal.
        if (! Schema::hasTable('marketplace_stocks')) {
            Schema::create('marketplace_stocks', function ($t) {
                $t->id();
                $t->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
                $t->integer('quantity')->default(0);
                $t->timestamp('seeded_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('marketplace_channel_overrides')) {
            Schema::create('marketplace_channel_overrides', function ($t) {
                $t->id();
                $t->foreignId('product_id')->constrained()->cascadeOnDelete();
                $t->string('channel', 16);
                $t->integer('quantity')->default(0);
                $t->timestamp('seeded_at')->nullable();
                $t->timestamps();
                $t->unique(['product_id', 'channel']);
            });
        }
    }
};
