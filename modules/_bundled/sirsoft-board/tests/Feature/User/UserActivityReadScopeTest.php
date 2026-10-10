<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 활동 목록 읽기 스코프 테스트 (KVE-2026-2371 동반)
 *
 * 여러 게시판을 가로지르는 활동 목록이 게시판별 읽기 권한(posts.read)을 거치지 않아,
 * 게시판 목록은 401/403 인 회원전용 게시판의 글이 제목·본문 미리보기째 나가던 결함을 고정한다.
 *
 * - 공개 프로필 작성글(`users/{uuid}/posts`): 열람자가 읽을 수 없는 게시판의 행을 뺀다. 본인은 예외.
 * - 내가 댓글 단 글(`me/board-activities?activity_type=commented`): 행이 남의 글이라 본인 화면에서도
 *   읽을 수 없게 된 게시판의 행을 뺀다. 작성글(authored) 본인 화면은 권한 변화와 무관하게 유지.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-secret-content-bypass-paths.yaml 참조.
 */
class UserActivityReadScopeTest extends BoardTestCase
{
    private Board $openBoard;

    private User $victim;

    private User $member;

    private User $noRoleUser;

    /**
     * 이 테스트 전용 게시판 슬러그를 반환합니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'activity-scope-members';
    }

    /**
     * 게시판 권한·회원·게시글을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이 게시판(회원전용): 비회원 읽기 없음, user 역할만 읽기
        $this->setGuestPermissions([]);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        // 대조군: 비회원도 읽는 공개 게시판
        $this->openBoard = Board::updateOrCreate(
            ['slug' => 'activity-scope-open'],
            $this->getDefaultBoardAttributes('activity-scope-open')
        );
        DB::table('board_comments')->where('board_id', $this->openBoard->id)->delete();
        DB::table('board_posts')->where('board_id', $this->openBoard->id)->delete();
        $guestRole = Role::where('identifier', 'guest')->first();
        $userRole = Role::where('identifier', 'user')->first();
        foreach ([$guestRole, $userRole] as $role) {
            $perm = Permission::firstOrCreate(
                ['identifier' => 'sirsoft-board.activity-scope-open.posts.read'],
                ['name' => ['ko' => 'posts.read', 'en' => 'posts.read'], 'type' => 'user']
            );
            $role?->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $this->resetPermissionMiddlewareCache();

        $this->victim = User::factory()->create();
        $this->victim->roles()->attach($userRole->id);
        $this->member = User::factory()->create();
        $this->member->roles()->attach($userRole->id);
        // 공개 게시판만 읽는 회원 (회원전용 게시판 읽기 권한 없음)
        $this->noRoleUser = User::factory()->create();
        $openOnly = Role::create(['identifier' => 'activity-open-only', 'name' => ['ko' => '공개만', 'en' => 'Open only']]);
        $openOnly->permissions()->syncWithoutDetaching([
            Permission::where('identifier', 'sirsoft-board.activity-scope-open.posts.read')->value('id'),
        ]);
        $this->noRoleUser->roles()->attach($openOnly->id);

        $this->createTestPost([
            'title' => '회원전용 글',
            'content' => 'MEMBERS-ONLY-BODY',
            'user_id' => $this->victim->id,
        ]);
        $this->createTestPost([
            'board_id' => $this->openBoard->id,
            'title' => '공개 글',
            'content' => 'OPEN-BODY',
            'user_id' => $this->victim->id,
        ]);
    }

    /**
     * 공개 프로필 작성글 목록의 게시판 슬러그 목록.
     *
     * @param  User|null  $viewer  열람자 (null 이면 비회원)
     * @return array<int, string> 행별 게시판 슬러그
     */
    private function publicPostBoards(?User $viewer): array
    {
        $request = $viewer ? $this->actingAs($viewer, 'sanctum') : $this;
        $response = $request->getJson("/api/modules/sirsoft-board/users/{$this->victim->uuid}/posts");
        $response->assertOk();

        return collect($response->json('data.data'))->pluck('board_slug')->sort()->values()->all();
    }

    /**
     * 비회원이 보는 공개 프로필 작성글에서 회원전용 게시판 글이 빠진다.
     *
     * @scenario viewer=guest
     *
     * @effects guest_public_profile_excludes_unreadable_board
     *
     * @return void 반환값 없음
     */
    public function test_guest_public_profile_excludes_members_only_board(): void
    {
        $this->assertSame(['activity-scope-open'], $this->publicPostBoards(null));
    }

    /**
     * 페이지 total 도 제외된 행을 세지 않는다 (결과를 걸러 total 과 어긋나지 않게 쿼리 단계에서 뺀다).
     *
     * @scenario viewer=guest
     *
     * @effects public_profile_total_matches_visible_rows
     *
     * @return void 반환값 없음
     */
    public function test_public_profile_total_matches_visible_rows(): void
    {
        $response = $this->getJson("/api/modules/sirsoft-board/users/{$this->victim->uuid}/posts");
        $response->assertOk();

        $this->assertSame(1, $response->json('data.total'));
    }

    /**
     * 회원전용 게시판 읽기 권한이 없는 회원에게도 그 게시판 글이 빠진다.
     *
     * @scenario viewer=no_read_member
     *
     * @effects member_without_read_excluded_from_unreadable_board
     *
     * @return void 반환값 없음
     */
    public function test_member_without_board_read_excludes_members_only_board(): void
    {
        $this->assertSame(['activity-scope-open'], $this->publicPostBoards($this->noRoleUser));
    }

    /**
     * 두 게시판을 모두 읽을 수 있는 회원은 두 게시판 글을 다 본다.
     *
     * @scenario viewer=reader
     *
     * @effects reader_sees_all_readable_boards
     *
     * @return void 반환값 없음
     */
    public function test_member_with_read_sees_both_boards(): void
    {
        $this->assertSame(['activity-scope-members', 'activity-scope-open'], $this->publicPostBoards($this->member));
    }

    /**
     * 본인 프로필은 읽기 권한과 무관하게 자기 글이 모두 남는다.
     *
     * @scenario viewer=owner
     *
     * @effects owner_public_profile_not_filtered
     *
     * @return void 반환값 없음
     */
    public function test_owner_public_profile_is_not_filtered(): void
    {
        // 본인 글은 읽기 권한과 무관하게 남는다 — 권한을 떼도 자기 프로필에서 사라지지 않는다
        $this->victim->roles()->detach();

        $this->assertSame(['activity-scope-members', 'activity-scope-open'], $this->publicPostBoards($this->victim->fresh()));
    }

    /**
     * 댓글을 단 뒤 그 게시판 읽기 권한을 잃으면 「내가 댓글 단 글」에서 그 게시판 행이 빠진다.
     * 작성글(authored) 본인 화면은 그대로 남는다.
     *
     * @scenario viewer=owner
     *
     * @effects commented_list_drops_board_after_read_revoked, own_authored_list_kept_after_read_revoked
     *
     * @return void 반환값 없음
     */
    public function test_commented_list_drops_board_after_read_permission_revoked(): void
    {
        $postId = DB::table('board_posts')->where('board_id', $this->board->id)->value('id');
        $commenter = User::factory()->create();
        $role = Role::create(['identifier' => 'activity-commenter', 'name' => ['ko' => '댓글러', 'en' => 'Commenter']]);
        $readPerm = Permission::where('identifier', "sirsoft-board.{$this->board->slug}.posts.read")->first();
        $role->permissions()->syncWithoutDetaching([$readPerm->id]);
        $commenter->roles()->attach($role->id);
        $this->createTestComment($postId, ['user_id' => $commenter->id, 'content' => 'MY-COMMENT']);
        $this->createTestPost(['title' => '내 글', 'user_id' => $commenter->id]);

        $before = $this->actingAs($commenter, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/me/board-activities?activity_type=commented');
        $before->assertOk();
        $this->assertSame(['activity-scope-members'], collect($before->json('data.data'))->pluck('board_slug')->all());

        $role->permissions()->detach($readPerm->id);
        $commenter = $commenter->fresh();

        $after = $this->actingAs($commenter, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/me/board-activities?activity_type=commented');
        $after->assertOk();
        $this->assertSame([], $after->json('data.data'));
        $this->assertSame(0, $after->json('data.total'));

        $authored = $this->actingAs($commenter, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/me/board-activities');
        $authored->assertOk();
        $this->assertSame(['activity-scope-members'], collect($authored->json('data.data'))->pluck('board_slug')->all());
    }
}
