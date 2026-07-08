<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 공개 페이지 API 테스트
 *
 * PublicPageController와 PublicPageAttachmentController의 엔드포인트를 검증합니다.
 * - 슬러그로 발행 페이지 조회
 * - 미발행 페이지 404
 * - 공개 첨부파일 다운로드/미리보기
 */
class PublicPageControllerTest extends FeatureTestCase
{
    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // StorageInterface 모킹
        $storageMock = Mockery::mock(StorageInterface::class);
        $storageMock->shouldReceive('put')->andReturn(true);
        $storageMock->shouldReceive('get')->andReturn('file content');
        $storageMock->shouldReceive('exists')->andReturn(true);
        $storageMock->shouldReceive('delete')->andReturn(true);
        $storageMock->shouldReceive('deleteDirectory')->andReturn(true);
        $storageMock->shouldReceive('getDisk')->andReturn('local');
        $storageMock->shouldReceive('url')->andReturn(null);
        // 파일 서빙(다운로드/미리보기)은 200 스트리밍 응답을 반환 — 권한 게이트 검증용
        $storageMock->shouldReceive('response')->andReturnUsing(
            fn () => new StreamedResponse(fn () => print ('file content'), 200)
        );
        $this->app->instance(StorageInterface::class, $storageMock);

        // PageAttachmentService 는 contextual binding 으로 모듈 도메인 Storage 를 받으므로
        // (AbstractExtensionServiceProvider::$storageServices), 전역 instance 만으로는
        // 오버라이드되지 않는다. 해당 서비스에도 mock 을 명시적으로 주입한다.
        $this->app->when(PageAttachmentService::class)
            ->needs(StorageInterface::class)
            ->give(fn () => $storageMock);
    }

    /**
     * 테스트 정리
     */
    protected function tearDown(): void
    {
        Page::where('slug', 'like', 'test-%')->forceDelete();
        Mockery::close();
        parent::tearDown();
    }

    // ─── 슬러그 조회 ──────────────────────────────────

    /**
     * 발행된 페이지를 슬러그로 조회할 수 있는지 확인
     */
    public function test_public_can_view_published_page_by_slug(): void
    {
        $admin = $this->createAdminUser([]);

        Page::factory()->published()->create([
            'slug' => 'test-public-page',
            'title' => ['ko' => '공개 페이지', 'en' => 'Public Page'],
            'content' => ['ko' => '공개 본문', 'en' => 'Public Content'],
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-page/pages/test-public-page');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['id', 'slug', 'title', 'content', 'current_version', 'created_at'],
            ]);
        $this->assertEquals('test-public-page', $response->json('data.slug'));
    }

    /**
     * 미발행 페이지 슬러그로 조회 시 404를 반환하는지 확인
     */
    public function test_unpublished_page_returns_404(): void
    {
        $admin = $this->createAdminUser([]);

        Page::factory()->create([
            'slug' => 'test-draft-page',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-page/pages/test-draft-page');

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 슬러그로 조회 시 404를 반환하는지 확인
     */
    public function test_nonexistent_slug_returns_404(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-page/pages/test-no-such-page');

        $response->assertStatus(404);
    }

    // ─── 미발행 페이지 관리자 미리보기 ────────

    /**
     * 페이지 조회 권한(pages.read) 관리자는 미발행 페이지를 미리볼 수 있는지 확인
     */
    public function test_admin_with_read_permission_can_preview_unpublished_page(): void
    {
        $admin = $this->createAdminUser(['sirsoft-page.pages.read']);

        Page::factory()->create([
            'slug' => 'test-draft-preview',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-page/pages/test-draft-preview');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'test-draft-preview')
            ->assertJsonPath('data.is_preview', true);
    }

    /**
     * 미발행 미리보기 응답의 첨부 URL이 공개 hash 라우트인지 확인
     *
     * 썸네일 <img>·다운로드는 브라우저 직접 GET 이라 토큰을 실을 수 없어 인증 라우트로
     * 두면 401 로 깨진다. 게시판·이커머스 표준과 동일하게 공개 hash 라우트로 단일화하고,
     * 미발행 콘텐츠 보안은 다운로드 권한 게이트(공개 라우트 내부)로 담당한다.
     */
    public function test_admin_preview_unpublished_page_attachments_use_public_url(): void
    {
        $admin = $this->createAdminUser(['sirsoft-page.pages.read']);

        $page = Page::factory()->create([
            'slug' => 'test-draft-preview-attach',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        // 이미지 첨부 — preview_url 은 이미지에만 생성되므로 image mime 사용
        PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'draft.png',
            'stored_filename' => 'stored-draft.png',
            'disk' => 'local',
            'path' => 'test/draft.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-page/pages/test-draft-preview-attach');

        $response->assertStatus(200);
        $downloadUrl = $response->json('data.attachments.0.download_url');
        $previewUrl = $response->json('data.attachments.0.preview_url');
        $this->assertStringContainsString('/pages/attachment/', $downloadUrl);
        $this->assertStringContainsString('/pages/attachment/', $previewUrl);
        $this->assertStringNotContainsString('/admin/attachments/', $downloadUrl);
        $this->assertStringNotContainsString('/admin/attachments/', $previewUrl);
    }

    /**
     * 페이지 권한이 없는 일반 회원은 미발행 페이지를 미리볼 수 없음(404)
     */
    public function test_non_admin_user_cannot_preview_unpublished_page(): void
    {
        $author = $this->createAdminUser([]);
        $member = $this->createUser();

        Page::factory()->create([
            'slug' => 'test-draft-member',
            'published' => false,
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);

        $response = $this->actingAs($member)
            ->getJson('/api/modules/sirsoft-page/pages/test-draft-member');

        $response->assertStatus(404);
    }

    /**
     * pages.read 권한이 없는 관리자(다른 모듈 admin)는 미발행 페이지를 미리볼 수 없음(404)
     */
    public function test_admin_without_pages_read_cannot_preview_unpublished_page(): void
    {
        // sirsoft-page.pages.read 가 아닌 다른 admin 권한만 보유
        $otherAdmin = $this->createAdminUser(['sirsoft-other.things.read']);

        Page::factory()->create([
            'slug' => 'test-draft-other-admin',
            'published' => false,
            'created_by' => $otherAdmin->id,
            'updated_by' => $otherAdmin->id,
        ]);

        $response = $this->actingAs($otherAdmin)
            ->getJson('/api/modules/sirsoft-page/pages/test-draft-other-admin');

        $response->assertStatus(404);
    }

    /**
     * 발행된 페이지의 첨부 URL은 관리자 요청이어도 공개용 라우트를 유지하는지 확인
     */
    public function test_published_page_attachments_use_public_url_even_for_admin(): void
    {
        $admin = $this->createAdminUser(['sirsoft-page.pages.read']);

        $page = Page::factory()->published()->create([
            'slug' => 'test-published-admin-attach',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'public.pdf',
            'stored_filename' => 'stored-public.pdf',
            'disk' => 'local',
            'path' => 'test/public.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/modules/sirsoft-page/pages/test-published-admin-attach');

        $response->assertStatus(200)
            ->assertJsonPath('data.is_preview', false);
        $downloadUrl = $response->json('data.attachments.0.download_url');
        $this->assertStringContainsString('/pages/attachment/', $downloadUrl);
    }

    /**
     * 발행된 페이지 조회 시 첨부파일 목록이 포함되는지 확인
     */
    public function test_published_page_includes_attachments(): void
    {
        $admin = $this->createAdminUser([]);

        $page = Page::factory()->published()->create([
            'slug' => 'test-page-with-attach',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'document.pdf',
            'stored_filename' => 'stored.pdf',
            'disk' => 'local',
            'path' => 'test/stored.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/modules/sirsoft-page/pages/test-page-with-attach');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'attachments' => [
                        '*' => ['id', 'hash', 'original_filename'],
                    ],
                ],
            ]);
        $this->assertCount(1, $response->json('data.attachments'));
    }

    // ─── 공개 첨부파일 다운로드/미리보기 ────────────────

    /**
     * 존재하지 않는 해시로 공개 다운로드 시 404를 반환하는지 확인
     */
    public function test_public_download_nonexistent_hash_returns_404(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-page/pages/attachment/abcdefghijkl');

        $response->assertStatus(404);
    }

    /**
     * 미발행 페이지의 첨부파일 다운로드 시 404를 반환하는지 확인
     */
    public function test_public_download_unpublished_page_attachment_returns_404(): void
    {
        $admin = $this->createAdminUser([]);

        $page = Page::factory()->create([
            'slug' => 'test-unpub-attach',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'secret.pdf',
            'stored_filename' => 'stored-secret.pdf',
            'disk' => 'local',
            'path' => 'test/secret.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}");

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 해시로 공개 미리보기 시 404를 반환하는지 확인
     */
    public function test_public_preview_nonexistent_hash_returns_404(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-page/pages/attachment/abcdefghijkl/preview');

        $response->assertStatus(404);
    }

    // ─── 썸네일(preview) 공개 서빙 매트릭스 (별건: 관리자 상세 썸네일 401 회귀) ───

    /**
     * 미발행 페이지의 이미지 첨부 미리보기(썸네일)는 비로그인이어도 200을 반환한다.
     *
     * 썸네일 <img> 는 토큰을 실을 수 없으므로 공개 hash 서빙으로 둔다.
     * 보안은 hash 비추측성 + 파일 다운로드 권한 게이트가 담당한다(트레이드오프 수용).
     */
    public function test_public_preview_unpublished_image_returns_200_for_guest(): void
    {
        $admin = $this->createAdminUser([]);

        $page = Page::factory()->create([
            'slug' => 'test-draft-preview-guest',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'draft.png',
            'stored_filename' => 'stored-draft.png',
            'disk' => 'local',
            'path' => 'test/draft.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}/preview");

        $response->assertStatus(200);
    }

    /**
     * 발행 페이지의 이미지 첨부 미리보기(썸네일)는 200을 반환한다.
     */
    public function test_public_preview_published_image_returns_200(): void
    {
        $admin = $this->createAdminUser([]);

        $page = Page::factory()->published()->create([
            'slug' => 'test-pub-preview',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'pub.png',
            'stored_filename' => 'stored-pub.png',
            'disk' => 'local',
            'path' => 'test/pub.png',
            'mime_type' => 'image/png',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}/preview");

        $response->assertStatus(200);
    }

    // ─── 다운로드 권한 게이트 매트릭스 (별건: 관리자 상세 다운로드 401 회귀) ───

    /**
     * 미발행 페이지 첨부 다운로드는 비로그인에게 404로 차단된다.
     */
    public function test_download_unpublished_attachment_blocked_for_guest(): void
    {
        $admin = $this->createAdminUser([]);

        $page = Page::factory()->create([
            'slug' => 'test-dl-guest',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'secret.pdf',
            'stored_filename' => 'stored-secret.pdf',
            'disk' => 'local',
            'path' => 'test/secret.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->getJson("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}");

        $response->assertStatus(404);
    }

    /**
     * 미발행 페이지 첨부 다운로드는 권한 없는 일반 회원에게 404로 차단된다.
     */
    public function test_download_unpublished_attachment_blocked_for_member(): void
    {
        $admin = $this->createAdminUser([]);
        $member = $this->createUser();

        $page = Page::factory()->create([
            'slug' => 'test-dl-member',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'secret.pdf',
            'stored_filename' => 'stored-secret.pdf',
            'disk' => 'local',
            'path' => 'test/secret.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($member)
            ->getJson("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}");

        $response->assertStatus(404);
    }

    /**
     * 미발행 페이지 첨부 다운로드는 pages.read 권한 관리자에게 200(스트리밍)을 허용한다.
     */
    public function test_download_unpublished_attachment_allowed_for_admin_with_read(): void
    {
        $admin = $this->createAdminUser(['sirsoft-page.pages.read']);

        $page = Page::factory()->create([
            'slug' => 'test-dl-admin',
            'published' => false,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'draft.pdf',
            'stored_filename' => 'stored-draft.pdf',
            'disk' => 'local',
            'path' => 'test/draft.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}");

        $response->assertStatus(200);
    }

    /**
     * 발행 페이지 첨부 다운로드는 누구나 200(스트리밍)을 허용한다.
     */
    public function test_download_published_attachment_allowed_for_guest(): void
    {
        $admin = $this->createAdminUser([]);

        $page = Page::factory()->published()->create([
            'slug' => 'test-dl-pub',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'pub.pdf',
            'stored_filename' => 'stored-pub.pdf',
            'disk' => 'local',
            'path' => 'test/pub.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $admin->id,
        ]);

        $response = $this->get("/api/modules/sirsoft-page/pages/attachment/{$attachment->hash}");

        $response->assertStatus(200);
    }
}
