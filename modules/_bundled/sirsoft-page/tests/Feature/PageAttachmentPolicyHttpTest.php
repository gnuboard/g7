<?php

namespace Modules\Sirsoft\Page\Tests\Feature;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 페이지 첨부 정책의 HTTP 왕복 강제 테스트 (E3)
 *
 * `PageAttachmentPolicyTest` 는 규칙 문자열과 Service 불변조건을 정적으로 고정한다.
 * 그러나 그것만으로는 (a) `UploadPageAttachmentRequest` 가 실제 라우트에 결선되어 있는지,
 * (b) Service 예외가 컨트롤러에서 422 로 매핑되는지가 검증되지 않는다.
 * 규칙이 올바르게 생성돼도 라우트에 물려 있지 않으면 업로드는 그대로 통과한다.
 *
 * 이 클래스는 실제 요청을 보내 응답 코드까지 확인한다.
 *
 * 주의: 용량·형식 판정은 반드시 기본값과 다른 설정값을 주입한다 —
 * 수정 전 하드코딩(10240KB / mimes 16종)과 우연히 일치하면 red 가 확보되지 않는다.
 */
class PageAttachmentPolicyHttpTest extends FeatureTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-page/admin/attachments';

    protected User $adminUser;

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([
            'sirsoft-page.pages.read',
            'sirsoft-page.pages.create',
            'sirsoft-page.pages.update',
            'sirsoft-page.pages.delete',
        ]);

        // StorageInterface 를 모킹 (파일 시스템 의존 제거)
        $storageMock = Mockery::mock(StorageInterface::class);
        $storageMock->shouldReceive('put')->andReturn(true);
        $storageMock->shouldReceive('get')->andReturn('file content');
        $storageMock->shouldReceive('exists')->andReturn(true);
        $storageMock->shouldReceive('delete')->andReturn(true);
        $storageMock->shouldReceive('deleteDirectory')->andReturn(true);
        $storageMock->shouldReceive('getDisk')->andReturn('local');
        $storageMock->shouldReceive('url')->andReturn('/storage/test.pdf');
        $storageMock->shouldReceive('response')->andReturn(null);
        $this->app->instance(StorageInterface::class, $storageMock);
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'policy-http-%')->forceDelete();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 첨부 설정을 주입합니다.
     *
     * @param  array  $overrides  덮어쓸 설정 값
     */
    private function writeAttachmentSettings(array $overrides = []): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment' => array_merge([
            'max_count' => 5,
            'max_size_mb' => 10,
            'allowed_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        ], $overrides)]);
    }

    /**
     * 테스트용 페이지를 만듭니다.
     *
     * @param  string  $slug  페이지 슬러그
     * @return Page 생성된 페이지
     */
    private function makePage(string $slug): Page
    {
        return Page::factory()->create([
            'slug' => $slug,
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);
    }

    /**
     * 허용 목록에 없는 형식(docx)은 422 로 거부된다
     *
     * 수정 전에는 `mimes:` 16종 하드코딩이 설정과 무관하게 docx 를 통과시켰다.
     */
    public function test_disallowed_mime_type_is_rejected_over_http(): void
    {
        $this->writeAttachmentSettings([
            'allowed_types' => ['image/png', 'application/pdf'],
        ]);

        $page = $this->makePage('policy-http-mime');

        $file = UploadedFile::fake()->create(
            'contract.docx',
            100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        $response = $this->actingAs($this->adminUser)
            ->postJson(self::ENDPOINT, [
                'file' => $file,
                'page_id' => $page->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->assertDatabaseMissing('page_attachments', [
            'page_id' => $page->id,
            'original_filename' => 'contract.docx',
        ]);
    }

    /**
     * 허용 목록에 있는 형식은 그대로 통과한다 (과잉 차단 방지 대조군)
     */
    public function test_allowed_mime_type_still_passes_over_http(): void
    {
        $this->writeAttachmentSettings([
            'allowed_types' => ['image/png', 'application/pdf'],
        ]);

        $page = $this->makePage('policy-http-mime-ok');

        $file = UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf');

        $this->actingAs($this->adminUser)
            ->postJson(self::ENDPOINT, [
                'file' => $file,
                'page_id' => $page->id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('page_attachments', [
            'page_id' => $page->id,
            'original_filename' => 'manual.pdf',
        ]);
    }

    /**
     * 설정한 최대 용량을 넘는 파일은 422 로 거부된다
     *
     * 설정을 5MB 로 낮춘 뒤 6MB 를 올린다 — 기본값(10MB) 상태였다면 통과했을 크기다.
     */
    public function test_file_over_configured_max_size_is_rejected_over_http(): void
    {
        $this->writeAttachmentSettings(['max_size_mb' => 5]);

        $page = $this->makePage('policy-http-size');

        // 6MB — 설정 상한 5MB 초과, 수정 전 하드코딩 10MB 였다면 통과
        $file = UploadedFile::fake()->create('large.pdf', 6 * 1024, 'application/pdf');

        $response = $this->actingAs($this->adminUser)
            ->postJson(self::ENDPOINT, [
                'file' => $file,
                'page_id' => $page->id,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['file']);
    }

    /**
     * 상한 이내 용량은 통과한다 (경계 대조군)
     */
    public function test_file_within_configured_max_size_passes_over_http(): void
    {
        $this->writeAttachmentSettings(['max_size_mb' => 5]);

        $page = $this->makePage('policy-http-size-ok');

        $file = UploadedFile::fake()->create('small.pdf', 4 * 1024, 'application/pdf');

        $this->actingAs($this->adminUser)
            ->postJson(self::ENDPOINT, [
                'file' => $file,
                'page_id' => $page->id,
            ])
            ->assertStatus(201);
    }

    /**
     * 개수 상한을 넘는 6번째 업로드는 422 + 코드로 차단된다
     *
     * Service 예외가 컨트롤러에서 422 로 매핑되는지까지 확인한다
     * (매핑이 없으면 generic 500 으로 떨어진다).
     */
    public function test_upload_beyond_max_count_returns_422_with_code(): void
    {
        $this->writeAttachmentSettings(['max_count' => 5]);

        $page = $this->makePage('policy-http-count');

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->adminUser)
                ->postJson(self::ENDPOINT, [
                    'file' => UploadedFile::fake()->create("doc-{$i}.pdf", 10, 'application/pdf'),
                    'page_id' => $page->id,
                ])
                ->assertStatus(201);
        }

        $response = $this->actingAs($this->adminUser)
            ->postJson(self::ENDPOINT, [
                'file' => UploadedFile::fake()->create('doc-6.pdf', 10, 'application/pdf'),
                'page_id' => $page->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.code', 'attachment_limit_exceeded');

        // 6번째는 저장되지 않았다 — "차단은 했는데 이미 저장됨" 반쪽 통과 배제
        $this->assertSame(5, PageAttachment::where('page_id', $page->id)->count());
    }

    /**
     * 상한 미설정(0)이면 개수를 제한하지 않는다 (기존 동작 보존)
     */
    public function test_zero_max_count_is_unrestricted_over_http(): void
    {
        $this->writeAttachmentSettings(['max_count' => 0]);

        $page = $this->makePage('policy-http-count-zero');

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($this->adminUser)
                ->postJson(self::ENDPOINT, [
                    'file' => UploadedFile::fake()->create("free-{$i}.pdf", 10, 'application/pdf'),
                    'page_id' => $page->id,
                ])
                ->assertStatus(201);
        }

        $this->assertSame(6, PageAttachment::where('page_id', $page->id)->count());
    }
}
