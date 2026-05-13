<?php

namespace Modules\Sirsoft\Page\Tests\Feature\Admin;

// FeatureTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../FeatureTestCase.php';

use App\Contracts\Extension\StorageInterface;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Models\PageAttachment;
use Modules\Sirsoft\Page\Tests\FeatureTestCase;

/**
 * 관리자 첨부파일 API 테스트
 *
 * PageAttachmentController의 업로드, 삭제, 순서 변경, 다운로드, 미리보기를 검증합니다.
 */
class PageAttachmentControllerTest extends FeatureTestCase
{
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

        // StorageInterface를 모킹 (파일 시스템 의존 제거)
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
        Page::where('slug', 'like', 'test-%')->forceDelete();
        Mockery::close();
        parent::tearDown();
    }

    // ─── 업로드 (upload) ───────────────────────────────

    /**
     * 첨부파일을 업로드할 수 있는지 확인
     */
    public function test_admin_can_upload_attachment_to_existing_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-attach-upload',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => $file,
                'page_id' => $page->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        'id', 'hash', 'original_filename', 'mime_type', 'size',
                    ],
                ],
            ]);

        $this->assertEquals('document.pdf', $response->json('data.data.original_filename'));
    }

    /**
     * temp_key로 임시 업로드할 수 있는지 확인
     */
    public function test_admin_can_upload_attachment_with_temp_key(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 300, 'application/pdf');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => $file,
                'temp_key' => 'test-temp-key-123',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('page_attachments', [
            'temp_key' => 'test-temp-key-123',
            'original_filename' => 'report.pdf',
        ]);
    }

    /**
     * 파일 없이 업로드 시 422를 반환하는지 확인
     */
    public function test_upload_without_file_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 허용되지 않는 파일 형식 업로드 시 422를 반환하는지 확인
     */
    public function test_upload_disallowed_file_type_returns_422(): void
    {
        $file = UploadedFile::fake()->create('script.exe', 100, 'application/x-msdownload');

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => $file,
                'temp_key' => 'test-bad-type',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    // ─── 삭제 (destroy) ────────────────────────────────

    /**
     * 첨부파일을 삭제할 수 있는지 확인
     */
    public function test_admin_can_delete_attachment(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-attach-delete',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $attachment = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'delete-me.pdf',
            'stored_filename' => 'stored-delete.pdf',
            'disk' => 'local',
            'path' => 'test/delete-me.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-page/admin/attachments/{$attachment->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('page_attachments', ['id' => $attachment->id]);
    }

    /**
     * 존재하지 않는 첨부파일 삭제 시 404를 반환하는지 확인
     */
    public function test_deleting_nonexistent_attachment_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->deleteJson('/api/modules/sirsoft-page/admin/attachments/99999');

        $response->assertStatus(404);
    }

    // ─── 순서 변경 (reorder) ───────────────────────────

    /**
     * 첨부파일 순서를 변경할 수 있는지 확인
     */
    public function test_admin_can_reorder_attachments(): void
    {
        $page = Page::factory()->create([
            'slug' => 'test-attach-reorder',
            'created_by' => $this->adminUser->id,
            'updated_by' => $this->adminUser->id,
        ]);

        $attachment1 = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'first.pdf',
            'stored_filename' => 'stored-first.pdf',
            'disk' => 'local',
            'path' => 'test/first.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 1,
            'created_by' => $this->adminUser->id,
        ]);

        $attachment2 = PageAttachment::create([
            'page_id' => $page->id,
            'original_filename' => 'second.pdf',
            'stored_filename' => 'stored-second.pdf',
            'disk' => 'local',
            'path' => 'test/second.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'collection' => 'attachments',
            'order' => 2,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-page/admin/attachments/reorder', [
                'order' => [
                    ['id' => $attachment1->id, 'order' => 2],
                    ['id' => $attachment2->id, 'order' => 1],
                ],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $attachment1->refresh();
        $attachment2->refresh();
        $this->assertEquals(2, $attachment1->order);
        $this->assertEquals(1, $attachment2->order);
    }

    /**
     * 빈 order 배열로 순서 변경 시 422를 반환하는지 확인
     */
    public function test_reorder_with_empty_order_returns_422(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-page/admin/attachments/reorder', [
                'order' => [],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['order']);
    }

    // ─── 다운로드/미리보기 ─────────────────────────────

    /**
     * 존재하지 않는 해시로 다운로드 시 404를 반환하는지 확인
     */
    public function test_download_nonexistent_hash_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/attachments/download/abcdefghijkl');

        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 해시로 미리보기 시 404를 반환하는지 확인
     */
    public function test_preview_nonexistent_hash_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-page/admin/attachments/preview/abcdefghijkl');

        $response->assertStatus(404);
    }

    // ─── 인증 차단 ─────────────────────────────────────

    /**
     * 미인증 사용자가 첨부파일을 업로드할 수 없는지 확인
     */
    public function test_unauthenticated_user_cannot_upload_attachment(): void
    {
        $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/modules/sirsoft-page/admin/attachments', [
            'file' => $file,
            'temp_key' => 'test-unauth',
        ]);

        $response->assertStatus(401);
    }
}
