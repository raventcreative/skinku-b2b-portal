<?php

namespace App\Services;

/**
 * Ubah 1 pesan Shopee (dari get_message API) → array ternormalisasi untuk
 * EcomChatService::syncIncoming. Bentuk `content` per tipe DIVERIFIKASI dari data
 * toko asli (Fase 0, 2026-09-18):
 *   text    → {"text":"..."}
 *   image   → {"url":"https://img.sp.mms…","thumb_url":...}
 *   item    → {"shop_id":...,"item_id":...}          (hanya id → di-enrich di controller)
 *   sticker → {"sticker_id":...,"image_url":...}     → other "[Stiker]"
 *   unknown → {"text":"unsupported msg"}             → other
 * Pengirim = toko kita bila from_shop_id === shop_id kita, selain itu pembeli.
 * Shopee TIDAK punya tipe pesan "order" tersendiri (order muncul via source_content).
 */
class ShopeeChatParser
{
    public function normalize(array $raw, int $ourShopId): array
    {
        $type = (string) ($raw['message_type'] ?? 'text');
        $c = is_array($raw['content'] ?? null) ? $raw['content'] : [];
        $fromShop = (int) ($raw['from_shop_id'] ?? -1);
        $sender = ($fromShop === $ourShopId) ? 'seller' : 'buyer';

        $out = [
            'conversation_id' => (string) ($raw['conversation_id'] ?? ''),
            'message_id' => (string) ($raw['message_id'] ?? ''),
            'sender' => $sender,
            'sent_at' => (int) ($raw['created_timestamp'] ?? 0),
            // buyer_id hanya bisa disimpulkan dari pesan PEMBELI (from_id-nya); untuk
            // pesan seller, pemanggil mengisi dari to_id percakapan.
            'buyer_id' => ($sender === 'buyer' && isset($raw['from_id'])) ? (string) $raw['from_id'] : null,
            'buyer_name' => null,
            'type' => 'text',
            'text' => '',
            'meta' => null,
        ];

        switch ($type) {
            case 'text':
                $out['text'] = (string) ($c['text'] ?? '');
                break;
            case 'image':
                $out['type'] = 'image';
                $out['meta'] = ['url' => (string) ($c['url'] ?? $c['thumb_url'] ?? '')];
                $out['text'] = '[Foto]';
                break;
            case 'video':
                $out['type'] = 'video';
                $out['meta'] = ['url' => (string) ($c['url'] ?? '')];
                $out['text'] = '[Video]';
                break;
            case 'item':
                $out['type'] = 'product_card';
                $out['meta'] = [
                    'product_id' => (string) ($c['item_id'] ?? ''),
                    'shop_id' => (string) ($c['shop_id'] ?? $ourShopId),
                ];
                $out['text'] = '[Produk]';
                break;
            case 'sticker':
                $out['type'] = 'other';
                $out['text'] = '[Stiker]';
                break;
            default:
                $out['type'] = 'other';
                $out['text'] = (string) ($c['text'] ?? ('[Pesan '.$type.']'));
        }

        return $out;
    }
}
