<?php

namespace Tests\Feature\Attachment;

use App\Extension\HookManager;
use App\Http\Middleware\GzipEncodeResponse;
use App\Http\Responses\NotModifiedResponse;
use App\Models\Attachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 코어 비이미지 첨부 다운로드의 조건부 요청 계약 테스트 (14차 G3 라운드).
 *
 * 같은 라우트(`GET /api/attachment/{hash}`)가 이미지는 `streamedFileResponse()` 를 지나
 * 304 로 재검증되는데, 문서·압축 파일 같은 비이미지는 `AttachmentService::download()` 의
 * 스트림을 **그대로** 돌려줘 검증자가 하나도 없었다 — 같은 사이트에서 같은 첨부가 유형에
 * 따라 재검증 가능 여부가 갈렸다. 오류도 로그도 없이 대역폭으로만 나타나고, 링크가 되는
 * 서버의 정적 서빙은 유형을 가리지 않으므로 링크 유무로 동작이 갈리는 상태였다.
 *
 * @scenario response_kind=streamed, conditional_request=none
 */
class AttachmentDownloadConditionalRequestTest extends TestCase
{
    use RefreshDatabase;

    private Attachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        HookManager::resetAll();
        config(['attachment.disk' => 'attachments']);
        Storage::fake('attachments');

        $path = 'attachments/2026/08/29/report.pdf';
        Storage::disk('attachments')->put($path, str_repeat('%PDF-1.4 fixture ', 8));

        $this->attachment = Attachment::factory()->create([
            'disk' => 'attachments',
            'path' => $path,
            'original_filename' => 'report.pdf',
            'stored_filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size' => Storage::disk('attachments')->size($path),
        ]);
    }

    private function url(): string
    {
        return '/api/attachment/'.$this->attachment->hash;
    }

    /**
     * 304 가 본문 없는 전용 응답이고 200 의 캐시 축을 그대로 싣는지 단언합니다.
     */
    private function assertNotModifiedMirrors200(TestResponse $full, TestResponse $notModified): void
    {
        $notModified->assertStatus(304);
        $this->assertInstanceOf(NotModifiedResponse::class, $notModified->baseResponse);
        $this->assertSame('', (string) $notModified->baseResponse->getContent(), '304 에 본문이 실렸다');

        foreach (['ETag', 'Last-Modified', 'Cache-Control', 'Expires'] as $header) {
            $this->assertNotNull($full->headers->get($header), "200 이 {$header} 를 싣지 않는다");
            $this->assertSame(
                $full->headers->get($header),
                $notModified->headers->get($header),
                "304 가 200 과 다른 {$header} 를 광고한다 (RFC 9110 §15.4.5)"
            );
        }

        $this->assertSame($full->headers->get('Vary'), $notModified->headers->get('Vary'));
    }

    /**
     * 비이미지 다운로드도 검증자를 싣고, 다운로드 방식(attachment)은 유지된다.
     */
    public function test_non_image_download_carries_validators_and_keeps_attachment_disposition(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();
        $this->assertStringStartsWith(
            'attachment;',
            (string) $response->headers->get('Content-Disposition'),
            '비이미지 첨부는 다운로드 방식이어야 한다 — 캐시 계약을 입히며 inline 으로 바뀌면 문서가 화면에 열린다'
        );
        $this->assertStringContainsString('report.pdf', (string) $response->headers->get('Content-Disposition'));

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', (string) $response->headers->get('ETag'));
        $this->assertSame(
            gmdate('D, d M Y H:i:s', (int) $this->attachment->updated_at?->getTimestamp()).' GMT',
            $response->headers->get('Last-Modified')
        );
        $this->assertNotNull($response->headers->get('Expires'));

        // `immutable` 은 붙지 않는다 — 이 URL 에는 `?v=` 가 없다.
        $this->assertStringNotContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     */
    public function test_matching_etag_yields_304_on_the_download_path(): void
    {
        $first = $this->get($this->url());
        $first->assertOk();

        $second = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])->get($this->url());

        $this->assertNotModifiedMirrors200($first, $second);
    }

    /**
     * @effects conditional_response_carries_the_full_cache_axis_of_200
     */
    public function test_if_modified_since_alone_yields_304_on_the_download_path(): void
    {
        $first = $this->get($this->url());
        $first->assertOk();

        $second = $this->withHeaders(['If-Modified-Since' => (string) $first->headers->get('Last-Modified')])->get($this->url());

        $this->assertNotModifiedMirrors200($first, $second);
    }

    public function test_non_matching_validator_returns_the_full_download(): void
    {
        $stale = $this->withHeaders(['If-None-Match' => '"not-the-current-tag"'])->get($this->url());

        $stale->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $stale->headers->get('Content-Disposition'));
    }

    /**
     * 사용자 업로드물 통로는 압축 제외를 **선언**한다 (형제 `PublicStorageController` 와 같다).
     *
     * 스트림 응답이 오늘 압축되지 않는 것은 미들웨어가 응답 클래스를 보고 건너뛰는 우연이라,
     * 응답 헤더만으로는 이 축을 잴 수 없다 — 요청 속성으로 잰다.
     */
    public function test_download_path_declares_compression_skip(): void
    {
        $this->withHeaders(['Accept-Encoding' => 'gzip'])->get($this->url())->assertOk();

        $this->assertTrue(
            $this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE),
            '사용자 업로드물 통로는 압축 제외를 선언해야 한다'
        );
    }
}
