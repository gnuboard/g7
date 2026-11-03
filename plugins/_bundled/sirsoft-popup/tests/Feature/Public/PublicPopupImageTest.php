<?php

namespace Plugins\Sirsoft\Popup\Tests\Feature\Public;

use Illuminate\Support\Str;
use Plugins\Sirsoft\Popup\Models\PopupImage;
use Plugins\Sirsoft\Popup\Tests\PluginTestCase;

/**
 * 공개 팝업 이미지 응답 테스트 (매니페스트 public-popups.yaml — 이미지 케이스)
 */
class PublicPopupImageTest extends PluginTestCase
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
     * 팝업에 연결된 래스터 이미지는 인라인 + nosniff + 긴 캐시로 나간다.
     *
     * @scenario surface=image_streams_inline_raster
     *
     * @effects inline_media_policy_applied
     *
     * @return void 반환값 없음
     */
    public function test_streams_inline_raster(): void
    {
        $id = $this->createPopupViaApi($this->fullAdmin())->assertCreated()->json('data.id');
        $hash = PopupImage::query()->where('popup_id', $id)->value('hash');

        $response = $this->get($this->api('/images/'.$hash));

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * 모르는 해시는 404 다.
     *
     * @scenario surface=image_unknown_hash_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_unknown_hash_returns_404(): void
    {
        $this->get($this->api('/images/'.Str::random(64)))->assertNotFound();
    }

    /**
     * 아직 팝업에 붙지 않은 임시 이미지도 해시로 내보낸다 — 등록·수정 화면 업로더 썸네일과 미리보기가 저장 전에 그린다.
     *
     * @scenario surface=image_temp_hash_streams_for_preview
     *
     * @return void 반환값 없음
     */
    public function test_temp_hash_streams_for_preview(): void
    {
        $temp = $this->uploadImage($this->fullAdmin(), 'pc', (string) Str::uuid())->assertCreated()->json('data');

        $this->get($temp['url'])->assertOk();
    }
}
