<?php

namespace Tests\Feature\Api\Public;

use App\Enums\ExtensionStatus;
use App\Http\Middleware\GzipEncodeResponse;
use App\Models\Template;
use App\Models\TemplateLayoutAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 무인증 레이아웃 첨부 서빙(`PublicTemplateController::serveFile`)의 파일 서빙 계약 테스트.
 *
 * 이 통로는 형제 아홉 통로와 같은 헬퍼(`streamedFileResponse()`)를 지나면서도 `immutable`·
 * `mimeType`·`skipCompression` 세 인자를 전부 생략하고 있었다 (15차 G3 라운드). 셋 다
 * **없어도 동작하는** 축이라 오류로 드러나지 않는다:
 *
 *  - `immutable` 생략 → 요청의 `?v=` 로 파생된다. 이 URL 은 행 id 주소라 서버가 버전을
 *    발급하지 않는데, 요청자가 `?v=1` 을 붙이면 사용자 업로드물이 1년 `immutable` 로 나간다.
 *  - `mimeType` 생략 → 스토리지 어댑터가 세운 헤더에 기댄다(추정은 사본이다).
 *  - `skipCompression` 생략 → 압축을 피하는 것이 응답 **클래스**를 보고 건너뛰는 우연이 된다.
 *
 * @scenario response_kind=streamed, conditional=none
 *
 * @effects conditional_response_carries_the_full_cache_axis_of_200,
 *          public_storage_response_revalidates_instead_of_immutable,
 *          file_serving_response_declares_nosniff
 */
class PublicTemplateLayoutAttachmentServeTest extends TestCase
{
    use RefreshDatabase;

    private const STORAGE_CATEGORY = 'template-layout-attachments';

    /**
     * @var array<string>
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-gdpr',
    ];

    private Template $template;

    private TemplateLayoutAttachment $attachment;

    protected function setUp(): void
    {
        parent::setUp();

        $disk = config('attachment.disk', 'attachments');
        Storage::fake($disk);

        $this->template = Template::create([
            'identifier' => 'sirsoft-basic',
            'vendor' => 'sirsoft',
            'name' => ['ko' => '기본', 'en' => 'Basic'],
            'version' => '1.0.0',
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
            'description' => ['ko' => '기본', 'en' => 'Basic'],
        ]);

        $relativePath = 'sirsoft-basic/2026/08/29/bg.svg';
        Storage::disk($disk)->put(self::STORAGE_CATEGORY.'/'.$relativePath, '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $this->attachment = TemplateLayoutAttachment::create([
            'template_id' => $this->template->id,
            'layout_name' => 'home',
            'disk' => $disk,
            'path' => $relativePath,
            'original_name' => 'bg.svg',
            'mime_type' => 'image/svg+xml',
            'size' => 41,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function serveUrl(string $query = ''): string
    {
        return route('api.public.templates.layout-attachment-file', [
            'identifier' => $this->template->identifier,
            'attachment' => $this->attachment->id,
        ]).$query;
    }

    /**
     * 요청자가 `?v=` 를 붙여도 `immutable` 이 되지 않는다.
     *
     * 이 주소의 버전은 서버가 발급하지 않는다 — 파생 규칙(`?v=` 가 있으면 immutable)은 서버가
     * 버전을 실어 보내는 자산 URL 에만 의미가 있고, 사용자 업로드물 통로는 명시 `false` 다
     * (공개 스토리지·첨부·확장 이미지와 같은 선언).
     */
    public function test_request_supplied_version_does_not_make_the_response_immutable(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->get($this->serveUrl('?v=1'));
        $response->assertOk();

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringNotContainsString('immutable', $cacheControl, '사용자 업로드물 주소에 요청자가 붙인 ?v= 로 immutable 이 되면 안 된다');
        $this->assertStringContainsString('must-revalidate', $cacheControl);
    }

    /**
     * `Content-Type` 은 행 메타(`mime_type`)가 근거다 — 스토리지 어댑터의 추정이 아니다.
     */
    public function test_content_type_is_the_row_mime_type(): void
    {
        $this->get($this->serveUrl())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');
    }

    /**
     * 압축 제외는 **선언**된다 — 응답 클래스에 기대지 않는다.
     */
    public function test_compression_exclusion_is_declared_on_the_request(): void
    {
        $this->get($this->serveUrl())->assertOk();

        $this->assertTrue(
            $this->app['request']->attributes->get(GzipEncodeResponse::SKIP_ATTRIBUTE) === true,
            '사용자 업로드물 통로는 압축 제외를 선언해야 한다 — 형제 아홉 통로와 같은 선언'
        );
    }

    /**
     * 200·304 모두 `X-Content-Type-Options: nosniff` 를 싣는다.
     *
     * SVG 는 스크립트를 실을 수 있고 이 통로는 무인증이다. `nosniff` 가 없으면 브라우저가
     * 선언 유형을 무시하고 내용으로 판정할 여지가 남는다 — 정적 서빙 설정이 붙이는 헤더를
     * 이 통로만 빠뜨리면 링크 유무로 위험이 갈린다.
     */
    public function test_full_and_not_modified_responses_declare_nosniff(): void
    {
        $first = $this->get($this->serveUrl());
        $first->assertOk();
        $first->assertHeader('X-Content-Type-Options', 'nosniff');

        $second = $this->withHeaders(['If-None-Match' => (string) $first->headers->get('ETag')])
            ->get($this->serveUrl());

        $second->assertStatus(304);
        $second->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($first->headers->get('Cache-Control'), $second->headers->get('Cache-Control'));
    }
}
