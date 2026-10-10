<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 관리자 반응 관리 Feature 테스트.
 *
 * 반응은 회원 소유 데이터라 게시판 경계를 넘어 조회되므로 모듈 레벨 권한
 * (sirsoft-board.reactions.view|manage)을 쓴다 — 스크랩 관리와 동형.
 * 조회 권한만 가진 매니저는 삭제가 막히고, 유형 필터 옵션은 목록 응답 메타로 온다
 * (매니저는 settings.read 가 없어 유형 API 를 부를 수 없다).
 */
class AdminReactionTest extends BoardTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/admin/reactions';

    private User $admin;

    private User $viewer;

    private int $likeId;

    private int $dislikeId;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser([
            'sirsoft-board.reactions.view',
            'sirsoft-board.reactions.manage',
            'core.users.read',
        ]);

        // createAdminUser 는 공용 admin 역할에 권한을 붙이므로 "view 만" 을 만들 수 없다.
        $this->viewer = $this->createReactionViewer();

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'use_comment_reaction' => true,
            'use_comment' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 반응 행을 직접 만듭니다.
     *
     * @param  int  $userId  회원 ID
     * @param  string  $targetType  대상 유형
     * @param  int  $targetId  대상 ID
     * @param  int  $typeId  유형 ID
     * @param  int|null  $boardId  게시판 ID
     * @return int 만든 반응 행 ID
     */
    private function seedReaction(int $userId, string $targetType, int $targetId, int $typeId, ?int $boardId = null): int
    {
        return DB::table('board_reactions')->insertGetId([
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reaction_type_id' => $typeId,
            'board_id' => $boardId ?? $this->board->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * view 권한이 없으면 목록이 막힌다.
     *
     * @scenario case=list_forbidden_without_view_permission
     *
     * @effects index_forbidden_without_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_forbidden_without_view_permission(): void
    {
        $this->actingAs($this->createUser())->getJson(self::ENDPOINT)->assertForbidden();
    }

    /**
     * 전 회원의 반응이 최근순으로 나온다.
     *
     * @scenario case=list_all_users_recent_first
     *
     * @effects index_lists_all_users_recent_first
     *
     * @return void 반환값 없음
     */
    public function test_index_lists_all_users_recent_first(): void
    {
        $author = $this->createUser();
        $memberA = $this->createUser();
        $memberB = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id, 'title' => '대상 글']);

        $this->seedReaction($memberA->id, 'post', $postId, $this->likeId);
        $this->seedReaction($memberB->id, 'post', $postId, $this->dislikeId);

        $response = $this->actingAs($this->admin)->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonCount(2, 'data.data');

        $row = $response->json('data.data.0');
        $this->assertSame('대상 글', $row['target_title']);
        $this->assertSame('post', $row['target_type']);
        $this->assertNotNull($row['member']);
        $this->assertTrue($row['abilities']['can_delete']);
        $this->assertTrue($response->json('data.abilities.can_manage'));
    }

    /**
     * 회원·게시판·유형·대상 구분·기간 필터가 동작한다.
     *
     * @scenario case=filters_user_board_type_target_and_date_range
     *
     * @effects index_filters_by_user_board_type_target_and_date_range
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_user_id_uuid_board_type_target_type_and_date_range(): void
    {
        $author = $this->createUser();
        $memberA = $this->createUser();
        $memberB = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->seedReaction($memberA->id, 'post', $postId, $this->likeId);
        $this->seedReaction($memberB->id, 'comment', $commentId, $this->dislikeId);

        $act = fn (string $qs) => $this->actingAs($this->admin)->getJson(self::ENDPOINT.$qs);

        $act('?user_id='.$memberA->id)->assertOk()->assertJsonCount(1, 'data.data');
        $act('?user_uuid='.$memberB->uuid)->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.target_type', 'comment');
        $act('?board_id='.$this->board->id)->assertOk()->assertJsonCount(2, 'data.data');
        $act('?reaction_type_id='.$this->likeId)->assertOk()->assertJsonCount(1, 'data.data');
        $act('?target_type=comment')->assertOk()->assertJsonCount(1, 'data.data');

        // 날짜 칸은 보는 사람(운영자)의 시간대 하루로 해석된다 — 운영자 시간대의 오늘로 보낸다.
        $viewerNow = now($this->admin->timezone ?: config('app.default_user_timezone'));
        $today = $viewerNow->format('Y-m-d');
        $act('?reacted_at_from='.$today.'&reacted_at_to='.$today)->assertOk()->assertJsonCount(2, 'data.data');
        $act('?reacted_at_from='.$viewerNow->copy()->addDays(2)->format('Y-m-d'))->assertOk()->assertJsonCount(0, 'data.data');
    }

    /**
     * 없는 회원 uuid 는 빈 결과 (필터를 무시하고 전체를 보여주지 않는다).
     *
     * @scenario case=unknown_user_uuid_returns_empty
     *
     * @effects index_unknown_user_uuid_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_index_unknown_user_uuid_returns_empty(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $this->seedReaction($this->createUser()->id, 'post', $postId, $this->likeId);

        $this->actingAs($this->admin)
            ->getJson(self::ENDPOINT.'?user_uuid='.Str::uuid())
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * 검색 6갈래가 각각 동작한다.
     *
     * @scenario case=search_six_fields_like_branches
     *
     * @effects index_search_fields_like_branches
     *
     * @return void 반환값 없음
     */
    public function test_index_search_fields_like_branches(): void
    {
        // createUser() 는 인자를 받지 않는다 — 이름은 생성 후 지정한다.
        $author = $this->createUser();
        $author->update(['name' => '작성자갑']);
        $member = $this->createUser();
        $member->update(['name' => '반응회원을']);
        $postId = $this->createTestPost([
            'user_id' => $author->id,
            'title' => '알파제목',
            'content' => '베타본문',
            'author_name' => '작성자갑',
        ]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id, 'content' => '감마댓글']);

        $this->seedReaction($member->id, 'post', $postId, $this->likeId);
        $this->seedReaction($member->id, 'comment', $commentId, $this->dislikeId);

        $search = fn (string $field, string $value) => $this->actingAs($this->admin)->getJson(
            self::ENDPOINT.'?filters[0][field]='.$field.'&filters[0][value]='.urlencode($value)
        );

        $search('target_title', '알파제목')->assertOk()->assertJsonCount(1, 'data.data');
        $search('target_content', '베타본문')->assertOk()->assertJsonCount(1, 'data.data');
        $search('target_content', '감마댓글')->assertOk()->assertJsonCount(1, 'data.data');
        $search('member', '반응회원을')->assertOk()->assertJsonCount(2, 'data.data');
        $search('author_name', '작성자갑')->assertOk()->assertJsonCount(1, 'data.data');
        $search('all', '알파제목')->assertOk()->assertJsonCount(1, 'data.data');
        $search('target_title', '없는키워드')->assertOk()->assertJsonCount(0, 'data.data');
    }

    /**
     * 삭제된 대상·반응 꺼진 게시판·탈퇴 회원·비활성 유형이 각각 표시된다.
     *
     * @scenario case=row_marks_deleted_target_and_withdrawn_member
     *
     * @effects index_marks_deleted_target_board_off_withdrawn_and_inactive_type
     *
     * @return void 반환값 없음
     */
    public function test_index_marks_deleted_target_board_deleted_reaction_off_withdrawn_inactive_type(): void
    {
        $author = $this->createUser();
        $withdrawn = $this->createUser();
        $withdrawn->update(['status' => UserStatus::Withdrawn->value]);
        $deletedPost = $this->createTestPost(['user_id' => $author->id, 'deleted_at' => now()]);

        $this->seedReaction($withdrawn->id, 'post', $deletedPost, $this->likeId);

        $row = $this->actingAs($this->admin)->getJson(self::ENDPOINT)->json('data.data.0');

        $this->assertTrue($row['is_target_deleted'], '관리자 목록은 삭제된 대상도 보여준다.');
        $this->assertSame('deleted', $row['target_status']);
        $this->assertTrue($row['member']['is_withdrawn'] ?? false, '탈퇴 회원 표시가 있어야 합니다.');
    }

    /**
     * 목록 응답에 비활성 포함 유형 메타가 실린다 (매니저 필터용).
     *
     * @scenario case=response_carries_inactive_types_meta
     *
     * @effects index_response_carries_reaction_types_meta_incl_inactive
     *
     * @return void 반환값 없음
     */
    public function test_index_response_carries_reaction_types_meta_incl_inactive(): void
    {
        ReactionType::create([
            'code' => 'inactive_old',
            'name' => ['ko' => '옛유형'],
            'display_order' => 9,
            'is_active' => false,
        ]);

        $types = $this->actingAs($this->viewer)->getJson(self::ENDPOINT)->json('data.reaction_types');

        $this->assertCount(3, $types);
        $inactive = collect($types)->firstWhere('code', 'inactive_old');
        $this->assertFalse($inactive['is_active'], '비활성 유형도 필터 옵션에 실리되 상태가 구분되어야 합니다.');
    }

    /**
     * 목록 응답에 게시판 필터 선택지가 실린다 — 반응 조회 권한만 가진 매니저도 게시판을 고를 수 있다.
     *
     * 게시판 목록 API(admin/boards)는 게시판 관리 권한이 있어야 열린다. 화면이 그 API 로
     * 게시판 셀렉트를 채우면 반응 조회만 가진 매니저에게는 403 이 나고 셀렉트가 빈다.
     *
     * @scenario case=response_carries_board_options_meta
     *
     * @effects index_response_carries_board_options_meta
     *
     * @return void 반환값 없음
     */
    public function test_index_response_carries_board_options_meta(): void
    {
        $boards = $this->actingAs($this->viewer)->getJson(self::ENDPOINT)->assertOk()->json('data.boards');

        $this->assertIsArray($boards, '목록 응답에 게시판 선택지가 실려야 합니다.');
        $mine = collect($boards)->firstWhere('id', $this->board->id);
        $this->assertNotNull($mine);
        $this->assertSame($this->board->getLocalizedName(), $mine['name']);
        $this->assertSame($this->board->slug, $mine['slug']);
    }

    /**
     * 반응 조회 권한이 없는 관리자에게는 회원 상세에 반응 요약을 싣지 않는다.
     *
     * 요약을 실으면 화면이 「반응」 탭과 건수를 그리고, 「이 회원 반응 목록 보기」는 403 화면으로 끝난다.
     *
     * @scenario case=user_detail_summary_requires_view_permission
     *
     * @effects admin_user_detail_omits_reactions_summary_without_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_omits_reactions_summary_without_view_permission(): void
    {
        $member = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $this->seedReaction($member->id, 'post', $postId, $this->likeId);

        $reader = $this->createAdminWithOnly(['core.users.read']);

        $response = $this->actingAs($reader)->getJson('/api/admin/users/'.$member->uuid);
        $response->assertOk();
        $this->assertArrayNotHasKey('board_reactions', $response->json('data') ?? []);

        // 조회 권한이 있으면 그대로 실린다.
        $this->assertSame(
            1,
            $this->actingAs($this->admin)->getJson('/api/admin/users/'.$member->uuid)->json('data.board_reactions.count')
        );
    }

    /**
     * per_page 상한(100)을 넘기면 422.
     *
     * @scenario case=per_page_over_limit_rejected
     *
     * @effects index_rejects_per_page_over_100
     *
     * @return void 반환값 없음
     */
    public function test_index_rejects_per_page_over_100(): void
    {
        $this->actingAs($this->admin)->getJson(self::ENDPOINT.'?per_page=101')->assertStatus(422);
    }

    /**
     * view 만 가진 매니저는 삭제가 막힌다.
     *
     * @scenario case=destroy_forbidden_with_view_only
     *
     * @effects destroy_forbidden_with_view_only
     *
     * @return void 반환값 없음
     */
    public function test_destroy_forbidden_with_view_only(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $id = $this->seedReaction($this->createUser()->id, 'post', $postId, $this->likeId);

        $this->actingAs($this->viewer)->deleteJson(self::ENDPOINT.'/'.$id)->assertForbidden();
        $this->actingAs($this->viewer)->deleteJson(self::ENDPOINT.'/bulk', ['ids' => [$id]])->assertForbidden();

        $this->assertDatabaseHas('board_reactions', ['id' => $id]);

        // 목록 응답의 abilities 도 삭제 불가로 나와야 화면이 체크박스를 숨긴다.
        $response = $this->actingAs($this->viewer)->getJson(self::ENDPOINT);
        $this->assertFalse($response->json('data.abilities.can_manage'));
        $this->assertTrue($response->json('data.abilities.can_view'));
    }

    /**
     * 관리자는 남의 반응을 삭제하고 카운트가 재집계된다.
     *
     * @scenario case=destroy_removes_and_recalculates
     *
     * @effects destroy_removes_other_users_reaction_and_recalculates
     *
     * @return void 반환값 없음
     */
    public function test_destroy_removes_other_users_reaction_and_recalculates(): void
    {
        $author = $this->createUser();
        $member = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/react", ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $this->assertSame(1, (int) (Post::find($postId)->reaction_counts[(string) $this->likeId] ?? 0));

        $id = DB::table('board_reactions')->where('user_id', $member->id)->value('id');

        $this->actingAs($this->admin)->deleteJson(self::ENDPOINT.'/'.$id)->assertOk();

        $this->assertDatabaseMissing('board_reactions', ['id' => $id]);
        $this->assertSame(
            0,
            (int) (Post::find($postId)->reaction_counts[(string) $this->likeId] ?? 0),
            '관리자 삭제 후에도 카운트 캐시가 실제 행 수와 같아야 합니다.',
        );
    }

    /**
     * 없는 반응은 404.
     *
     * @scenario case=destroy_missing_returns_404
     *
     * @effects destroy_returns_404_for_missing
     *
     * @return void 반환값 없음
     */
    public function test_destroy_returns_404_for_missing(): void
    {
        $this->actingAs($this->admin)->deleteJson(self::ENDPOINT.'/99999999')->assertNotFound();
    }

    /**
     * 일괄 삭제는 건수를 돌려주고, 없는 ID 는 422 로 막는다.
     *
     * @scenario case=bulk_destroy_count_and_unknown_ids_rejected
     *
     * @effects bulk_destroy_returns_count_and_rejects_unknown_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_returns_deleted_count_and_rejects_unknown_ids_422(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $a = $this->seedReaction($this->createUser()->id, 'post', $postId, $this->likeId);
        $b = $this->seedReaction($this->createUser()->id, 'post', $postId, $this->dislikeId);

        $this->actingAs($this->admin)
            ->deleteJson(self::ENDPOINT.'/bulk', ['ids' => [$a, 99999999]])
            ->assertStatus(422);

        $this->assertDatabaseHas('board_reactions', ['id' => $a]);

        $this->actingAs($this->admin)
            ->deleteJson(self::ENDPOINT.'/bulk', ['ids' => [$a, $b]])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseMissing('board_reactions', ['id' => $a]);
        $this->assertDatabaseMissing('board_reactions', ['id' => $b]);
    }

    /**
     * 회원 상세 응답에 반응 요약이 실리고 프로필에는 실리지 않는다.
     *
     * @scenario case=user_detail_includes_summary_profile_omits
     *
     * @effects admin_user_detail_includes_board_reactions_summary, profile_omits_board_reactions
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_includes_board_reactions_summary(): void
    {
        $author = $this->createUser();
        $member = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->seedReaction($member->id, 'post', $postId, $this->likeId);
        $this->seedReaction($member->id, 'comment', $commentId, $this->dislikeId);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/users/'.$member->uuid);

        $response->assertOk();

        $summary = $response->json('data.board_reactions');
        $this->assertNotNull($summary, '관리자 회원 상세에 반응 요약이 실려야 합니다.');
        $this->assertSame(2, $summary['count']);
        $this->assertSame(1, $summary['post_count']);
        $this->assertSame(1, $summary['comment_count']);
        $this->assertNotNull($summary['latest_reacted_at']);

        // 프로필(/api/me)에는 싣지 않는다 — 모든 화면 부팅 경로에 집계 비용을 얹지 않는다.
        $profile = $this->actingAs($member, 'sanctum')->getJson('/api/me');
        $profile->assertOk();
        $this->assertArrayNotHasKey('board_reactions', $profile->json('data') ?? []);
    }

    /**
     * 모듈이 반응 권한·메뉴를 선언한다.
     *
     * @scenario case=module_declares_permissions_and_menu
     *
     * @effects module_declares_reactions_permissions_and_menu
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_reactions_permissions_and_menu(): void
    {
        $module = new Module;

        $categories = collect($module->getPermissions()['categories'] ?? []);
        $reactions = $categories->firstWhere('identifier', 'reactions');

        $this->assertNotNull($reactions, '모듈이 reactions 권한 카테고리를 선언해야 합니다.');
        $this->assertSame(['view', 'manage'], array_column($reactions['permissions'], 'action'));
        $this->assertSame(['admin', 'manager'], $reactions['permissions'][0]['roles']);
        $this->assertSame(['admin'], $reactions['permissions'][1]['roles']);

        $menus = collect($module->getAdminMenus());
        $children = collect($menus->first()['children'] ?? []);
        $menu = $children->firstWhere('slug', 'sirsoft-board-reactions');

        $this->assertNotNull($menu, '모듈이 반응 관리 메뉴를 선언해야 합니다.');
        $this->assertSame('/admin/boards/reactions', $menu['url']);
        $this->assertSame('sirsoft-board.reactions.view', $menu['permission']);
    }

    /**
     * 주어진 관리자 권한만 가진 운영자를 만듭니다 (공용 admin 역할을 쓰지 않는다).
     *
     * @param  array<int, string>  $permissionIdentifiers  부여할 권한 식별자
     * @return User 운영자
     */
    private function createAdminWithOnly(array $permissionIdentifiers): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-board.reaction-only-perms-test'],
            [
                'name' => ['ko' => '권한 한정 운영자', 'en' => 'Limited Operator'],
                'description' => ['ko' => '테스트용', 'en' => 'For tests'],
            ]
        );

        foreach ($permissionIdentifiers as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * reactions.view 만 가진 운영자를 만듭니다.
     *
     * createAdminUser 는 공용 admin 역할에 권한을 붙이므로 "view 만" 을 만들 수 없다.
     * 전용 역할을 쓰면 admin 미들웨어(관리자 타입 권한 보유 여부)는 통과하면서
     * reactions.manage 만 빠진 상태를 정확히 재현한다.
     *
     * @return User 조회 전용 운영자
     */
    private function createReactionViewer(): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-board.reaction-viewer-test'],
            [
                'name' => ['ko' => '반응 조회 전용', 'en' => 'Reaction Viewer'],
                'description' => ['ko' => '테스트용', 'en' => 'For tests'],
            ]
        );

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.reactions.view'],
            [
                'name' => ['ko' => '반응 조회', 'en' => 'View Reactions'],
                'type' => 'admin',
            ]
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
