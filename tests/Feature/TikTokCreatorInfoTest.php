<?php

namespace Tests\Feature;

use App\Services\Social\TikTokContentClient;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokCreatorInfoTest extends TestCase
{
    public function test_creator_info_sends_empty_json_post_with_required_content_type(): void
    {
        Http::fake(['open.tiktokapis.com/*' => Http::response([
            'data' => ['privacy_level_options' => ['SELF_ONLY']],
            'error' => ['code' => 'ok', 'message' => ''],
        ])]);

        $result = app(TikTokContentClient::class)->creatorInfo('access-token');

        $this->assertSame(['privacy_level_options' => ['SELF_ONLY']], $result);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/post/publish/creator_info/query/')
            && $request->hasHeader('Content-Type', 'application/json; charset=UTF-8')
            && $request->body() === '');
    }
}
