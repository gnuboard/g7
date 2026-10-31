<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Http\Middleware\GzipEncodeResponse;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Repositories\Contracts\PageAttachmentRepositoryInterface;
use Modules\Sirsoft\Page\Repositories\Contracts\PageRepositoryInterface;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 페이지 첨부 미리보기의 조건부 요청 계약 (13차 G3 라운드)
 *
 * 12차가 `ETag`·`Last-Modified` 를 **내보내기**는 했지만 돌려받은 `If-None-Match` /
 * `If-Modified-Since` 를 판정하는 곳이 없어 재검증이 영원히 전문 200 이었다. 본문 `<img>`
 * 로 실려 건수가 유계가 아닌 통로라 페이지 조회마다 손해가 누적되는데, 오류도 로그도
 * 없이 응답 헤더로만 드러난다. 실제 HTTP 왕복으로 고정한다 — 라우터가 304 응답의
 * `Last-Modified` 를 벗기므로 컨트롤러 반환값만 보면 「붙였다」와 「나갔다」가 구분되지 않는다.
 *
 * 스토리지는 모킹한다(`preview()` 가 만든 스트림). 검증자 소스(`previewValidators()`)는
 * 실제 구현을 지난다 — 서비스가 200 에 실은 ETag 와 코어 헬퍼가 판정에 쓰는 ETag 가 같은
 * 소스에서 나오는지가 이 계약의 핵심이다.
 */
class PublicPageAttachmentConditionalRequestTest extends FeatureTestCase
{
    private const BASE = '/api/modules/sirsoft-page/pages/attachment';

    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'attach-cond-%')->forceDelete();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 발행 페이지의 이미지 첨부와, 스트림만 모킹한 서비스를 만든다.
     *
     * @return PageAttachment 생성된 첨부
     */
    private function makePublishedImageAttachment(): PageAttachment
    {
        $page = Page::factory()->state(['slug' => 'attach-cond-'.uniqid()])->published()->create();
        $attachment = PageAttachment::factory()->image()->create(['page_id' => $page->id]);

        $service = Mockery::mock(PageAttachmentService::class)->makePartial();
        $service->shouldReceive('getByHash')->andReturn($attachment);
        $service->shouldReceive('preview')->andReturnUsing(
            fn () => new StreamedResponse(fn () => print ('IMAGE-BYTES'), 200, ['Content-Type' => 'image/png'])
        );
        $this->app->instance(PageAttachmentService::class, $service);

        return $attachment;
    }

    /**
     * 일치하는 `If-None-Match` 는 본문 없는 304 이고, 200 의 캐시 헤더를 함께 싣는다.
     *
     * @scenario serving_type=preview, published=published, viewer=guest
     *
     * @effects preview_matching_etag_returns_304_with_the_cache_headers_of_200
     */
    public function test_matching_if_none_match_returns_304_with_the_cache_headers_of_200(): void
    {
        $attachment = $this->makePublishedImageAttachment();
        $url = self::BASE.'/'.$attachment->hash.'/preview';

        $first = $this->get($url);
        $first->assertOk();

        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
        $this->assertSame(
            gmdate('D, d M Y H:i:s', $attachment->updated_at->getTimestamp()).' GMT',
            $first->headers->get('Last-Modified'),
        );

        $second = $this->get($url, ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent(), '304 에 본문이 실렸다');
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertSame($first->headers->get('Cache-Control'), $second->headers->get('Cache-Control'), '304 가 200 과 다른 캐시 정책을 광고한다');
        $this->assertSame($first->headers->get('Last-Modified'), $second->headers->get('Last-Modified'), '라우터가 벗긴 Last-Modified 가 되살아나지 않았다');
        $this->assertNotEmpty($second->headers->get('Expires'));
    }

    /**
     * `If-Modified-Since` 만 보내는 클라이언트도 304 로 재검증된다.
     *
     * @scenario serving_type=preview, published=published, viewer=guest
     *
     * @effects preview_if_modified_since_alone_returns_304
     */
    public function test_if_modified_since_alone_returns_304_when_row_is_not_newer(): void
    {
        $attachment = $this->makePublishedImageAttachment();

        $since = gmdate('D, d M Y H:i:s', $attachment->updated_at->getTimestamp()).' GMT';

        $this->get(self::BASE.'/'.$attachment->hash.'/preview', ['If-Modified-Since' => $since])
            ->assertStatus(304);
    }

    /**
     * 검증자가 어긋나면 전문 200 이다 — 304 판정이 「언제나 304」로 기울지 않았는지의 반대 축.
     *
     * @scenario serving_type=preview, published=published, viewer=guest
     *
     * @effects preview_non_matching_validator_returns_full_200
     */
    public function test_non_matching_validator_returns_full_200(): void
    {
        $attachment = $this->makePublishedImageAttachment();

        $response = $this->get(self::BASE.'/'.$attachment->hash.'/preview', ['If-None-Match' => '"stale"']);

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    /**
     * 발행 페이지의 문서 첨부와, 다운로드 스트림만 모킹한 서비스를 만든다.
     *
     * `getDownloadInfo()` 는 실제 구현을 지난다 — 검증자 소스가 미리보기와 같은 행 메타에서
     * 나오는지가 계약의 핵심이다. 스토리지 `download()` 만 partial 로 막는다.
     *
     * @return PageAttachment 생성된 첨부
     */
    private function makePublishedDocumentAttachment(): PageAttachment
    {
        $page = Page::factory()->state(['slug' => 'attach-cond-'.uniqid()])->published()->create();
        $attachment = PageAttachment::factory()->create([
            'page_id' => $page->id,
            'original_filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $storage = Mockery::mock(StorageInterface::class);
        $storage->shouldReceive('getDisk')->andReturn($attachment->disk);
        $storage->shouldReceive('exists')->andReturn(true);
        $storage->shouldReceive('download')->andReturnUsing(
            fn () => new StreamedResponse(fn () => print ('PDF-BYTES'), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename=report.pdf',
            ])
        );

        $service = Mockery::mock(PageAttachmentService::class, [
            app(PageAttachmentRepositoryInterface::class),
            $storage,
            app(PageRepositoryInterface::class),
        ])->makePartial();
        $service->shouldReceive('getByHash')->andReturn($attachment);
        $this->app->instance(PageAttachmentService::class, $service);

        return $attachment;
    }

    /**
     * 다운로드 통로도 코어 헬퍼를 지난다 — 검증자·304·`nosniff`·압축 제외 선언 (15차 G3 라운드).
     *
     * 종전에는 `download()` 가 스토리지 스트림을 그대로 돌려줘 이 통로만 ETag 도 304 도
     * 없었다 — 같은 첨부가 이미지면 재검증되고 문서면 매번 전문을 받아 갔다(코어 첨부가
     * 14차에 고친 것과 같은 비대칭). `Content-Disposition: attachment` 는 그대로 승계된다.
     *
     * @scenario serving_type=download, published=published, viewer=guest
     *
     * @effects download_matching_etag_returns_304_with_the_cache_headers_of_200,
     *          file_serving_response_declares_nosniff
     */
    public function test_download_revalidates_through_the_core_helper(): void
    {
        $attachment = $this->makePublishedDocumentAttachment();
        $url = self::BASE.'/'.$attachment->hash;

        $first = $this->get($url);
        $first->assertOk();

        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag, '다운로드 응답에 검증자가 없다');
        $this->assertSame(
            gmdate('D, d M Y H:i:s', $attachment->updated_at->getTimestamp()).' GMT',
            $first->headers->get('Last-Modified'),
        );
        $this->assertStringStartsWith('attachment', (string) $first->headers->get('Content-Disposition'), '다운로드는 attachment disposition 을 유지한다');
        $this->assertSame('application/pdf', $first->headers->get('Content-Type'));
        $first->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringNotContainsString('immutable', (string) $first->headers->get('Cache-Control'));
        $this->assertTrue(
            $this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE) === true,
            '사용자 업로드물 통로는 압축 제외를 선언해야 한다'
        );

        $second = $this->get($url, ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent(), '304 에 본문이 실렸다');
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertSame($first->headers->get('Cache-Control'), $second->headers->get('Cache-Control'));
        $this->assertSame($first->headers->get('Last-Modified'), $second->headers->get('Last-Modified'));
        $second->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
