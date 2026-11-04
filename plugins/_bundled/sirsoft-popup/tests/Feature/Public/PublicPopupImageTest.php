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

        // 긴 캐시는 운영 환경 정책이다(코어 파일 서빙 헬퍼 — 개발 환경은 no-cache)
        $this->app->detectEnvironment(fn () => 'production');
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

    /**
     * 공개 이미지는 코어 파일 서빙 계약을 따른다 — 운영 환경에서 해시 주소라 1년 불변 캐시, 검증자(ETag)로 304 재검증하고
     * 304 도 200 과 같은 캐시 정책과 nosniff 를 싣는다. 개발 환경은 코어와 같이 캐시하지 않는다(no-cache).
     *
     * @scenario surface=image_follows_core_file_serving_contract
     *
     * @effects file_serving_contract_matches_core
     *
     * @return void 반환값 없음
     */
    public function test_image_follows_core_file_serving_contract(): void
    {
        $url = $this->api('/images/'.$this->publicHash());

        $this->assertContains('no-cache', $this->directives($this->get($url)->assertOk()->headers->get('Cache-Control')));

        $this->app->detectEnvironment(fn () => 'production');
        $first = $this->get($url)->assertOk();
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($first->headers->get('Cache-Control')));
        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        $again = $this->get($url, ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertEqualsCanonicalizing(['public', 'max-age=31536000', 'immutable'], $this->directives($again->headers->get('Cache-Control')));
        $this->assertSame('nosniff', $again->headers->get('X-Content-Type-Options'));
        $this->assertSame($etag, $again->headers->get('ETag'));
    }

    /**
     * 공개 이미지 하나를 만들고 그 해시를 반환합니다.
     *
     * @return string 해시
     */
    private function publicHash(): string
    {
        $id = $this->createPopupViaApi($this->fullAdmin())->assertCreated()->json('data.id');

        return (string) PopupImage::query()->where('popup_id', $id)->value('hash');
    }

    /**
     * Cache-Control 값을 지시어 목록으로 나눕니다(순서는 응답 종류마다 다르다).
     *
     * @param  string|null  $header  Cache-Control 값
     * @return array<int, string> 지시어 목록
     */
    private function directives(?string $header): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $header))));
    }
}
