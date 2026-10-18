<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 게시판 API 읽기 권한 회귀 테스트 (7.1.0 abilities 게이트 최종 점검 S2·S7)
 *
 * - S2: 게시글 상세가 댓글 열람 권한(comments.read) 없이도 댓글 원문을 응답에 실었다(화면만 can_read_comments 로 가림).
 *   comment_page 페이지네이션 경로도 같다.
 * - S7: 신고·스크랩·반응 엔드포인트가 로그인만 확인해 그 게시판 글 읽기 권한이 없는 회원도 호출할 수 있었다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-user-read-permission-gate.yaml 참조.
 */
class UserBoardReadPermissionGateTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-read-permission-gate';
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
            'name' => ['ko' => '읽기 권한 게이트 게시판', 'en' => 'Read Gate Board'],
            'is_active' => true,
            'use_comment' => true,
            'use_report' => true,
            'use_reaction' => true,
            'use_comment_reaction' => true,
            'max_comment_depth' => 10,
        ];
    }

    /**
     * 게시판 권한이 없는 회원을 만듭니다(user 역할만).
     *
     * @return User 회원
     */
    private function memberWithoutBoardPermission(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $user->roles()->attach($userRole->id);
        }

        return $user->fresh();
    }

    /**
     * 댓글 열람 권한이 없는 비회원에게 상세 응답은 댓글 원문을 싣지 않는다.
     *
     * @scenario case=detail_without_comments_read
     *
     * @effects comments_not_exposed_without_read
     *
     * @return void 반환값 없음
     */
    public function test_post_detail_omits_comments_without_comments_read(): void
    {
        $this->setGuestPermissions(['posts.read']);
        $postId = $this->createTestPost();
        $this->createTestComment($postId, ['content' => '권한없이보이면안되는댓글원문']);
        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}";

        $this->getJson($url)->assertOk()->assertDontSee('권한없이보이면안되는댓글원문');
        $this->getJson($url.'?comment_page=1')->assertOk()->assertDontSee('권한없이보이면안되는댓글원문');
    }

    /**
     * 댓글 열람 권한이 있으면 종전처럼 댓글이 실린다.
     *
     * @scenario case=detail_with_comments_read
     *
     * @effects comments_exposed_with_read
     *
     * @return void 반환값 없음
     */
    public function test_post_detail_includes_comments_with_comments_read(): void
    {
        $this->setGuestPermissions(['posts.read', 'comments.read']);
        $postId = $this->createTestPost();
        $this->createTestComment($postId, ['content' => '권한있으면보이는댓글원문']);

        $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}")
            ->assertOk()
            ->assertSee('권한있으면보이는댓글원문');
    }

    /**
     * 글 읽기 권한이 없는 회원은 게시글 신고·스크랩·반응을 할 수 없다(403).
     *
     * @scenario case=post_actions_without_posts_read
     *
     * @effects post_actions_require_posts_read
     *
     * @return void 반환값 없음
     */
    public function test_post_report_scrap_react_require_posts_read(): void
    {
        $member = $this->memberWithoutBoardPermission();
        $postId = $this->createTestPost();
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}";

        $this->actingAs($member)->postJson($base.'/reports', ['reason_type' => 'spam', 'reason_detail' => '테스트 신고'])->assertForbidden();
        $this->actingAs($member)->postJson($base.'/scrap/toggle')->assertForbidden();
        $this->actingAs($member)->postJson($base.'/react', ['reaction_type' => 'like'])->assertForbidden();
    }

    /**
     * 관리자 진입 링크(can_access_admin)는 게시판 관리 권한에 더해 관리자 게시판 화면의 진입 권한(boards.read)도
     * 있어야 켜진다 — 관리 권한만 있으면 링크를 눌러도 관리자 화면이 403 이었다(basic-board-user #4).
     *
     * @scenario case=admin_link_without_boards_read
     *
     * @effects admin_link_requires_admin_screen_permission
     *
     * @return void 반환값 없음
     */
    public function test_can_access_admin_requires_boards_read(): void
    {
        $this->grantUserRolePermissions(['posts.read']);
        $postId = $this->createTestPost();
        $managerOnly = $this->memberWithAdminPermissions(["sirsoft-board.{$this->board->slug}.admin.manage"]);
        $withScreen = $this->memberWithAdminPermissions(["sirsoft-board.{$this->board->slug}.admin.manage", 'sirsoft-board.boards.read']);
        $list = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
        $detail = $list.'/'.$postId;

        $this->actingAs($managerOnly)->getJson($list)->assertOk()->assertJsonPath('data.abilities.can_access_admin', false);
        $this->actingAs($managerOnly)->getJson($detail)->assertOk()->assertJsonPath('data.abilities.can_access_admin', false);
        $this->actingAs($withScreen)->getJson($list)->assertOk()->assertJsonPath('data.abilities.can_access_admin', true);
        $this->actingAs($withScreen)->getJson($detail)->assertOk()->assertJsonPath('data.abilities.can_access_admin', true);
    }

    /**
     * user 역할 + 주어진 관리자 권한을 가진 회원을 만듭니다.
     *
     * @param  array<int, string>  $identifiers  관리자 권한 식별자
     * @return User 회원
     */
    private function memberWithAdminPermissions(array $identifiers): User
    {
        $user = $this->memberWithoutBoardPermission();
        $role = Role::create([
            'identifier' => 'board_admin_link_'.uniqid(),
            'name' => ['ko' => '관리 링크 역할', 'en' => 'Admin Link Role'],
            'is_active' => true,
        ]);
        foreach ($identifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        }
        $user->roles()->attach($role->id);

        return $user->fresh();
    }

    /**
     * 댓글 읽기 권한이 없는 회원은 댓글 신고·반응을 할 수 없다(403).
     *
     * @scenario case=comment_actions_without_comments_read
     *
     * @effects comment_actions_require_comments_read
     *
     * @return void 반환값 없음
     */
    public function test_comment_report_react_require_comments_read(): void
    {
        $this->grantUserRolePermissions(['posts.read']);
        $member = $this->memberWithoutBoardPermission();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId);
        $base = "/api/modules/sirsoft-board/boards/{$this->board->slug}/comments/{$commentId}";

        $this->actingAs($member)->postJson($base.'/reports', ['reason_type' => 'spam', 'reason_detail' => '테스트 신고'])->assertForbidden();
        $this->actingAs($member)->postJson($base.'/react', ['reaction_type' => 'like'])->assertForbidden();
    }
}
