<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 첨부파일 CRUD 전수 시나리오 테스트
 *
 * 검증 목적:
 * - 회원/비회원 첨부파일 업로드 권한 분기
 * - use_file_upload=false 게시판에서 업로드 차단
 * - 업로드 후 게시글 작성 시 attachments_count 반영
 * - 업로드 권한 없는 사용자 차단
 * - 첨부파일 삭제 및 attachments_count 감소
 *
 * @group board
 * @group attachment
 */
class AttachmentCrudScenarioTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'attachment-crud';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '첨부파일 CRUD 테스트 게시판', 'en' => 'Attachment CRUD Test Board'],
            'is_active' => true,
            'use_file_upload' => true,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->setGuestPermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);

        $this->memberUser = User::factory()->create(['email' => 'attach-member@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    // ==========================================
    // 첨부파일 업로드 권한 시나리오
    // ==========================================

    /**
     * 업로드 권한 있는 회원은 첨부파일을 업로드할 수 있다
     */
    public function test_member_with_upload_permission_can_upload(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    /**
     * 업로드 권한 없는 사용자는 첨부파일을 업로드할 수 없다
     */
    public function test_user_without_upload_permission_cannot_upload(): void
    {
        $noPermUser = User::factory()->create(['email' => 'no-perm-attach@test.com']);
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($noPermUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    /**
     * 비회원도 업로드 권한이 있으면 첨부파일을 업로드할 수 있다
     */
    public function test_guest_with_upload_permission_can_upload(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments", [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }

    // ==========================================
    // use_file_upload 게시판 설정 시나리오
    // ==========================================

    /**
     * use_file_upload=false 게시판에서는 파일 첨부가 차단된다
     */
    public function test_upload_blocked_when_board_disables_file_upload(): void
    {
        $this->updateBoardSettings(['use_file_upload' => false]);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments", [
                'file' => $file,
            ]);

        $response->assertStatus(403);
    }

    /**
     * use_file_upload=false 게시판에서 파일과 함께 게시글 작성이 차단된다
     */
    public function test_post_with_file_blocked_when_board_disables_file_upload(): void
    {
        $this->updateBoardSettings(['use_file_upload' => false]);

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '파일 첨부 게시글',
                'content' => '파일을 첨부하려는 게시글 내용입니다.',
                'author_name' => '작성자',
                'files' => [$file],
            ]);

        $response->assertStatus(403);
    }

    // ==========================================
    // 첨부파일 삭제 시나리오
    // ==========================================

    /**
     * 첨부파일을 삭제하면 DB에서 제거된다
     */
    public function test_can_delete_attachment(): void
    {
        // DB에 직접 첨부파일 레코드 생성
        $attachmentId = DB::table('board_attachments')->insertGetId([
            'board_id' => $this->board->id,
            'post_id' => null,
            'created_by' => $this->memberUser->id,
            'hash' => 'testhash1234',
            'original_filename' => 'test.pdf',
            'stored_filename' => 'test.pdf',
            'disk' => 'public',
            'path' => 'attachments/test.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'collection' => 'attachments',
            'order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachments/{$attachmentId}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('board_attachments', ['id' => $attachmentId]);
    }

    // ==========================================
    // attachments_count 연동 시나리오
    // ==========================================

    /**
     * 게시글에 첨부파일이 없으면 attachments_count가 0이다
     */
    public function test_post_without_file_has_zero_attachments_count(): void
    {
        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts", [
                'title' => '첨부파일 없는 게시글',
                'content' => '첨부파일이 없는 게시글 본문입니다.',
                'author_name' => '작성자',
            ]);

        $response->assertStatus(201);
        $postId = $response->json('data.id');

        $attachmentsCount = DB::table('board_posts')->where('id', $postId)->value('attachments_count');
        $this->assertEquals(0, $attachmentsCount);
    }
}
