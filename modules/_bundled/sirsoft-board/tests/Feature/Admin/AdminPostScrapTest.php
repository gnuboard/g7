<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Services\PostScrapService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 스크랩 관리 API 테스트
 *
 * 검증 목적 (이슈 #524 제보 ① — 관리자가 회원별 스크랩을 조회·관리할 수 없던 결함):
 * - 전 회원 스크랩 통합 목록: 최근 스크랩순, 회원·게시판·기간 필터
 * - 검색 대상 구분: 게시글 제목 / 게시글 내용 / 게시판명 / 회원 / 작성자명 / 전체
 * - 삭제글·스크랩 중지 게시판의 상태 노출 (관리자는 배지로 구분하고 제목도 본다)
 * - 권한 분리: view 는 조회만, 삭제는 manage 필요
 * - 삭제 시 활동 로그의 주어는 스크랩 소유자 (관리자가 아님)
 * - 회원 상세 응답에만 스크랩 요약 주입 (/api/me 에는 미주입)
 *
 * URL:
 * - GET    /api/modules/sirsoft-board/admin/scraps
 * - DELETE /api/modules/sirsoft-board/admin/scraps/{id}
 * - DELETE /api/modules/sirsoft-board/admin/scraps/bulk
 *
 * @scenario endpoint=admin_scrap_index,actor=admin
 *
 * @effects admin_scrap_index_requires_view_permission, admin_scrap_index_lists_all_members
 */
#[Group('board')]
#[Group('admin')]
#[Group('scrap')]
class AdminPostScrapTest extends BoardTestCase
{
    private User $admin;

    private User $viewer;

    private User $memberA;

    private User $memberB;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-scrap-api';
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
            'name' => ['ko' => '관리자 스크랩 테스트 게시판', 'en' => 'Admin Scrap Test Board'],
            'is_active' => true,
            'use_scrap' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write']);

        $this->admin = $this->createAdminUser([
            'sirsoft-board.scraps.view',
            'sirsoft-board.scraps.manage',
            // 회원 상세 요약 주입 검증에 필요 (코어 회원 상세 라우트 권한)
            'core.users.read',
        ]);

        // 조회만 가능한 운영자 — 삭제 버튼·엔드포인트가 막혀야 한다.
        // createAdminUser 는 공용 admin 역할에 권한을 붙이므로 여기서는 전용 역할을 만든다.
        $this->viewer = $this->createScrapViewer();

        $this->memberA = $this->createUser();
        $this->memberB = $this->createUser();
    }

    // ==========================================
    // 목록 조회 · 권한
    // ==========================================

    /**
     * scraps.view 없는 일반 회원 → 403.
     *
     * @effects admin_scrap_index_requires_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_forbidden_without_view_permission(): void
    {
        $this->actingAs($this->memberA)
            ->getJson($this->indexUrl())
            ->assertStatus(403);
    }

    /**
     * 전 회원의 스크랩이 최근 스크랩순으로 한 목록에 나온다.
     *
     * @effects admin_scrap_index_lists_all_members
     *
     * @return void 반환값 없음
     */
    public function test_index_lists_all_users_scraps_recent_first(): void
    {
        $first = $this->scrap($this->memberA, $this->createTestPost(['title' => '먼저']));
        $second = $this->scrap($this->memberB, $this->createTestPost(['title' => '나중']));

        $response = $this->actingAs($this->admin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.data');
        $response->assertJsonPath('data.data.0.id', $second);
        $response->assertJsonPath('data.data.1.id', $first);
        $response->assertJsonPath('data.abilities.can_view', true);
        $response->assertJsonPath('data.abilities.can_manage', true);
    }

    /**
     * user_id 필터 → 해당 회원의 스크랩만.
     *
     * @effects admin_scrap_index_filters_by_user
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_user_id(): void
    {
        $mine = $this->scrap($this->memberA, $this->createTestPost(['title' => 'A 의 글']));
        $this->scrap($this->memberB, $this->createTestPost(['title' => 'B 의 글']));

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl(['user_id' => $this->memberA->id]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $mine);
    }

    /**
     * user_uuid 필터가 동작하고, 존재하지 않는 uuid 는 빈 결과다 (전체 노출 아님).
     *
     * @effects admin_scrap_index_filters_by_user_uuid, admin_scrap_index_unknown_uuid_returns_empty
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_user_uuid_and_unknown_uuid_returns_empty(): void
    {
        $mine = $this->scrap($this->memberA, $this->createTestPost(['title' => 'A 의 글']));
        $this->scrap($this->memberB, $this->createTestPost(['title' => 'B 의 글']));

        $this->actingAs($this->admin)
            ->getJson($this->indexUrl(['user_uuid' => $this->memberA->uuid]))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $mine);

        // 없는 회원 → 필터를 무시하고 전체를 보여주면 안 된다
        $this->actingAs($this->admin)
            ->getJson($this->indexUrl(['user_uuid' => '00000000-0000-4000-8000-000000000000']))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data.data');
    }

    /**
     * board_id 필터 → 해당 게시판의 스크랩만.
     *
     * @effects admin_scrap_index_filters_by_board
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_board_id(): void
    {
        $otherBoardId = $this->createOtherBoard('admin-scrap-other', '다른 게시판');

        $mine = $this->scrap($this->memberA, $this->createTestPost(['title' => '이 게시판']));
        $this->scrap(
            $this->memberA,
            $this->createTestPost(['title' => '저 게시판', 'board_id' => $otherBoardId]),
            $otherBoardId
        );

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl(['board_id' => $this->board->id]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $mine);
    }

    /**
     * 스크랩일 범위 필터 (종료일은 그날 23:59:59 까지 포함).
     *
     * @effects admin_scrap_index_filters_by_date_range
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_scrapped_at_range(): void
    {
        $old = $this->scrap($this->memberA, $this->createTestPost(['title' => '옛날']));
        $recent = $this->scrap($this->memberA, $this->createTestPost(['title' => '최근']));

        DB::table('board_post_scraps')->where('id', $old)->update(['created_at' => now()->subDays(10)]);

        // 날짜 칸은 보는 사람(운영자)의 시간대 하루로 해석된다 — 운영자 시간대의 오늘로 보낸다.
        $viewerNow = now($this->admin->timezone ?: config('app.default_user_timezone'));
        $response = $this->actingAs($this->admin)->getJson($this->indexUrl([
            'scrapped_at_from' => $viewerNow->copy()->subDays(1)->format('Y-m-d'),
            'scrapped_at_to' => $viewerNow->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $recent);
    }

    // ==========================================
    // 검색 대상 구분
    // ==========================================

    /**
     * post_title → 제목만 매칭 (본문 매칭은 제외).
     *
     * @effects admin_scrap_search_post_title
     *
     * @return void 반환값 없음
     */
    public function test_index_search_post_title_only(): void
    {
        $titleHit = $this->scrap($this->memberA, $this->createTestPost(['title' => '알파 제목', 'content' => '무관']));
        $this->scrap($this->memberA, $this->createTestPost(['title' => '무관', 'content' => '알파 본문']));

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('post_title', '알파')));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $titleHit);
    }

    /**
     * post_content → 본문만 매칭 (제목 매칭은 제외).
     *
     * @effects admin_scrap_search_post_content
     *
     * @return void 반환값 없음
     */
    public function test_index_search_post_content_only(): void
    {
        $this->scrap($this->memberA, $this->createTestPost(['title' => '알파 제목', 'content' => '무관']));
        $contentHit = $this->scrap($this->memberA, $this->createTestPost(['title' => '무관', 'content' => '알파 본문']));

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('post_content', '알파')));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $contentHit);
    }

    /**
     * board_name → 게시판명(로케일 JSON)으로 매칭.
     *
     * @effects admin_scrap_search_board_name
     *
     * @return void 반환값 없음
     */
    public function test_index_search_board_name(): void
    {
        $otherBoardId = $this->createOtherBoard('admin-scrap-qna', '질문답변');

        $this->scrap($this->memberA, $this->createTestPost(['title' => '기본 게시판 글']));
        $qnaScrap = $this->scrap(
            $this->memberA,
            $this->createTestPost(['title' => 'QnA 글', 'board_id' => $otherBoardId]),
            $otherBoardId
        );

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('board_name', '질문답변')));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $qnaScrap);
    }

    /**
     * member → 회원 이름 또는 이메일로 매칭.
     *
     * @effects admin_scrap_search_member
     *
     * @return void 반환값 없음
     */
    public function test_index_search_member_by_name_and_email(): void
    {
        $targetScrap = $this->scrap($this->memberA, $this->createTestPost(['title' => 'A 글']));
        $this->scrap($this->memberB, $this->createTestPost(['title' => 'B 글']));

        // 이름 매칭
        $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('member', $this->memberA->name)))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $targetScrap);

        // 이메일 매칭
        $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('member', $this->memberA->email)))
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $targetScrap);
    }

    /**
     * author_name → 비회원 작성자명으로 매칭.
     *
     * @effects admin_scrap_search_author_name
     *
     * @return void 반환값 없음
     */
    public function test_index_search_author_name(): void
    {
        $hit = $this->scrap(
            $this->memberA,
            $this->createTestPost(['title' => '익명 글', 'author_name' => '홍길동'])
        );
        $this->scrap(
            $this->memberA,
            $this->createTestPost(['title' => '다른 글', 'author_name' => '김철수'])
        );

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('author_name', '홍길동')));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $hit);
    }

    /**
     * all → 제목·내용·게시판명·회원·작성자명 어디든 매칭되면 잡힌다.
     *
     * @effects admin_scrap_search_all
     *
     * @return void 반환값 없음
     */
    public function test_index_search_all_matches_any_field(): void
    {
        $byAuthor = $this->scrap(
            $this->memberA,
            $this->createTestPost(['title' => '무관 제목', 'content' => '무관 본문', 'author_name' => '유니크작성자'])
        );
        $this->scrap($this->memberB, $this->createTestPost(['title' => '다른 글', 'content' => '다른 본문']));

        $response = $this->actingAs($this->admin)
            ->getJson($this->indexUrl($this->searchQuery('all', '유니크작성자')));

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.data');
        $response->assertJsonPath('data.data.0.id', $byAuthor);
    }

    // ==========================================
    // 행 상태 노출
    // ==========================================

    /**
     * 삭제된 원글도 제목을 내고 상태는 deleted 로 구분한다 (관리자 화면 정책).
     *
     * @effects admin_scrap_row_exposes_deleted_post_title_and_status
     *
     * @return void 반환값 없음
     */
    public function test_index_marks_deleted_post_with_status_and_keeps_title(): void
    {
        $postId = $this->createTestPost(['title' => '삭제될 글']);
        $scrapId = $this->scrap($this->memberA, $postId);

        DB::table('board_posts')->where('id', $postId)->update([
            'deleted_at' => now(),
            'status' => 'deleted',
        ]);

        $response = $this->actingAs($this->admin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.id', $scrapId);
        $response->assertJsonPath('data.data.0.is_deleted', true);
        $response->assertJsonPath('data.data.0.post_status', 'deleted');
        // 사용자 카드와 달리 관리자 목록은 제목을 유지한다
        $response->assertJsonPath('data.data.0.post_title', '삭제될 글');
    }

    /**
     * 게시판의 use_scrap·is_active 상태를 행에 실어 화면이 「스크랩 꺼짐」 배지를 낼 수 있게 한다.
     *
     * @effects admin_scrap_row_exposes_board_flags
     *
     * @return void 반환값 없음
     */
    public function test_index_exposes_board_use_scrap_false_and_is_active(): void
    {
        $scrapId = $this->scrap($this->memberA, $this->createTestPost(['title' => '중지될 게시판 글']));

        DB::table('boards')->where('id', $this->board->id)->update(['use_scrap' => false]);

        $response = $this->actingAs($this->admin)->getJson($this->indexUrl());

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.id', $scrapId);
        $response->assertJsonPath('data.data.0.board.use_scrap', false);
        $response->assertJsonPath('data.data.0.board.is_active', true);
    }

    /**
     * sort_order=asc → 오래된 스크랩이 먼저.
     *
     * @effects admin_scrap_index_sort_order_asc
     *
     * @return void 반환값 없음
     */
    public function test_index_sort_order_asc(): void
    {
        $first = $this->scrap($this->memberA, $this->createTestPost(['title' => '먼저']));
        $second = $this->scrap($this->memberB, $this->createTestPost(['title' => '나중']));

        DB::table('board_post_scraps')->where('id', $first)->update(['created_at' => now()->subDay()]);

        $response = $this->actingAs($this->admin)->getJson($this->indexUrl(['sort_order' => 'asc']));

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.id', $first);
        $response->assertJsonPath('data.data.1.id', $second);
    }

    /**
     * 목록 응답에 게시판 필터 선택지가 실린다 — 스크랩 조회 권한만 가진 매니저도 게시판을 고를 수 있다.
     *
     * 게시판 목록 API(admin/boards)는 게시판 관리 권한이 있어야 열린다. 화면이 그 API 로 게시판
     * 셀렉트를 채우면 스크랩 조회만 가진 매니저에게는 403 이 나고 셀렉트가 빈다(K2 실측 2026-10-06).
     * 지워지지 않은 게시판은 비활성이어도 선택지에 남는다(그 게시판의 스크랩을 정리해야 한다).
     *
     * @scenario surface=admin_list, permission=view
     *
     * @effects admin_scrap_index_carries_board_options_meta
     *
     * @return void 반환값 없음
     */
    public function test_index_response_carries_board_options_meta(): void
    {
        $inactiveId = $this->createOtherBoard('scrap-meta-inactive', '꺼진 게시판');
        DB::table('boards')->where('id', $inactiveId)->update(['is_active' => false]);

        $boards = $this->actingAs($this->viewer)->getJson($this->indexUrl())->assertOk()->json('data.boards');

        $this->assertIsArray($boards, '목록 응답에 게시판 선택지가 실려야 합니다.');
        $mine = collect($boards)->firstWhere('id', $this->board->id);
        $this->assertNotNull($mine);
        $this->assertSame($this->board->slug, $mine['slug']);
        $this->assertSame($this->board->getLocalizedName(), $mine['name']);
        $this->assertNotNull(collect($boards)->firstWhere('id', $inactiveId), '비활성 게시판도 선택지에 남아야 합니다.');
    }

    /**
     * 목록 조회 중 서버 오류는 입력 오류(400)가 아니라 서버 오류(500)로 답한다.
     *
     * @scenario surface=admin_list, permission=manage
     *
     * @effects admin_scrap_index_server_error_returns_500
     *
     * @return void 반환값 없음
     */
    public function test_index_server_error_returns_500(): void
    {
        $this->mock(PostScrapService::class, function ($mock): void {
            $mock->shouldReceive('paginateForAdmin')->andThrow(new \RuntimeException('boom'));
        });

        $this->actingAs($this->admin)->getJson($this->indexUrl())->assertStatus(500);
    }

    /**
     * per_page 상한(100) 초과 → 422.
     *
     * @effects admin_scrap_index_rejects_per_page_over_limit
     *
     * @return void 반환값 없음
     */
    public function test_index_rejects_per_page_over_100(): void
    {
        $this->actingAs($this->admin)
            ->getJson($this->indexUrl(['per_page' => 500]))
            ->assertStatus(422);
    }

    // ==========================================
    // 삭제 (단건)
    // ==========================================

    /**
     * view 만 있는 운영자의 삭제 시도 → 403.
     *
     * @effects admin_scrap_destroy_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_destroy_forbidden_with_view_only(): void
    {
        $scrapId = $this->scrap($this->memberA, $this->createTestPost());

        $this->actingAs($this->viewer)
            ->deleteJson($this->indexUrl().'/'.$scrapId)
            ->assertStatus(403);

        $this->assertDatabaseHas('board_post_scraps', ['id' => $scrapId]);
    }

    /**
     * 관리자는 남의 스크랩을 삭제할 수 있고 scrap_count 도 함께 준다.
     *
     * @effects admin_scrap_destroy_removes_and_decrements_count
     *
     * @return void 반환값 없음
     */
    public function test_destroy_removes_other_users_scrap_and_decrements_count(): void
    {
        $postId = $this->createTestPost();
        $scrapId = $this->scrap($this->memberA, $postId);

        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));

        $this->actingAs($this->admin)
            ->deleteJson($this->indexUrl().'/'.$scrapId)
            ->assertStatus(200);

        $this->assertDatabaseMissing('board_post_scraps', ['id' => $scrapId]);
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 없는 스크랩 삭제 → 404.
     *
     * @effects admin_scrap_destroy_missing_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_destroy_returns_404_for_missing(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson($this->indexUrl().'/999999')
            ->assertStatus(404);
    }

    /**
     * 관리자 삭제도 단건 해제와 같은 훅(after_toggle)을 타며, 그 첫 인자는 스크랩 소유자다.
     *
     * 활동 로그의 user_id 는 로그인한 행위자(관리자)로 남는다 — 회원이 하지 않은 행위를
     * 그 회원의 활동 이력에 적으면 안 되기 때문이다. 반면 훅 인자는 "누구의 스크랩이
     * 해제됐는가" 를 가리켜야 구독자가 그 회원의 집계를 갱신할 수 있다. 두 값이 서로
     * 다른 질문에 답하므로 둘 다 검증한다 (설계: A-1.8, 실측 매트릭스 §8.2 T4b).
     *
     * @effects admin_scrap_destroy_fires_after_toggle_with_owner_id, admin_scrap_destroy_logs_actor
     *
     * @return void 반환값 없음
     */
    public function test_destroy_writes_unscrap_activity_log_with_owner_id(): void
    {
        $postId = $this->createTestPost();
        $scrapId = $this->scrap($this->memberA, $postId);

        $hookArgs = [];
        HookManager::addAction(
            'sirsoft-board.scrap.after_toggle',
            function (int $userId, int $hookPostId, bool $added) use (&$hookArgs) {
                $hookArgs[] = ['user_id' => $userId, 'post_id' => $hookPostId, 'added' => $added];
            },
            99
        );

        $this->actingAs($this->admin)
            ->deleteJson($this->indexUrl().'/'.$scrapId)
            ->assertStatus(200);

        // 훅 인자의 주어는 스크랩 소유자
        $this->assertCount(1, $hookArgs);
        $this->assertSame($this->memberA->id, $hookArgs[0]['user_id']);
        $this->assertSame($postId, $hookArgs[0]['post_id']);
        $this->assertFalse($hookArgs[0]['added']);

        // 활동 로그는 남되 행위자는 관리자
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'scrap.unscrap',
            'user_id' => $this->admin->id,
        ]);
    }

    // ==========================================
    // 삭제 (일괄)
    // ==========================================

    /**
     * 일괄 삭제 → deleted_count 반환, 행 제거.
     *
     * @effects admin_scrap_bulk_destroy_returns_count
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_returns_deleted_count(): void
    {
        $a = $this->scrap($this->memberA, $this->createTestPost(['title' => '1']));
        $b = $this->scrap($this->memberB, $this->createTestPost(['title' => '2']));

        $response = $this->actingAs($this->admin)
            ->deleteJson($this->indexUrl().'/bulk', ['ids' => [$a, $b]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);
        $this->assertDatabaseMissing('board_post_scraps', ['id' => $a]);
        $this->assertDatabaseMissing('board_post_scraps', ['id' => $b]);
    }

    /**
     * 존재하지 않는 ID 가 섞이면 422 (관리자 경로는 목록이 낡았음을 드러낸다).
     *
     * @effects admin_scrap_bulk_destroy_rejects_unknown_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_rejects_unknown_ids_422(): void
    {
        $a = $this->scrap($this->memberA, $this->createTestPost());

        $this->actingAs($this->admin)
            ->deleteJson($this->indexUrl().'/bulk', ['ids' => [$a, 999999]])
            ->assertStatus(422);

        $this->assertDatabaseHas('board_post_scraps', ['id' => $a]);
    }

    /**
     * view 만 있는 운영자의 일괄 삭제 → 403.
     *
     * @effects admin_scrap_bulk_destroy_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_forbidden_with_view_only(): void
    {
        $a = $this->scrap($this->memberA, $this->createTestPost());

        $this->actingAs($this->viewer)
            ->deleteJson($this->indexUrl().'/bulk', ['ids' => [$a]])
            ->assertStatus(403);

        $this->assertDatabaseHas('board_post_scraps', ['id' => $a]);
    }

    // ==========================================
    // 회원 상세 요약 주입
    // ==========================================

    /**
     * 관리자 회원 상세 응답에 board_scraps 요약이 실린다.
     *
     * @effects admin_user_detail_includes_scrap_summary
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_includes_board_scraps_summary(): void
    {
        $this->scrap($this->memberA, $this->createTestPost(['title' => '1']));
        $this->scrap($this->memberA, $this->createTestPost(['title' => '2']));

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/'.$this->memberA->uuid);

        $response->assertStatus(200);
        $response->assertJsonPath('data.board_scraps.count', 2);
        $this->assertNotNull($response->json('data.board_scraps.latest_scrapped_at'));
    }

    /**
     * 스크랩 조회 권한이 없는 관리자에게는 회원 상세에 스크랩 요약을 싣지 않는다.
     *
     * 요약을 실으면 화면이 「스크랩」 탭과 건수를 그리고, 「이 회원 스크랩 목록 보기」는 403 화면으로
     * 끝난다(K2 실측 2026-10-06 — 반응 쪽 K1-D11 과 같은 결함).
     *
     * @scenario surface=member_detail_tab, permission=view, member_scope=true
     *
     * @effects admin_user_detail_omits_scrap_summary_without_view_permission
     *
     * @return void 반환값 없음
     */
    public function test_admin_user_detail_omits_board_scraps_without_view_permission(): void
    {
        $this->scrap($this->memberA, $this->createTestPost(['title' => '권한 확인']));

        $reader = $this->createAdminWithOnly(['core.users.read']);

        $response = $this->actingAs($reader)->getJson('/api/admin/users/'.$this->memberA->uuid);
        $response->assertOk();
        $this->assertArrayNotHasKey('board_scraps', $response->json('data') ?? []);

        // 조회 권한이 있으면 그대로 실린다.
        $this->assertSame(
            1,
            $this->actingAs($this->admin)->getJson('/api/admin/users/'.$this->memberA->uuid)->json('data.board_scraps.count')
        );
    }

    /**
     * 프로필(/api/me)에는 board_scraps 를 싣지 않는다 — 부팅 경로에 집계 비용을 얹지 않는다.
     *
     * @effects profile_endpoint_omits_scrap_summary
     *
     * @return void 반환값 없음
     */
    public function test_profile_endpoint_omits_board_scraps(): void
    {
        $this->scrap($this->memberA, $this->createTestPost());

        $response = $this->actingAs($this->memberA)->getJson('/api/me');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('board_scraps', $response->json('data') ?? []);
    }

    /**
     * 모듈이 스크랩 권한 2종과 관리 메뉴를 선언한다 (설치본 동기화의 근거).
     *
     * @effects module_declares_scrap_permissions_and_menu
     *
     * @return void 반환값 없음
     */
    public function test_module_declares_scraps_permissions_and_menu(): void
    {
        $module = new Module;

        $categories = collect($module->getPermissions()['categories'] ?? []);
        $scrapCategory = $categories->firstWhere('identifier', 'scraps');

        $this->assertNotNull($scrapCategory, 'scraps 권한 카테고리가 선언되지 않았다');
        $this->assertSame(
            ['view', 'manage'],
            array_column($scrapCategory['permissions'], 'action')
        );

        $children = collect($module->getAdminMenus()[0]['children'] ?? []);
        $scrapMenu = $children->firstWhere('slug', 'sirsoft-board-scraps');

        $this->assertNotNull($scrapMenu, '스크랩 관리 메뉴가 선언되지 않았다');
        $this->assertSame('/admin/boards/scraps', $scrapMenu['url']);
        $this->assertSame('sirsoft-board.scraps.view', $scrapMenu['permission']);
    }

    // ==========================================
    // 헬퍼
    // ==========================================

    /**
     * 관리자 스크랩 목록 URL 을 만듭니다.
     *
     * @param  array<string, mixed>  $query  쿼리 파라미터
     * @return string 엔드포인트 URL
     */
    private function indexUrl(array $query = []): string
    {
        $base = '/api/modules/sirsoft-board/admin/scraps';

        return $query === [] ? $base : $base.'?'.http_build_query($query);
    }

    /**
     * filters[0] 규약의 검색 쿼리를 만듭니다.
     *
     * @param  string  $field  검색 대상
     * @param  string  $value  검색어
     * @return array<string, mixed> 쿼리 배열
     */
    private function searchQuery(string $field, string $value): array
    {
        return ['filters' => [['field' => $field, 'value' => $value, 'operator' => 'like']]];
    }

    /**
     * 회원의 스크랩을 시드하고 스크랩 레코드 ID 를 반환합니다.
     *
     * 서비스를 거쳐야 scrap_count 증가·활동 로그까지 실제 경로와 같아진다.
     *
     * @param  User  $user  스크랩하는 회원
     * @param  int  $postId  게시글 ID
     * @param  int|null  $boardId  게시판 ID (기본: 테스트 게시판)
     * @return int 스크랩 레코드 ID
     */
    private function scrap(User $user, int $postId, ?int $boardId = null): int
    {
        app(PostScrapService::class)
            ->toggle($user->id, $postId, $boardId ?? $this->board->id);

        return (int) DB::table('board_post_scraps')
            ->where('user_id', $user->id)
            ->where('post_id', $postId)
            ->value('id');
    }

    /**
     * 다른 게시판을 만듭니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  string  $koName  한국어 게시판명
     * @return int 게시판 ID
     */
    private function createOtherBoard(string $slug, string $koName): int
    {
        return (int) DB::table('boards')->insertGetId([
            'slug' => $slug,
            'name' => json_encode(['ko' => $koName, 'en' => $slug], JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'use_scrap' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            ['identifier' => 'sirsoft-board.scrap-only-perms-test'],
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
     * scraps.view 만 가진 운영자를 만듭니다.
     *
     * createAdminUser 는 공용 admin 역할에 권한을 붙이므로 "view 만" 을 만들 수 없다.
     * 전용 역할을 쓰면 admin 미들웨어(관리자 타입 권한 보유 여부)는 통과하면서
     * scraps.manage 만 빠진 상태를 정확히 재현한다.
     *
     * @return User 조회 전용 운영자
     */
    private function createScrapViewer(): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-board.scrap-viewer-test'],
            [
                'name' => ['ko' => '스크랩 조회 전용', 'en' => 'Scrap Viewer'],
                'description' => ['ko' => '테스트용', 'en' => 'For tests'],
            ]
        );

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.scraps.view'],
            [
                'name' => ['ko' => '스크랩 조회', 'en' => 'View Scraps'],
                'type' => 'admin',
            ]
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
