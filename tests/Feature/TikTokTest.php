<?php

namespace Tests\Feature;

use App\Models\TiktokConnection;
use App\Models\User;
use App\Services\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => "U{$n}", 'fullname' => "U{$n}", 'username' => "{$role}{$n}",
            'email' => "{$role}{$n}@skinku.test", 'password' => Hash::make('secret123'),
            'role' => $role, 'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function configureTikTok(): void
    {
        config()->set('services.tiktok.app_key', 'testkey');
        config()->set('services.tiktok.app_secret', 'testsecret');
        config()->set('services.tiktok.service_id', '123');
    }

    public function test_signature_is_stable_order_independent_and_excludes_token(): void
    {
        $this->configureTikTok();
        $c = app(TikTokClient::class);

        $a = $c->sign('/order/202309/orders/search', ['timestamp' => '100', 'app_key' => 'testkey'], '{"page_size":20}');
        $b = $c->sign('/order/202309/orders/search', ['app_key' => 'testkey', 'timestamp' => '100'], '{"page_size":20}');
        $this->assertSame($a, $b);                    // urutan param tidak ngaruh (di-sort)
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a); // HMAC-SHA256 hex

        // sign & access_token dikecualikan dari perhitungan
        $withNoise = $c->sign('/x', ['app_key' => 'k', 'sign' => 'zzz', 'access_token' => 'ttt'], '');
        $clean = $c->sign('/x', ['app_key' => 'k'], '');
        $this->assertSame($clean, $withNoise);

        // body & param ikut mengubah tanda tangan
        $this->assertNotSame($a, $c->sign('/order/202309/orders/search', ['timestamp' => '101', 'app_key' => 'testkey'], '{"page_size":20}'));
    }

    public function test_callback_exchanges_code_and_stores_connection(): void
    {
        $this->configureTikTok();
        Http::fake([
            '*/api/v2/token/get*' => Http::response(['code' => 0, 'message' => 'success', 'data' => [
                'access_token' => 'acc-123', 'refresh_token' => 'ref-123',
                // refresh bisa jauh ke depan (mis. epoch th 2125) — harus muat di kolom datetime
                'access_token_expire_in' => time() + 604800, 'refresh_token_expire_in' => 4_900_000_000,
                'seller_name' => 'SKINKU',
            ]]),
            '*/authorization/202309/shops*' => Http::response(['code' => 0, 'data' => [
                'shops' => [['id' => 'SHOP1', 'cipher' => 'CIPHER1', 'name' => 'SKINKU Store', 'region' => 'ID']],
            ]]),
        ]);

        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/tiktok/callback?code=authcode123')
            ->assertRedirect(route('tiktok.index'));

        $this->assertDatabaseHas('tiktok_connections', [
            'shop_id' => 'SHOP1', 'shop_cipher' => 'CIPHER1', 'shop_name' => 'SKINKU Store', 'region' => 'ID',
        ]);
        $conn = TiktokConnection::first();
        $this->assertNotNull($conn->access_expires_at);
    }

    public function test_callback_without_code_shows_error(): void
    {
        $this->configureTikTok();
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/tiktok/callback')
            ->assertRedirect(route('tiktok.index'))->assertSessionHas('error');
        $this->assertDatabaseCount('tiktok_connections', 0);
    }

    public function test_search_orders_sends_json_object_body(): void
    {
        $this->configureTikTok();
        Http::fake(['*/order/202309/orders/search*' => Http::response(['code' => 0, 'data' => ['orders' => []]])]);
        TiktokConnection::create([
            'shop_id' => 'S', 'shop_cipher' => 'C', 'access_token' => 'acc', 'refresh_token' => 'ref',
            'access_expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->post('/tiktok/sync-orders')->assertRedirect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/order/202309/orders/search')
                && $request->body() === '{}'                 // object {}, BUKAN array []
                && $request->hasHeader('x-tts-access-token');
        });
    }

    public function test_index_renders_and_reseller_forbidden(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/tiktok')->assertOk();
        $this->actingAs($this->user(User::ROLE_RESELLER))->get('/tiktok')->assertForbidden();
    }
}
