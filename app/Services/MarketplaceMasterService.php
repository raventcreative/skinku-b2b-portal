<?php

namespace App\Services;

use App\Models\MarketplaceMaster;
use App\Models\MarketplaceMasterChannel;
use App\Models\ShopeeConnection;
use App\Models\TiktokConnection;
use Carbon\Carbon;

/**
 * Engine "Produk Master E-commerce" (ala Desty): tiap unit jualan (satuan/varian/
 * bundle) = 1 master dgn stok+harga di-set langsung, tertaut ke listing TikTok/
 * Shopee, override per-channel. TERPISAH TOTAL dari stok HQ.
 */
class MarketplaceMasterService
{
    public function __construct(private ShopeeClient $shopee, private TikTokClient $tiktok) {}

    // ---- Nilai efektif per (master, channel) ----

    public function effectiveStock(MarketplaceMaster $m, string $channel): ?int
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->stock !== null) {
            return (int) $ch->stock;
        }

        return $m->base_stock !== null ? (int) $m->base_stock : null;
    }

    public function effectivePrice(MarketplaceMaster $m, string $channel): ?float
    {
        $ch = $m->channels->firstWhere('channel', $channel)
            ?? MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if ($ch && $ch->price !== null) {
            return (float) $ch->price;
        }

        return $m->base_price !== null ? (float) $m->base_price : null;
    }

    // ---- Setter Master ----

    public function setMasterStock(MarketplaceMaster $m, int $qty): void
    {
        $m->update(['base_stock' => max(0, $qty), 'seeded_at' => now()]);
    }

    public function setMasterPrice(MarketplaceMaster $m, float $price): void
    {
        $m->update(['base_price' => max(0, $price)]);
    }

    // ---- Setter override channel ----

    public function setChannelStock(MarketplaceMaster $m, string $channel, int $qty): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['stock' => max(0, $qty), 'seeded_at' => now()],
        );
    }

    public function setChannelPrice(MarketplaceMaster $m, string $channel, float $price): MarketplaceMasterChannel
    {
        return MarketplaceMasterChannel::updateOrCreate(
            ['master_id' => $m->id, 'channel' => $channel],
            ['price' => max(0, $price)],
        );
    }

    /** Kembalikan satu field channel ke "ikut Master"; hapus baris bila stock & price dua-duanya null. */
    public function ikutMaster(MarketplaceMaster $m, string $channel, string $field): void
    {
        $row = MarketplaceMasterChannel::where('master_id', $m->id)->where('channel', $channel)->first();
        if (! $row) {
            return;
        }
        $row->update([$field => null]);
        if ($row->stock === null && $row->price === null) {
            $row->delete();
        }
    }

    // ---- Helper koneksi & token (SALINAN transisi dari MarketplaceStockService) ----

    private function tiktokConn(): ?TiktokConnection
    {
        return TiktokConnection::latest('id')->first();
    }

    private function shopeeConn(): ?ShopeeConnection
    {
        return ShopeeConnection::latest('id')->first();
    }

    private function tiktokToken(TiktokConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->tiktok->refreshToken($c->refresh_token);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->tiktokExpiry($t['access_token_expire_in'] ?? null),
            'refresh_expires_at' => $this->tiktokExpiry($t['refresh_token_expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    private function tiktokExpiry(mixed $v): ?Carbon
    {
        if (! $v) {
            return null;
        }
        $v = (int) $v;

        return $v > 1_000_000_000 ? Carbon::createFromTimestamp($v) : now()->addSeconds($v);
    }

    private function shopeeToken(ShopeeConnection $c): string
    {
        if (! $c->accessExpiringSoon()) {
            return (string) $c->access_token;
        }
        $t = $this->shopee->refreshToken($c->refresh_token, $c->shop_id);
        $c->update([
            'access_token' => $t['access_token'],
            'refresh_token' => $t['refresh_token'] ?? $c->refresh_token,
            'access_expires_at' => $this->shopeeExpiry($t['expire_in'] ?? null),
        ]);

        return (string) $t['access_token'];
    }

    private function shopeeExpiry(mixed $expireIn): ?Carbon
    {
        return $expireIn ? now()->addSeconds((int) $expireIn) : null;
    }
}
