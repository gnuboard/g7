<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 반응 응답 게이트 테스트 (2026-09-16 결정).
 *
 * 「반응 기능이나 특정 반응을 더 이상 쓰지 않기로 했는데 API 에는 반응 정보가 포함돼
 * 나가면 곤란하다」 — 끈 기능의 정보가 응답 본문에 남지 않는지를 표면마다 잠근다.
 *
 * 화면은 프론트에서 use_reaction 을 한 번 더 보므로 브라우저로는 드러나지 않는다.
 * 누출은 응답 본문에서만 보이므로 이 테스트가 유일한 방어선이다.
 *
 * @scenario case=use_reaction_off_payload_omits_reaction
 *
 * @effects list_omits_reaction_total_when_use_reaction_off
 */
class ReactionPayloadGateTest extends BoardTestCase
{
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

        // FK(restrictOnDelete) 순서: 이력 먼저 정리 후 유형 삭제
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 반응 카운트가 캐시 컬럼에 남아 있는 게시글을 만듭니다.
     *
     * 반응을 끄거나 유형을 비활성으로 돌려도 캐시 컬럼의 옛 카운트는 그대로 남는다 —
     * 누출은 바로 그 잔여 값이 응답에 실려 나가는 것이므로, 잔여 값을 심어 재현한다.
     *
     * @param  $counts  counts 값
     * @return int 정수 식별자
     */
    private function createPostWithCounts(array $counts): int
    {
        $postId = $this->createTestPost();
        Post::query()->whereKey($postId)->update(['reaction_counts' => json_encode($counts)]);

        return $postId;
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @return string 문자열
     */
    private function listUrl(): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts";
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @param  $postId  postId 값
     * @return string 문자열
     */
    private function detailUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}";
    }

    /**
     * 반응 ON — 목록에 reaction_total 이 실리고 활성 유형 합계와 같다.
     *
     * @scenario case=register_first_like
     *
     * @effects active_types_always_shown_even_zero_count
     *
     * @return void 반환값 없음
     */
    public function test_list_includes_reaction_total_when_enabled(): void
    {
        $this->createPostWithCounts([(string) $this->likeId => 3, (string) $this->dislikeId => 2]);

        $response = $this->getJson($this->listUrl())->assertOk();

        $this->assertSame(5, $response->json('data.data.0.reaction_total'));
    }

    /**
     * 관리자 게시글 목록 — 「반응」 열이 읽는 reaction_total 이 사용자 목록과 같은 합계로 실린다.
     *
     * 목록 저장소는 board 관계를 싣지 않아 컨트롤러가 붙여 줘야 한다. 관리자 목록은 붙이지 않아
     * 키가 빠졌고, 화면은 `row.reaction_total || 0` 이라 반응이 있는 글도 늘 0 으로 보였다
     * (브라우저 실측 2026-10-03: 비추천 1건이 있는 글이 관리자 목록에서 0).
     *
     * @scenario case=register_first_like
     *
     * @effects admin_list_includes_reaction_total_when_enabled
     *
     * @return void 반환값 없음
     */
    public function test_admin_list_includes_reaction_total_when_enabled(): void
    {
        $this->createPostWithCounts([(string) $this->likeId => 3, (string) $this->dislikeId => 2]);

        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $identifier = "sirsoft-board.{$this->board->slug}.admin.posts.read";
        $permission = Permission::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin'],
        );
        $adminRole->permissions()->syncWithoutDetaching([$permission->id]);

        $admin = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $admin->roles()->attach($adminRole->id);

        $row = $this->actingAs($admin)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts")
            ->assertOk()
            ->json('data.data.0');

        $this->assertSame(5, $row['reaction_total'] ?? null);
    }

    /**
     * 목록 — 활성 반응 유형 조회 수가 행 수에 비례하지 않는다.
     *
     * 행마다 반응 합계를 내려면 게시판이 켠 활성 유형이 필요한데, 행마다 새로 조회하면 목록 한 쪽에
     * 유형 조회와 아이콘 첨부 적재가 행 수만큼 붙는다. 같은 요청 안에서는 한 번만 조회해 다시 쓴다.
     *
     * @scenario case=register_first_like
     *
     * @effects list_reaction_type_lookup_does_not_scale_with_rows
     *
     * @return void 반환값 없음
     */
    public function test_list_reaction_type_lookup_does_not_scale_with_rows(): void
    {
        $countLookups = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->listUrl())->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            return count(array_filter(
                $queries,
                fn (array $query): bool => str_contains($query['query'], 'board_reaction_types')
            ));
        };

        $this->createPostWithCounts([(string) $this->likeId => 1]);
        $withOneRow = $countLookups();

        for ($i = 0; $i < 4; $i++) {
            $this->createPostWithCounts([(string) $this->likeId => 1]);
        }
        $withFiveRows = $countLookups();

        $this->assertGreaterThan(0, $withOneRow);
        $this->assertSame($withOneRow, $withFiveRows);
    }

    /**
     * 반응 OFF — 목록 응답에 reaction_total 키 자체가 없다 (결정: 0 이 아니라 키 제거).
     *
     * @scenario case=use_reaction_off_payload_omits_reaction
     *
     * @effects list_omits_reaction_total_when_use_reaction_off
     *
     * @return void 반환값 없음
     */
    public function test_list_omits_reaction_total_when_use_reaction_off(): void
    {
        $this->createPostWithCounts([(string) $this->likeId => 3]);
        $this->updateBoardSettings(['use_reaction' => false]);

        $row = $this->getJson($this->listUrl())->assertOk()->json('data.data.0');

        $this->assertArrayNotHasKey('reaction_total', $row);
    }

    /**
     * 비활성으로 돌린 유형의 잔여 카운트는 목록 합계에서 빠진다.
     *
     * 캐시 컬럼을 통째로 더하면 상세(활성 유형만 집계)와 어긋나 같은 글인데 목록 3,
     * 상세 0 이 된다 — 그 불일치를 잠근다.
     *
     * @scenario case=deactivated_type_excluded_from_payload
     *
     * @effects list_total_excludes_deactivated_type_counts
     *
     * @return void 반환값 없음
     */
    public function test_list_total_excludes_deactivated_type_counts(): void
    {
        $this->createPostWithCounts([(string) $this->likeId => 3, (string) $this->dislikeId => 2]);
        $this->updateBoardSettings(['active_reaction_types' => ['dislike']]);

        $response = $this->getJson($this->listUrl())->assertOk();

        $this->assertSame(2, $response->json('data.data.0.reaction_total'));
    }

    /**
     * 반응 OFF — 상세 응답에서 카운트와 유형 카탈로그가 모두 비어 나간다.
     *
     * @scenario case=use_reaction_off_payload_omits_reaction
     *
     * @effects detail_omits_reaction_counts_and_options_when_off
     *
     * @return void 반환값 없음
     */
    public function test_detail_omits_reaction_data_when_use_reaction_off(): void
    {
        $postId = $this->createPostWithCounts([(string) $this->likeId => 3]);
        $this->updateBoardSettings(['use_reaction' => false]);

        $data = $this->getJson($this->detailUrl($postId))->assertOk()->json('data');

        $this->assertSame([], (array) $data['reaction_counts']);
        $this->assertSame([], $data['board']['reaction_type_options']);
        $this->assertNull($data['my_reaction_type_id']);
    }

    /**
     * 비활성으로 돌린 유형은 상세의 카운트·카탈로그 어디에도 나오지 않는다.
     *
     * @scenario case=deactivated_type_excluded_from_payload
     *
     * @effects detail_omits_deactivated_type
     *
     * @return void 반환값 없음
     */
    public function test_detail_omits_deactivated_type(): void
    {
        $postId = $this->createPostWithCounts([(string) $this->likeId => 3, (string) $this->dislikeId => 2]);
        $this->updateBoardSettings(['active_reaction_types' => ['dislike']]);

        $data = $this->getJson($this->detailUrl($postId))->assertOk()->json('data');

        $this->assertArrayNotHasKey((string) $this->likeId, (array) $data['reaction_counts']);
        $this->assertSame(
            [$this->dislikeId],
            array_column($data['board']['reaction_type_options'], 'id')
        );
    }

    /**
     * 활성 유형이 하나도 없으면 반응을 켜 두었어도 응답이 비어 나간다.
     *
     * @scenario case=detail_hidden_when_no_active_types
     *
     * @effects reaction_area_hidden_when_no_active_types
     *
     * @return void 반환값 없음
     */
    public function test_no_active_types_empties_payload(): void
    {
        $postId = $this->createPostWithCounts([(string) $this->likeId => 3]);
        $this->updateBoardSettings(['active_reaction_types' => []]);

        $data = $this->getJson($this->detailUrl($postId))->assertOk()->json('data');

        $this->assertSame([], (array) $data['reaction_counts']);
        $this->assertSame([], $data['board']['reaction_type_options']);

        $row = $this->getJson($this->listUrl())->assertOk()->json('data.data.0');
        $this->assertArrayNotHasKey('reaction_total', $row);
    }

    /**
     * 최신글 위젯 — 반응 ON 이면 활성 유형 합계가 실린다.
     *
     * @scenario case=register_first_like
     *
     * @effects recent_posts_widget_includes_reaction_total
     *
     * @return void 반환값 없음
     */
    public function test_recent_posts_widget_includes_reaction_total(): void
    {
        $postId = $this->createPostWithCounts([(string) $this->likeId => 3, (string) $this->dislikeId => 2]);

        $rows = app(BoardRepositoryInterface::class)->getBoardRecentPostsById($this->board->id, 10);
        $row = collect($rows)->firstWhere('id', $postId);

        $this->assertSame(5, $row['reaction_total']);
    }

    /**
     * 홈 최근 게시글 위젯 — 캐시된 뒤에 반응을 남기거나 지워도 반응 수가 곧바로 맞는다.
     *
     * 위젯 목록은 `board-posts` 태그로 하루 동안 캐시된다. 반응 처리 경로가 이 태그를
     * 비우지 않으면, 회원이 반응을 남긴 뒤에도 홈에는 이전 반응 수(0)가 계속 보인다.
     *
     * @scenario case=register_first_like
     *
     * @effects recent_posts_widget_reaction_total_refreshes_after_react
     *
     * @return void 반환값 없음
     */
    public function test_recent_posts_widget_reaction_total_refreshes_after_react(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->setGuestPermissions(['posts.read']);
        $this->grantUserRolePermissions(['posts.read']);

        $recentTotal = function () use ($postId): ?int {
            $row = collect($this->getJson('/api/modules/sirsoft-board/boards/posts/recent?limit=20')->assertOk()->json('data'))
                ->firstWhere('id', $postId);

            return $row['reaction_total'] ?? null;
        };

        // 첫 조회가 위젯 목록을 캐시에 담는다.
        $this->assertSame(0, $recentTotal());

        $this->actingAs($reactor, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/react", ['reaction_type_id' => $this->likeId])
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame(1, $recentTotal(), '반응을 남긴 뒤 위젯의 반응 수가 갱신되어야 한다.');

        // 마이페이지에서 해제해도 위젯이 따라온다.
        $reactionId = (int) DB::table('board_reactions')->where('user_id', $reactor->id)->value('id');
        $this->actingAs($reactor, 'sanctum')
            ->deleteJson("/api/modules/sirsoft-board/me/reactions/{$reactionId}")
            ->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame(0, $recentTotal(), '반응을 해제한 뒤 위젯의 반응 수가 갱신되어야 한다.');
    }

    /**
     * 최신글 위젯 — 반응을 끄면 reaction_total 키 자체가 실리지 않는다.
     *
     * 위젯은 PostResource 를 타지 않고 BoardRepository 가 직접 배열을 만든다 —
     * 목록/상세를 고쳐도 이 경로는 따로 막아야 한다.
     *
     * @scenario case=use_reaction_off_payload_omits_reaction
     *
     * @effects recent_posts_widget_omits_reaction_total_when_off
     *
     * @return void 반환값 없음
     */
    public function test_recent_posts_widget_omits_reaction_total_when_off(): void
    {
        $postId = $this->createPostWithCounts([(string) $this->likeId => 3]);
        $this->updateBoardSettings(['use_reaction' => false]);

        $rows = app(BoardRepositoryInterface::class)->getBoardRecentPostsById($this->board->id, 10);
        $row = collect($rows)->firstWhere('id', $postId);

        $this->assertArrayNotHasKey('reaction_total', $row);
    }
}
