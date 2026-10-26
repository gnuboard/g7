<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Feature\Public;

use Illuminate\Support\Str;
use Plugins\Sirsoft\HeroSection\Models\BannerImage;
use Plugins\Sirsoft\HeroSection\Tests\PluginTestCase;

/**
 * 공개 배너 이미지 응답 테스트 (매니페스트 public-hero.yaml — 이미지 케이스)
 */
class PublicBannerImageTest extends PluginTestCase
{
    /**
     * 테스트 준비 — 이미지 파일은 가짜 디스크에 쓴다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImageDisks();
    }

    /**
     * 배너에 연결된 래스터 이미지는 인라인 + nosniff + 긴 캐시로 나간다.
     *
     * @scenario surface=image_streams_inline_raster
     *
     * @effects inline_media_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_streams_inline_raster(): void
    {
        $id = $this->createBannerViaApi($this->fullAdmin())->assertCreated()->json('data.id');
        $hash = BannerImage::query()->where('banner_id', $id)->value('hash');

        $response = $this->get($this->api('/images/'.$hash));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 모르는 해시와 아직 배너에 연결되지 않은 임시 이미지는 404 다.
     *
     * @scenario surface=image_unknown_hash_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_unknown_or_temp_hash_returns_404(): void
    {
        $this->get($this->api('/images/'.Str::random(64)))->assertNotFound();

        $temp = $this->uploadImage($this->fullAdmin(), 'pc', ['temp_key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $hash = BannerImage::query()->whereKey($temp)->value('hash');
        $this->get($this->api('/images/'.$hash))->assertNotFound();
    }
}
