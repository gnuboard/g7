<?php

namespace Plugins\Sirsoft\Ckeditor5\Tests\Feature\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Tests\PluginTestCase;

/**
 * 본문 이미지 서빙의 조건부 요청 계약 (13차 G3 라운드)
 *
 * 12차가 `ETag`·`Last-Modified` 를 **내보내기**는 했지만 돌려받은 `If-None-Match` /
 * `If-Modified-Since` 를 판정하는 곳이 없어 재검증이 영원히 전문 200 이었다 — 검증자를
 * 싣지 않던 시절과 대역폭이 같다. 오류도 로그도 없이 응답 헤더로만 드러나므로 실제
 * HTTP 왕복으로 고정한다. 판정은 코어 단일 지점(`streamedFileResponse()`)이 하고, 여기서는
 * 그 지점에 **배선되었는가**와 304 가 200 의 캐시 헤더를 함께 싣는가를 잰다.
 *
 * 라우터를 지나야 한다 — Laravel 은 304 응답에 `setNotModified()` 를 다시 불러
 * `Last-Modified` 를 벗기므로, 컨트롤러 반환값만 보면 「붙였다」와 「나갔다」가 구분되지
 * 않는다.
 */
class ImageServeConditionalRequestTest extends PluginTestCase
{
    private const BASE = '/api/plugins/sirsoft-ckeditor5/images/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('plugins');
    }

    /**
     * 파일과 행을 함께 만든다.
     *
     * @return Ckeditor5ImageUpload 생성된 행
     */
    private function makeImage(): Ckeditor5ImageUpload
    {
        Storage::disk('plugins')->put('sirsoft-ckeditor5/images/2026/04/06/cond.jpg', 'fake-image-content');

        return Ckeditor5ImageUpload::create([
            'original_name' => 'cond.jpg',
            'file_path' => 'images/2026/04/06/cond.jpg',
            'storage_disk' => 'plugins',
            'file_size' => 18,
            'mime_type' => 'image/jpeg',
            'uploaded_by' => null,
        ]);
    }

    /**
     * 일치하는 `If-None-Match` 는 본문 없는 304 이고, 200 의 캐시 헤더를 함께 싣는다.
     *
     * @scenario conditional_request=if_none_match
     *
     * @effects image_serve_matching_etag_returns_304_with_the_cache_headers_of_200
     */
    public function test_matching_if_none_match_returns_304_with_the_cache_headers_of_200(): void
    {
        $image = $this->makeImage();

        $first = $this->get(self::BASE.$image->hash);
        $first->assertOk();

        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag, 'ETag 는 따옴표로 감싼 entity-tag 여야 한다');
        $this->assertNotEmpty($first->headers->get('Last-Modified'));
        $this->assertNotEmpty($first->headers->get('Cache-Control'));
        $this->assertNotEmpty($first->headers->get('Expires'));

        $second = $this->get(self::BASE.$image->hash, ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent(), '304 에 본문이 실렸다');
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertSame($first->headers->get('Cache-Control'), $second->headers->get('Cache-Control'), '304 가 200 과 다른 캐시 정책을 광고한다');
        $this->assertSame($first->headers->get('Last-Modified'), $second->headers->get('Last-Modified'), '라우터가 벗긴 Last-Modified 가 되살아나지 않았다');
        $this->assertNotEmpty($second->headers->get('Expires'));
        $this->assertNull($second->headers->get('Vary'), '이미지는 압축 대상이 아니라 200 에 Vary 가 없다 — 304 에만 붙이면 비대칭이다');
    }

    /**
     * `If-Modified-Since` 만 보내는 클라이언트도 304 로 재검증된다.
     *
     * @scenario conditional_request=if_modified_since
     *
     * @effects image_serve_if_modified_since_alone_returns_304
     */
    public function test_if_modified_since_alone_returns_304_when_row_is_not_newer(): void
    {
        $image = $this->makeImage();

        $since = gmdate('D, d M Y H:i:s', $image->updated_at->getTimestamp()).' GMT';

        $this->get(self::BASE.$image->hash, ['If-Modified-Since' => $since])
            ->assertStatus(304);
    }

    /**
     * 검증자가 어긋나면 전문 200 이다 — 304 판정이 「언제나 304」로 기울지 않았는지의 반대 축.
     *
     * @scenario conditional_request=non_matching
     *
     * @effects image_serve_non_matching_validator_returns_full_200
     */
    public function test_non_matching_validator_returns_full_200(): void
    {
        $image = $this->makeImage();

        $response = $this->get(self::BASE.$image->hash, ['If-None-Match' => '"not-the-current-etag"']);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');
        $this->assertNotEmpty($response->headers->get('ETag'));
    }
}
