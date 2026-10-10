<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Models\Attachment;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 글에 아직 이어지지 않은 임시 첨부의 삭제·순서 변경 테스트
 *
 * 글쓰기 화면은 [저장]을 누를 때 첨부를 먼저 올리고(임시 첨부, board_id=0) 글을 보낸다. 글 저장이
 * 422 로 실패하면 임시 첨부가 업로더 목록에 남는데, 이것을 지우면 첨부 조회가 「이 게시판 id」로만
 * 찾아 `DELETE …/attachments/{id} → 404` 「파일을 찾을 수 없습니다」가 났다(7.1.0 전수 실측 O-F1-1,
 * 페이지 모듈 L-D4 와 같은 결함군). 임시 첨부는 그 게시판 임시 경로(`{slug}/temp/…`)의 것만 대상이고,
 * 올린 사람만 지우거나 순서를 바꿀 수 있다(남의 임시 첨부는 잇지도 지우지도 못한다 — KVE-2026-2349).
 *
 * @scenario-manifest tests/scenarios/board-temp-attachment-delete.yaml
 */
class TempAttachmentDeleteTest extends BoardTestCase
{
    /**
     * 글 작성·업로드 권한이 있는 회원 (임시 첨부를 올리는 사람)
     */
    private User $uploader;

    /**
     * 같은 권한을 가진 다른 회원
     */
    private User $otherMember;

    /**
     * 테스트 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'temp-attachment-delete';
    }

    /**
     * 테스트 게시판 기본 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '임시 첨부 삭제 게시판', 'en' => 'Temp Attachment Delete Board'],
            'is_active' => true,
            'use_file_upload' => true,
            'max_file_count' => 5,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 권한과 회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'attachments.upload']);
        $this->uploader = $this->createMember('temp-delete-uploader@test.com');
        $this->otherMember = $this->createMember('temp-delete-other@test.com');
    }

    /**
     * 테스트가 만든 임시 첨부 행과 저장소 파일을 정리합니다.
     *
     * 임시 첨부는 board_id=0 이라 BoardTestCase 의 게시판 단위 정리에 걸리지 않는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([$this->getTestBoardSlug(), $this->getTestBoardSlug().'-other'] as $slug) {
            Attachment::withTrashed()->where('board_id', 0)->where('path', 'like', $slug.'/temp/%')->forceDelete();
            Storage::disk('modules')->deleteDirectory('sirsoft-board/attachments/'.$slug);
        }

        parent::tearDown();
    }

    /**
     * 회원은 사용자 경로로 자기 임시 첨부를 지울 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario route=user, actor=uploader, operation=delete
     *
     * @effects own_temp_attachment_deleted
     */
    public function test_member_can_delete_own_temp_attachment(): void
    {
        $id = $this->uploadAsMember($this->uploader, 'own.png');

        $this->actingAs($this->uploader, 'sanctum')
            ->deleteJson($this->userAttachmentsUrl()."/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('board_attachments', ['id' => $id]);
    }

    /**
     * 다른 회원은 사용자 경로로 남의 임시 첨부를 지울 수 없다(403, 행 그대로).
     *
     * @return void 반환값 없음
     *
     * @scenario route=user, actor=other_member, operation=delete
     *
     * @effects others_temp_attachment_rejected_with_403
     */
    public function test_other_member_cannot_delete_temp_attachment(): void
    {
        $id = $this->uploadAsMember($this->uploader, 'owner.png');

        $this->actingAs($this->otherMember, 'sanctum')
            ->deleteJson($this->userAttachmentsUrl()."/{$id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('board_attachments', ['id' => $id]);
    }

    /**
     * 관리자는 관리자 경로로 자기 임시 첨부를 지울 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario route=admin, actor=uploader, operation=delete
     *
     * @effects own_temp_attachment_deleted
     */
    public function test_admin_can_delete_own_temp_attachment(): void
    {
        $admin = $this->createUploadAdmin();
        $id = $this->uploadAsAdmin($admin, 'admin-own.pdf');

        $this->actingAs($admin)
            ->deleteJson($this->adminAttachmentsUrl()."/{$id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('board_attachments', ['id' => $id]);
    }

    /**
     * 같은 권한의 다른 관리자도 남이 올린 임시 첨부는 지울 수 없다(403, 행 그대로).
     *
     * @return void 반환값 없음
     *
     * @scenario route=admin, actor=other_admin, operation=delete
     *
     * @effects others_temp_attachment_rejected_with_403
     */
    public function test_other_admin_cannot_delete_temp_attachment(): void
    {
        $admin = $this->createUploadAdmin();
        $other = $this->createUploadAdmin();
        $id = $this->uploadAsAdmin($admin, 'admin-owner.pdf');

        $this->actingAs($other)
            ->deleteJson($this->adminAttachmentsUrl()."/{$id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('board_attachments', ['id' => $id]);
    }

    /**
     * 다른 게시판 주소로는 이 게시판에 올린 임시 첨부를 찾지 못한다(404, 행 그대로).
     *
     * @return void 반환값 없음
     *
     * @scenario route=user, actor=uploader, operation=delete
     *
     * @effects temp_attachment_bound_to_uploaded_board
     */
    public function test_temp_attachment_is_not_reachable_through_another_board(): void
    {
        $otherSlug = $this->getTestBoardSlug().'-other';
        Board::updateOrCreate(['slug' => $otherSlug], $this->getDefaultBoardAttributes($otherSlug));

        // 다른 게시판에도 업로드 권한을 줘 미들웨어(403)가 아니라 첨부 조회에서 갈리게 한다
        $userRole = Role::where('identifier', 'user')->firstOrFail();
        $permission = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$otherSlug}.attachments.upload"],
            ['name' => ['ko' => 'attachments.upload', 'en' => 'attachments.upload'], 'type' => 'user']
        );
        $userRole->permissions()->syncWithoutDetaching([$permission->id]);
        $this->resetPermissionMiddlewareCache();

        $id = $this->uploadAsMember($this->uploader, 'bound.png');

        $this->actingAs($this->uploader, 'sanctum')
            ->deleteJson("/api/modules/sirsoft-board/boards/{$otherSlug}/attachments/{$id}")
            ->assertStatus(404);

        $this->assertNotSoftDeleted('board_attachments', ['id' => $id]);
    }

    /**
     * 수정 화면에서 더한 임시 첨부(기존 첨부가 있는 글)도 지울 수 있고 기존 첨부는 그대로다.
     *
     * @return void 반환값 없음
     *
     * @scenario route=user, actor=uploader, operation=delete
     *
     * @effects own_temp_attachment_deleted, existing_attachments_untouched
     */
    public function test_temp_attachment_added_on_edit_screen_can_be_deleted(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->uploader->id]);
        $existing = Attachment::query()->create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => 'existing.png',
            'stored_filename' => 'existing.png',
            'disk' => 'modules',
            'path' => $this->getTestBoardSlug().'/2026/10/08/existing.png',
            'mime_type' => 'image/png',
            'size' => 10,
            'collection' => 'post_attachments',
            'order' => 1,
            'created_by' => $this->uploader->id,
        ]);

        $id = $this->uploadAsMember($this->uploader, 'added-on-edit.png');

        $this->actingAs($this->uploader, 'sanctum')
            ->deleteJson($this->userAttachmentsUrl()."/{$id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('board_attachments', ['id' => $id]);
        $this->assertNotSoftDeleted('board_attachments', ['id' => $existing->id, 'post_id' => $postId, 'order' => 1]);
    }

    /**
     * 다른 회원은 남이 올린 임시 첨부의 순서를 바꿀 수 없다(403, 순서 그대로).
     *
     * 순서 변경의 담당 범위 확인은 `[ID => 순서]` 맵을 값 쪽에서 읽어 실제로는 아무것도 검사하지 않았다.
     *
     * @return void 반환값 없음
     *
     * @scenario route=user, actor=other_member, operation=reorder
     *
     * @effects others_temp_attachment_rejected_with_403
     */
    public function test_other_member_cannot_reorder_temp_attachments(): void
    {
        [$first, $second] = $this->uploadTwoTempAttachments();

        $this->actingAs($this->otherMember, 'sanctum')
            ->patchJson($this->userAttachmentsUrl().'/reorder', ['order' => [
                ['id' => $first, 'order' => 2],
                ['id' => $second, 'order' => 1],
            ]])
            ->assertStatus(403);

        $this->assertSame(1, (int) Attachment::query()->findOrFail($first)->order);
        $this->assertSame(2, (int) Attachment::query()->findOrFail($second)->order);
    }

    /**
     * 올린 사람은 임시 첨부의 순서를 바꿀 수 있다.
     *
     * 저장이 실패한 뒤 업로더 목록에서 끌어 순서를 바꾸면 같은 조회를 거쳐 서버 오류가 났다.
     *
     * @return void 반환값 없음
     *
     * @scenario route=user, actor=uploader, operation=reorder
     *
     * @effects own_temp_attachments_reordered
     */
    public function test_uploader_can_reorder_own_temp_attachments(): void
    {
        [$first, $second] = $this->uploadTwoTempAttachments();

        $this->actingAs($this->uploader, 'sanctum')
            ->patchJson($this->userAttachmentsUrl().'/reorder', ['order' => [
                ['id' => $first, 'order' => 2],
                ['id' => $second, 'order' => 1],
            ]])
            ->assertStatus(200);

        $this->assertSame(2, (int) Attachment::query()->findOrFail($first)->order);
        $this->assertSame(1, (int) Attachment::query()->findOrFail($second)->order);
    }

    /**
     * 올린 사람이 같은 임시 키로 첨부 두 개를 올리고 ID 를 돌려줍니다.
     *
     * @return array{0: int, 1: int} 첫째·둘째 첨부 ID
     */
    private function uploadTwoTempAttachments(): array
    {
        $tempKey = (string) Str::uuid();

        return [
            $this->uploadAsMember($this->uploader, 'first.png', $tempKey),
            $this->uploadAsMember($this->uploader, 'second.png', $tempKey),
        ];
    }

    /**
     * 회원으로 임시 첨부를 올리고 ID 를 돌려줍니다.
     *
     * @param  User  $user  올리는 회원
     * @param  string  $name  파일명
     * @param  string|null  $tempKey  임시 키 (없으면 새로 만든다)
     * @return int 첨부 ID
     */
    private function uploadAsMember(User $user, string $name, ?string $tempKey = null): int
    {
        $id = (int) $this->actingAs($user, 'sanctum')
            ->postJson($this->userAttachmentsUrl(), [
                'file' => UploadedFile::fake()->image($name),
                'temp_key' => $tempKey ?? (string) Str::uuid(),
                'collection' => 'post_attachments',
            ])
            ->assertStatus(201)
            ->json('data.data.id');

        $this->assertSame(0, (int) Attachment::query()->findOrFail($id)->board_id);

        return $id;
    }

    /**
     * 관리자로 임시 첨부를 올리고 ID 를 돌려줍니다.
     *
     * @param  User  $admin  올리는 관리자
     * @param  string  $name  파일명
     * @return int 첨부 ID
     */
    private function uploadAsAdmin(User $admin, string $name): int
    {
        $id = (int) $this->actingAs($admin)
            ->postJson($this->adminAttachmentsUrl(), [
                'file' => UploadedFile::fake()->create($name, 10, 'application/pdf'),
                'temp_key' => (string) Str::uuid(),
                'collection' => 'post_attachments',
            ])
            ->assertStatus(201)
            ->json('data.data.id');

        $this->assertSame(0, (int) Attachment::query()->findOrFail($id)->board_id);

        return $id;
    }

    /**
     * 이 게시판 첨부 업로드 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function createUploadAdmin(): User
    {
        $slug = $this->getTestBoardSlug();

        return $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.attachments.upload",
            "sirsoft-board.{$slug}.admin.posts.write",
        ]);
    }

    /**
     * 글 작성·업로드 권한 역할을 가진 회원을 만듭니다.
     *
     * @param  string  $email  이메일
     * @return User 생성된 회원
     */
    private function createMember(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        return $user;
    }

    /**
     * 사용자 첨부 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function userAttachmentsUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->getTestBoardSlug()}/attachments";
    }

    /**
     * 관리자 첨부 API 경로를 반환합니다.
     *
     * @return string API 경로
     */
    private function adminAttachmentsUrl(): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->getTestBoardSlug()}/attachments";
    }
}
