<?php

namespace Modules\Sirsoft\Page\Tests\Unit\Services;

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Services\PageAttachmentService;
use Modules\Sirsoft\Page\Tests\ModuleTestCase;

/**
 * PageAttachmentService 단위 테스트
 *
 * 첨부파일 업로드/삭제/순서변경/연결 비즈니스 로직을 검증합니다.
 */
class PageAttachmentServiceTest extends ModuleTestCase
{
    private PageAttachmentService $service;

    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->createAdminUser([]);
        $this->actingAs($this->adminUser);

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

        $this->service = app(PageAttachmentService::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ─── upload ────────────────────────────────────────

    /**
     * 파일을 페이지에 업로드할 수 있는지 확인
     */
    public function test_upload_creates_attachment_for_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-attach-page',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $attachment = $this->service->upload($file, $page->id);

        $this->assertInstanceOf(PageAttachment::class, $attachment);
        $this->assertEquals($page->id, $attachment->page_id);
        $this->assertEquals('document.pdf', $attachment->original_filename);
        $this->assertEquals('application/pdf', $attachment->mime_type);
        $this->assertNull($attachment->temp_key);
    }

    /**
     * temp_key로 임시 업로드할 수 있는지 확인
     */
    public function test_upload_creates_temp_attachment(): void
    {
        $file = UploadedFile::fake()->create('temp-file.pdf', 200, 'application/pdf');

        $attachment = $this->service->upload($file, null, 'attachments', 'temp-key-123');

        $this->assertNull($attachment->page_id);
        $this->assertEquals('temp-key-123', $attachment->temp_key);
    }

    /**
     * 업로드 시 order가 자동 증가하는지 확인
     */
    public function test_upload_auto_increments_order(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-attach-order',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file1 = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('second.pdf', 100, 'application/pdf');

        $attachment1 = $this->service->upload($file1, $page->id);
        $attachment2 = $this->service->upload($file2, $page->id);

        $this->assertEquals(1, $attachment1->order);
        $this->assertEquals(2, $attachment2->order);
    }

    /**
     * 이미지 업로드 시 메타데이터(width, height)가 저장되는지 확인
     */
    public function test_upload_image_stores_dimensions_in_meta(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-attach-img',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $attachment = $this->service->upload($file, $page->id);

        $this->assertNotNull($attachment->meta);
        $this->assertArrayHasKey('width', $attachment->meta);
        $this->assertArrayHasKey('height', $attachment->meta);
    }

    // ─── linkTempAttachmentsWithMove ───────────────────

    /**
     * 임시 첨부파일을 페이지에 연결할 수 있는지 확인
     */
    public function test_link_temp_attachments_moves_to_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-link-temp',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        // 임시 첨부파일 생성
        $file = UploadedFile::fake()->create('temp-doc.pdf', 100, 'application/pdf');
        $tempAttachment = $this->service->upload($file, null, 'attachments', 'link-temp-key');

        $this->assertNull($tempAttachment->page_id);

        // 페이지에 연결
        $count = $this->service->linkTempAttachmentsWithMove('link-temp-key', $page->id);

        $this->assertEquals(1, $count);

        $tempAttachment->refresh();
        $this->assertEquals($page->id, $tempAttachment->page_id);
        $this->assertNull($tempAttachment->temp_key);
    }

    // ─── deleteAttachment ──────────────────────────────

    /**
     * 첨부파일을 삭제할 수 있는지 확인
     */
    public function test_delete_attachment_soft_deletes(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-del-attach',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file = UploadedFile::fake()->create('delete-me.pdf', 100, 'application/pdf');
        $attachment = $this->service->upload($file, $page->id);

        $result = $this->service->deleteAttachment($attachment);

        $this->assertTrue($result);
        $this->assertSoftDeleted('page_attachments', ['id' => $attachment->id]);
    }

    // ─── reorder ───────────────────────────────────────

    /**
     * 첨부파일 순서를 변경할 수 있는지 확인
     */
    public function test_reorder_changes_attachment_orders(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-reorder',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file1 = UploadedFile::fake()->create('a.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('b.pdf', 100, 'application/pdf');

        $att1 = $this->service->upload($file1, $page->id);
        $att2 = $this->service->upload($file2, $page->id);

        // 순서 역전
        $this->service->reorder([
            $att1->id => 2,
            $att2->id => 1,
        ]);

        $att1->refresh();
        $att2->refresh();
        $this->assertEquals(2, $att1->order);
        $this->assertEquals(1, $att2->order);
    }

    // ─── getByHash ─────────────────────────────────────

    /**
     * 해시로 첨부파일을 조회할 수 있는지 확인
     */
    public function test_get_by_hash_returns_attachment(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-hash',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file = UploadedFile::fake()->create('hash-test.pdf', 100, 'application/pdf');
        $attachment = $this->service->upload($file, $page->id);

        $found = $this->service->getByHash($attachment->hash);

        $this->assertNotNull($found);
        $this->assertEquals($attachment->id, $found->id);
    }

    /**
     * 존재하지 않는 해시로 조회 시 null을 반환하는지 확인
     */
    public function test_get_by_hash_returns_null_for_nonexistent(): void
    {
        $found = $this->service->getByHash('nonexistent1');

        $this->assertNull($found);
    }

    // ─── getByPageId ───────────────────────────────────

    /**
     * 페이지의 첨부파일 목록을 조회할 수 있는지 확인
     */
    public function test_get_by_page_id_returns_attachments(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-svc-by-page',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file1 = UploadedFile::fake()->create('one.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('two.pdf', 100, 'application/pdf');

        $this->service->upload($file1, $page->id);
        $this->service->upload($file2, $page->id);

        $attachments = $this->service->getByPageId($page->id);

        $this->assertCount(2, $attachments);
    }
}
