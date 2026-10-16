<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판 관리 권한의 게시판 스코프 재적용 테스트 (#789 §6.2 · §6.3 B2·B3·B4·B5·B15)
 *
 * 게시판 관리 권한(`admin.manage`·`manager`)은 스코프 메타 `resource_route_key = board`,
 * `owner_key = created_by` 를 갖는다. 게시판 라우트는 `{slug}` 를 쓰므로 미들웨어의 스코프
 * 검사(라우트 Model 바인딩 때만 동작)가 돌지 않아, 「본인이 만든 게시판만」으로 위임받은
 * 관리자가 남이 만든 게시판의 글을 블라인드·복원·일괄 처리하고 삭제글·비밀글·첨부까지 열었다.
 *
 * 기대: 남의 게시판에서는 관리 권한이 없는 사람과 같게(403·404·마스킹), 자기 게시판에서는
 * 그대로 동작. 403 은 500 이 아니어야 한다(컨트롤러의 `catch (\Exception)` 이 삼키지 않게).
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-scoped-permission-reapply.yaml 참조.
 */
#[Group('board')]
#[Group('admin')]
#[Group('scope')]
class BoardManageScopeTest extends BoardTestCase
{
    private User $owner;

    private User $delegate;

    private Role $delegateRole;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'board-manage-scope';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '게시판 관리 스코프', 'en' => 'Board Manage Scope'],
            'is_active' => true,
            'use_comment' => true,
            'use_file_upload' => true,
            'secret_mode' => 'enabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 게시판 소유자·위임 관리자(게시판 관리 = 본인만)를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        PermissionHelper::clearPermissionScopeCache();

        $adminRole = Role::where('identifier', 'admin')->first();

        // 게시판을 만든 다른 관리자
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach($adminRole->id);
        $this->board->forceFill(['created_by' => $this->owner->id])->save();

        // 위임 관리자 — 관리자 화면 진입(admin 역할, 권한 없음) + 위임 역할
        $this->delegate = User::factory()->create();
        $this->delegate->roles()->attach($adminRole->id);

        $this->delegateRole = Role::create([
            'identifier' => 'board-manage-scope-delegate',
            'name' => ['ko' => '게시판 위임 관리자', 'en' => 'Board Delegate'],
            'is_active' => true,
        ]);
        $this->delegate->roles()->attach($this->delegateRole->id);

        $this->grant('admin.manage', ScopeType::Self);
        $this->grant('manager', ScopeType::Self);
        $this->grant('admin.posts.read', null);
        $this->grant('admin.comments.read', null);
        $this->grant('admin.attachments.download', null);
        $this->grant('posts.read', null);
        $this->grant('comments.read', null);
        $this->grant('attachments.download', null);

        $this->resetPermissionMiddlewareCache();

        // 픽스처 전제 — 스코프 메타가 실리지 않으면 수정 전·후 모두 「통과」해 무엇도 검증하지 못한다
        $this->assertSame('board', PermissionHelper::getResourceRouteKey("sirsoft-board.{$this->board->slug}.admin.manage"));
    }

    /**
     * 위임 역할에 이 게시판 권한을 스코프와 함께 줍니다 (권한 행에 스코프 메타 포함).
     *
     * @param  string  $key  권한 키 (예: 'admin.manage')
     * @param  ScopeType|null  $scope  스코프 (null = 전체)
     * @return void 반환값 없음
     */
    private function grant(string $key, ?ScopeType $scope): void
    {
        // 키에 점이 있어(admin.manage) 점 경로로 읽으면 중첩 키로 해석된다 — 배열로 받아 꺼낸다
        $definition = config('sirsoft-board.board_permission_definitions', [])[$key] ?? [];
        $permission = Permission::updateOrCreate(
            ['identifier' => "sirsoft-board.{$this->board->slug}.{$key}"],
            [
                'name' => ['ko' => $key, 'en' => $key],
                'type' => str_starts_with($key, 'admin.') ? 'admin' : 'user',
                'resource_route_key' => $definition['scope']['resource_route_key'] ?? null,
                'owner_key' => $definition['scope']['owner_key'] ?? null,
            ]
        );
        $this->delegateRole->permissions()->syncWithoutDetaching([
            $permission->id => ['scope_type' => $scope?->value],
        ]);
    }

    /**
     * 위임 관리자가 이 게시판을 만든 것으로 바꿉니다 (대조군 — 자기 게시판).
     *
     * @return void 반환값 없음
     */
    private function makeDelegateTheCreator(): void
    {
        $this->board->forceFill(['created_by' => $this->delegate->id])->save();
    }

    /**
     * 관리자 게시글 API 주소.
     *
     * @param  string  $path  하위 경로
     * @return string 주소
     */
    private function adminPostUrl(string $path = ''): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts{$path}";
    }

    /**
     * 관리자 댓글 API 주소.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $path  하위 경로
     * @return string 주소
     */
    private function adminCommentUrl(int $postId, string $path = ''): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}/comments{$path}";
    }

    /**
     * 게시글에 첨부파일 행을 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $hash  첨부 해시
     * @return void 반환값 없음
     */
    private function createAttachment(int $postId, string $hash): void
    {
        DB::table('board_attachments')->insert([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'original_filename' => 'doc.pdf',
            'stored_filename' => "{$hash}.pdf",
            'hash' => $hash,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'path' => 'attachments/doc.pdf',
            'collection' => 'attachments',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ==========================================
    // B4 — 블라인드·복원 (미들웨어가 admin.manage 하나뿐인 경로)
    // ==========================================

    /**
     * 남의 게시판 글 블라인드는 403(500 아님)이고 상태가 바뀌지 않는다.
     *
     * @scenario case=others_board_blind_forbidden
     *
     * @effects others_board_blind_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_cannot_blind_post_on_others_board(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $response = $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl("/{$postId}/blind"), ['reason' => '위임 범위 밖']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('board_posts', ['id' => $postId, 'status' => 'published']);
    }

    /**
     * 자기가 만든 게시판에서는 블라인드가 그대로 된다 (대조군).
     *
     * @scenario case=own_board_blind_allowed
     *
     * @effects own_board_blind_allowed
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_can_blind_post_on_own_board(): void
    {
        $this->makeDelegateTheCreator();
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl("/{$postId}/blind"), ['reason' => '내 게시판'])
            ->assertOk();

        $this->assertDatabaseHas('board_posts', ['id' => $postId, 'status' => 'blinded']);
    }

    /**
     * 소유역할 스코프는 게시판을 만든 사람과 역할을 공유하면 통과한다.
     *
     * @scenario case=role_peer_board_blind_allowed
     *
     * @effects role_peer_board_blind_allowed
     *
     * @return void 반환값 없음
     */
    public function test_role_scoped_manager_can_blind_post_on_role_peer_board(): void
    {
        $this->grant('admin.manage', ScopeType::Role);
        $this->owner->roles()->attach($this->delegateRole->id);
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl("/{$postId}/blind"), ['reason' => '역할 공유'])
            ->assertOk();

        $this->assertDatabaseHas('board_posts', ['id' => $postId, 'status' => 'blinded']);
    }

    /**
     * 남의 게시판 글 복원은 403 이고 상태가 바뀌지 않는다.
     *
     * @scenario case=others_board_restore_forbidden
     *
     * @effects others_board_restore_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_cannot_restore_post_on_others_board(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl("/{$postId}/restore"), ['reason' => '위임 범위 밖'])
            ->assertStatus(403);

        $this->assertDatabaseHas('board_posts', ['id' => $postId, 'status' => 'blinded']);
    }

    /**
     * 남의 게시판 일괄 블라인드·복원·삭제는 전량 403 이고 어느 글도 바뀌지 않는다.
     *
     * @scenario case=others_board_bulk_forbidden_all_or_nothing
     *
     * @effects others_board_bulk_forbidden_all_or_nothing
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_cannot_bulk_act_on_others_board(): void
    {
        $published = $this->createTestPost(['status' => 'published']);
        $blinded = $this->createTestPost(['status' => 'blinded']);

        $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl('/bulk-blind'), ['ids' => [$published], 'reason' => 'x'])
            ->assertStatus(403);
        $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl('/bulk-restore'), ['ids' => [$blinded], 'reason' => 'x'])
            ->assertStatus(403);
        $this->actingAs($this->delegate)
            ->deleteJson($this->adminPostUrl('/bulk'), ['ids' => [$published, $blinded]])
            ->assertStatus(403);

        $this->assertDatabaseHas('board_posts', ['id' => $published, 'status' => 'published', 'deleted_at' => null]);
        $this->assertDatabaseHas('board_posts', ['id' => $blinded, 'status' => 'blinded', 'deleted_at' => null]);
    }

    /**
     * 자기 게시판 일괄 블라인드는 그대로 된다 (대조군).
     *
     * @scenario case=own_board_bulk_allowed
     *
     * @effects own_board_bulk_allowed
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_can_bulk_blind_on_own_board(): void
    {
        $this->makeDelegateTheCreator();
        $postId = $this->createTestPost(['status' => 'published']);

        $this->actingAs($this->delegate)
            ->patchJson($this->adminPostUrl('/bulk-blind'), ['ids' => [$postId], 'reason' => 'x'])
            ->assertOk()
            ->assertJsonPath('data.affected_count', 1);
    }

    /**
     * 남의 게시판 댓글 블라인드·복원은 403 이다.
     *
     * @scenario case=others_board_comment_blind_restore_forbidden
     *
     * @effects others_board_comment_blind_restore_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_cannot_blind_or_restore_comment_on_others_board(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);
        $published = $this->createTestComment($postId, ['status' => 'published']);
        $blinded = $this->createTestComment($postId, ['status' => 'blinded']);

        $this->actingAs($this->delegate)
            ->patchJson($this->adminCommentUrl($postId, "/{$published}/blind"), ['reason' => 'x'])
            ->assertStatus(403);
        $this->actingAs($this->delegate)
            ->patchJson($this->adminCommentUrl($postId, "/{$blinded}/restore"), ['reason' => 'x'])
            ->assertStatus(403);

        $this->assertDatabaseHas('board_comments', ['id' => $published, 'status' => 'published']);
        $this->assertDatabaseHas('board_comments', ['id' => $blinded, 'status' => 'blinded']);
    }

    // ==========================================
    // B3 — 게시판 관리 권한 직접 호출(삭제글 열람)
    // ==========================================

    /**
     * 남의 게시판 삭제글은 관리자 상세에서 열리지 않는다 — 관리 권한이 없는 사람과 같은 403(삭제글 접근 거부).
     *
     * @scenario case=others_board_deleted_post_hidden
     *
     * @effects others_board_deleted_post_hidden
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_cannot_open_deleted_post_on_others_board(): void
    {
        $postId = $this->createTestPost(['status' => 'deleted', 'deleted_at' => now()]);

        $this->actingAs($this->delegate)
            ->getJson($this->adminPostUrl("/{$postId}"))
            ->assertStatus(403)
            ->assertJsonPath('message', __('sirsoft-board::messages.posts.deleted_post_access_denied'));
    }

    /**
     * 자기 게시판 삭제글은 관리자 상세에서 열린다 (대조군).
     *
     * @scenario case=own_board_deleted_post_visible
     *
     * @effects own_board_deleted_post_visible
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_can_open_deleted_post_on_own_board(): void
    {
        $this->makeDelegateTheCreator();
        $postId = $this->createTestPost(['status' => 'deleted', 'deleted_at' => now()]);

        $this->actingAs($this->delegate)
            ->getJson($this->adminPostUrl("/{$postId}"))
            ->assertOk();
    }

    // ==========================================
    // B2 — SecretContentGate 규칙 4 (게시판 관리 권한으로 비밀글 열람)
    // ==========================================

    /**
     * 비밀글 조회 권한 없이 게시판 관리 권한(본인만)만 가진 위임 관리자는 남의 게시판 비밀글 본문을 못 본다.
     *
     * @scenario case=others_board_secret_content_masked
     *
     * @effects others_board_secret_content_masked
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_sees_secret_content_masked_on_others_board(): void
    {
        $postId = $this->createTestPost([
            'is_secret' => true,
            'content' => 'OTHER-BOARD-SECRET-BODY',
            'user_id' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->delegate)->getJson($this->adminPostUrl("/{$postId}"));

        $response->assertOk();
        $this->assertNull($response->json('data.content'));
    }

    /**
     * 자기 게시판 비밀글은 게시판 관리 권한으로 본문이 보인다 (대조군).
     *
     * @scenario case=own_board_secret_content_visible
     *
     * @effects own_board_secret_content_visible
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_sees_secret_content_on_own_board(): void
    {
        $this->makeDelegateTheCreator();
        $postId = $this->createTestPost([
            'is_secret' => true,
            'content' => 'OWN-BOARD-SECRET-BODY',
            'user_id' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->delegate)->getJson($this->adminPostUrl("/{$postId}"));

        $response->assertOk();
        $this->assertSame('OWN-BOARD-SECRET-BODY', $response->json('data.content'));
    }

    // ==========================================
    // B5 — 삭제·블라인드 글 첨부
    // ==========================================

    /**
     * 남의 게시판 삭제글·블라인드글 첨부 다운로드는 403 이다.
     *
     * @scenario case=others_board_deleted_blinded_attachment_forbidden
     *
     * @effects others_board_deleted_blinded_attachment_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_cannot_download_deleted_or_blinded_post_attachment_on_others_board(): void
    {
        $deleted = $this->createTestPost(['status' => 'deleted', 'deleted_at' => now(), 'attachments_count' => 1]);
        $blinded = $this->createTestPost(['status' => 'blinded', 'attachments_count' => 1, 'user_id' => $this->owner->id]);
        $this->createAttachment($deleted, 'bmsdelAAAAAA');
        $this->createAttachment($blinded, 'bmsblindAAAA');

        $this->actingAs($this->delegate, 'sanctum')
            ->get("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/bmsdelAAAAAA")
            ->assertStatus(403);
        $this->actingAs($this->delegate, 'sanctum')
            ->get("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/bmsblindAAAA")
            ->assertStatus(403);
    }

    /**
     * 자기 게시판 삭제글 첨부는 권한 게이트를 통과한다(파일이 없어 404 여도 403 은 아님 — 대조군).
     *
     * @scenario case=own_board_deleted_attachment_passes_gate
     *
     * @effects own_board_deleted_attachment_passes_gate
     *
     * @return void 반환값 없음
     */
    public function test_self_scoped_manager_passes_attachment_gate_on_own_board(): void
    {
        $this->makeDelegateTheCreator();
        $deleted = $this->createTestPost(['status' => 'deleted', 'deleted_at' => now(), 'attachments_count' => 1]);
        $this->createAttachment($deleted, 'bmsownAAAAAA');

        $status = $this->actingAs($this->delegate, 'sanctum')
            ->get("/api/modules/sirsoft-board/boards/{$this->board->slug}/attachment/bmsownAAAAAA")
            ->getStatusCode();

        $this->assertNotSame(403, $status);
    }

    // ==========================================
    // B15 — 화면 권한 맵(can_manage)과 서버 판정 일치
    // ==========================================

    /**
     * 남의 게시판 글 상세의 can_manage 는 사용자·관리자 화면 모두 false 다.
     *
     * @scenario case=others_board_can_manage_false
     *
     * @effects others_board_can_manage_false
     *
     * @return void 반환값 없음
     */
    public function test_post_abilities_can_manage_false_on_others_board(): void
    {
        $postId = $this->createTestPost(['status' => 'published']);

        $user = $this->actingAs($this->delegate, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");
        $user->assertOk();
        $this->assertFalse($user->json('data.abilities.can_manage'));

        $admin = $this->actingAs($this->delegate)->getJson($this->adminPostUrl("/{$postId}"));
        $admin->assertOk();
        $this->assertFalse($admin->json('data.abilities.can_manage'));
    }

    /**
     * 게시판 정보 응답의 사용자 권한 맵(can_manage·can_access_admin)도 남의 게시판에서는 false 다.
     *
     * 게시판 정보 응답은 역할·권한 행을 직접 읽어 권한 맵을 만든다 — 보유만 보면 관리 버튼·관리자 화면 링크가
     * 켜지고 서버는 거부한다.
     *
     * @scenario case=others_board_board_abilities_false
     *
     * @effects others_board_board_abilities_false
     *
     * @return void 반환값 없음
     */
    public function test_board_resource_abilities_false_on_others_board(): void
    {
        $response = $this->actingAs($this->delegate, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}?include_user_abilities=1");

        $response->assertOk();
        $this->assertFalse($response->json('data.user_abilities.can_manage'));
        $this->assertFalse($response->json('data.user_abilities.can_access_admin'));
    }

    /**
     * 자기 게시판 정보 응답의 can_manage·can_access_admin 은 true 다 (대조군).
     *
     * @scenario case=own_board_board_abilities_true
     *
     * @effects own_board_board_abilities_true
     *
     * @return void 반환값 없음
     */
    public function test_board_resource_abilities_true_on_own_board(): void
    {
        $this->makeDelegateTheCreator();

        $response = $this->actingAs($this->delegate, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}?include_user_abilities=1");

        $response->assertOk();
        $this->assertTrue($response->json('data.user_abilities.can_manage'));
        $this->assertTrue($response->json('data.user_abilities.can_access_admin'));
    }

    /**
     * 자기 게시판 글 상세의 can_manage 는 true 다 (대조군).
     *
     * @scenario case=own_board_can_manage_true
     *
     * @effects own_board_can_manage_true
     *
     * @return void 반환값 없음
     */
    public function test_post_abilities_can_manage_true_on_own_board(): void
    {
        $this->makeDelegateTheCreator();
        $postId = $this->createTestPost(['status' => 'published']);

        $admin = $this->actingAs($this->delegate)->getJson($this->adminPostUrl("/{$postId}"));
        $admin->assertOk();
        $this->assertTrue($admin->json('data.abilities.can_manage'));
    }
}
