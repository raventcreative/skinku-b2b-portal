<?php

namespace Tests\Feature\MarketplaceMaster;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MasterActionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'a', 'fullname' => 'A', 'username' => 'a'.uniqid(), 'email' => uniqid().'@t.test', 'password' => Hash::make('secret123'), 'role' => User::ROLE_SUPER_ADMIN, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_toggle_bundle(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a', 'is_bundle' => false]);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.bundle', $m))->assertRedirect();
        $this->assertTrue($m->refresh()->is_bundle);
        $this->actingAs($this->admin())->post(route('marketplace-stock.master.bundle', $m))->assertRedirect();
        $this->assertFalse($m->refresh()->is_bundle);
    }

    public function test_gabung_master_pindah_listing_dan_hapus_sumber(): void
    {
        $src = MarketplaceMaster::create(['master_sku' => 'SRC', 'name' => 'Src', 'name_key' => 'src']);
        $tgt = MarketplaceMaster::create(['master_sku' => 'TGT', 'name' => 'Tgt', 'name_key' => 'tgt']);
        $l = MarketplaceListing::create(['channel' => 'shopee', 'seller_sku' => 'SRC', 'item_id' => 'P1', 'master_id' => $src->id]);

        $this->actingAs($this->admin())->post(route('marketplace-stock.master.gabung', $src), ['target_master_id' => $tgt->id])->assertRedirect()->assertSessionHas('status');

        $this->assertSame($tgt->id, $l->refresh()->master_id);
        $this->assertNull(MarketplaceMaster::find($src->id)); // sumber terhapus
    }

    public function test_upload_foto(): void
    {
        Storage::fake('public');
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);

        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.master.foto', $m), ['foto' => UploadedFile::fake()->image('x.jpg', 600, 600)])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertNotNull($m->refresh()->imageUrl()); // foto upload terpasang → imageUrl() ada
    }

    public function test_upload_foto_tolak_bukan_gambar(): void
    {
        $m = MarketplaceMaster::create(['master_sku' => 'A', 'name' => 'A', 'name_key' => 'a']);
        $this->actingAs($this->admin())
            ->post(route('marketplace-stock.master.foto', $m), ['foto' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('foto');
    }
}
