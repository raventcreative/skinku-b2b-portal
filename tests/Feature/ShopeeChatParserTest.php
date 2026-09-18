<?php

namespace Tests\Feature;

use App\Services\ShopeeChatParser;
use Tests\TestCase;

class ShopeeChatParserTest extends TestCase
{
    private int $shop = 426938728; // toko kita

    private function parse(array $raw): array
    {
        return app(ShopeeChatParser::class)->normalize($raw, $this->shop);
    }

    public function test_teks_pembeli(): void
    {
        // from_shop_id != toko kita → pembeli.
        $n = $this->parse([
            'message_id' => 'M1', 'conversation_id' => 'C1', 'from_id' => 949588930,
            'from_shop_id' => 949477559, 'message_type' => 'text', 'content' => ['text' => 'Siap dtunggu ka'],
            'created_timestamp' => 1723887490,
        ]);
        $this->assertSame('text', $n['type']);
        $this->assertSame('Siap dtunggu ka', $n['text']);
        $this->assertSame('buyer', $n['sender']);
        $this->assertSame('949588930', $n['buyer_id']);
    }

    public function test_teks_dari_toko_terdeteksi_seller(): void
    {
        // from_shop_id === toko kita → seller (jangan dianggap pembeli).
        $n = $this->parse([
            'message_id' => 'M2', 'conversation_id' => 'C1', 'from_id' => 426958305,
            'from_shop_id' => 426938728, 'message_type' => 'text', 'content' => ['text' => 'oke'],
        ]);
        $this->assertSame('seller', $n['sender']);
        $this->assertNull($n['buyer_id']);
    }

    public function test_gambar(): void
    {
        $n = $this->parse([
            'message_id' => 'M3', 'conversation_id' => 'C2', 'from_shop_id' => 388607232,
            'message_type' => 'image', 'content' => ['url' => 'https://img.sp.mms.shopee.sg/id-x', 'thumb_url' => ''],
        ]);
        $this->assertSame('image', $n['type']);
        $this->assertSame('https://img.sp.mms.shopee.sg/id-x', $n['meta']['url']);
    }

    public function test_item_jadi_kartu_produk(): void
    {
        $n = $this->parse([
            'message_id' => 'M4', 'conversation_id' => 'C3', 'from_shop_id' => 426938728,
            'message_type' => 'item', 'content' => ['shop_id' => 426938728, 'item_id' => 22668447677],
        ]);
        $this->assertSame('product_card', $n['type']);
        $this->assertSame('22668447677', $n['meta']['product_id']);
        $this->assertSame('426938728', $n['meta']['shop_id']);
    }

    public function test_sticker_dan_unknown_jadi_other(): void
    {
        $stk = $this->parse(['message_id' => 'M5', 'conversation_id' => 'C4', 'message_type' => 'sticker', 'content' => ['sticker_id' => '0001']]);
        $this->assertSame('other', $stk['type']);
        $this->assertSame('[Stiker]', $stk['text']);

        $unk = $this->parse(['message_id' => 'M6', 'conversation_id' => 'C4', 'message_type' => 'unknown', 'content' => ['text' => 'unsupported msg']]);
        $this->assertSame('other', $unk['type']);
        $this->assertSame('unsupported msg', $unk['text']);
    }
}
