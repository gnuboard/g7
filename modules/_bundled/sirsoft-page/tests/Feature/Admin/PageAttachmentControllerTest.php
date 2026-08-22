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
     * 설정 상한을 넘는 용량이 실제 요청에서 422 로 차단되는지 확인 (E3)
     *
     * `rules()` 문자열 단언만으로는 규칙이 실제 요청에 적용되는지 알 수 없다 — Request 가
     * 라우트에 배선되지 않았거나 컨트롤러가 우회하면 문자열은 맞는데 요청은 통과한다.
     * 기본값(10MB)과 다른 값을 주입해 하드코딩과의 우연한 일치를 피한다.
     */
    public function test_upload_over_configured_size_returns_422(): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 5,
            'max_size_mb' => 5,
            'allowed_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        ]]);

        // 상한 5MB 에 6MB → 초과
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => UploadedFile::fake()->create('over-size.png', 6 * 1024, 'image/png'),
                'temp_key' => 'test-over-size',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 개수 상한에 도달한 뒤의 단일 업로드가 422 로 차단되는지 확인 (E3)
     *
     * 개수 상한의 최종 관문은 Service 지만, 사용자는 파일을 한 건씩 올린다 — 마지막 한 건이
     * 500 이 아니라 422 로 돌아와야 화면이 사유를 안내할 수 있다.
     */
    public function test_single_upload_beyond_count_limit_returns_422(): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 3,
            'max_size_mb' => 10,
            'allowed_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        ]]);

        $tempKey = 'test-count-limit';

        for ($i = 0; $i < 3; $i++) {
            PageAttachment::create([
                'page_id' => null,
                'temp_key' => $tempKey,
                'disk' => 'local',
                'original_filename' => "seed-{$i}.png",
                'stored_filename' => "seed-{$i}.png",
                'path' => "temp/{$tempKey}/seed-{$i}.png",
                'mime_type' => 'image/png',
                'size' => 100,
                'order' => $i,
            ]);
        }

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => UploadedFile::fake()->create('fourth.png', 10, 'image/png'),
                'temp_key' => $tempKey,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 수정 전 허용 목록에 있던 형식이 설정 기준으로 차단되는지 확인 (E3)
     *
     * 기존 `test_upload_disallowed_file_type_returns_422` 는 `.exe` 를 쓴다 — 수정 전
     * 하드코딩 목록(`mimes:...,doc,docx,xls,...`)에도 없던 형식이라 수정 전후 모두 422 다.
     * 즉 그 케이스는 이 변경을 red 로 잡아내지 못한다.
     *
     * 문서 형식은 수정 전에는 통과했고 설정 기준으로는 차단되므로, 전환이 실제로 적용된
     * 것을 red 로 증명할 수 있는 유일한 경계다.
     */
    public function test_upload_type_allowed_before_but_not_in_settings_returns_422(): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 5,
            'max_size_mb' => 10,
            'allowed_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        ]]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => UploadedFile::fake()->create(
                    'legacy.docx',
                    100,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
                'temp_key' => 'test-legacy-type',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 설정에 추가한 형식은 곧바로 허용되는지 확인 (E3 — 차단 일변도가 아님)
     *
     * 위 케이스와 같은 파일을 설정에만 넣어 통과시킨다. 두 케이스가 짝을 이뤄야 "설정이
     * SSoT" 라는 계약이 증명된다 — 차단만 보면 목록이 그냥 좁아진 것과 구분되지 않는다.
     */
    public function test_upload_type_added_to_settings_is_allowed(): void
    {
        config(['g7_settings.modules.sirsoft-page.attachment' => [
            'max_count' => 5,
            'max_size_mb' => 10,
            'allowed_types' => [
                'image/png',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ]]);

        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/modules/sirsoft-page/admin/attachments', [
                'file' => UploadedFile::fake()->create(
                    'allowed.docx',
                    100,
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ),
                'temp_key' => 'test-added-type',
            ]);

        $response->assertStatus(201);
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

        $this->assertDatabaseMissing('page_attachments', ['id' => $attachment->id]);
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

    // 첨부 다운로드/미리보기는 공개 hash 라우트(pages/attachment/*)로 단일화되어
    // 관리자 전용 preview/download 라우트가 제거됨. 해당 서빙 경로 테스트는
    // PublicPageControllerTest 의 preview/download 매트릭스로 이관.

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
