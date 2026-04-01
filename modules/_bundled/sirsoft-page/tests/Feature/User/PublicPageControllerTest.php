<?php

namespace Modules\Sirsoft\Page\Tests\Feature\User;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

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
        $storageMock->shouldReceive('response')->andReturn(null);
        $this->app->instance(StorageInterface::class, $storageMock);
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
                'data' => ['id', 'slug', 'title', 'content'],
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

        $response = $this->getJson("/api/modules/sirsoft-page/pages/test-page-with-attach");

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
}
