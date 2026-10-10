<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 관리자 반응 목록 — deferred join 페이지네이션 회귀 (#525 WP-C).
 *
 * `AdminReactionTest` 가 권한·필터·검색·삭제를 잠근다. 이 파일은 그쪽이 다루지 않는
 * **페이지네이션 기계**를 잠근다 — [ReactionRepository::paginateForAdmin()](modules/_bundled/sirsoft-board/src/Repositories/ReactionRepository.php)
 * 이 `paginateWithDeferredJoin()` 을 쓰기 때문에 평범한 목록과 실패 양상이 다르다:
 *
 *  - **행 중복·누락**: deferred join 은 «ID 만 먼저 페이징 → 본조회» 2단이라 정렬 키가
 *    불안정하면 같은 행이 두 페이지에 걸치거나 사라진다. 페이지를 걷어 모은 ID 집합이
 *    전체와 정확히 같아야 한다.
 *  - **관계 적재**: `relations` 로 지정한 user·board·reactionType 이 실제로 실려야 한다.
 *    빠지면 화면이 N+1 로 떨어지거나 빈 칸이 뜬다.
 *  - **hydrateTargets**: 폴리모픽 대상(post/comment)은 `with()` 가 아니라 저장소가 손으로
 *    채운다 — 이 단계가 빠지면 제목 칸이 통째로 빈다.
 */
class AdminReactionPaginationTest extends BoardTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/admin/reactions';

    private User $admin;

    private int $likeId;

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

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'use_comment_reaction' => true,
            'use_comment' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 반응 행 N 건을 서로 다른 회원·게시글로 만듭니다.
     *
     * @return array<int, int> 만들어진 반응 ID 목록
     * @param $count count 값
     */
    private function seedReactions(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $user = $this->createUser();
            $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

            $ids[] = DB::table('board_reactions')->insertGetId([
                'user_id' => $user->id,
                'target_type' => 'post',
                'target_id' => $postId,
                'reaction_type_id' => $this->likeId,
                'board_id' => $this->board->id,
                // created_at 을 흩어 정렬이 안정적인지 본다.
                'created_at' => now()->subMinutes($count - $i),
                'updated_at' => now()->subMinutes($count - $i),
            ]);
        }

        return $ids;
    }

    /**
     * 페이지를 걷어 모은 ID 집합이 전체와 정확히 같다 — 중복도 누락도 없다.
     *
     * deferred join 의 2단 조회에서 정렬 키가 불안정하면 여기서 red 다.
     *
     * @scenario case=admin_reaction_pagination_covers_all_rows
     *
     * @effects admin_reaction_pages_have_no_duplicate_or_missing_rows
     *
     * @return void 반환값 없음
     */
    public function test_paging_covers_every_row_exactly_once(): void
    {
        $expected = $this->seedReactions(7);

        $collected = [];
        // 요청한 크기대로 잘려야 한다 — 3·3·1. 페이지 크기가 밀리면 여기서 red 다.
        $expectedSizes = [1 => 3, 2 => 3, 3 => 1];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->actingAs($this->admin)
                ->getJson(self::ENDPOINT."?per_page=3&page={$page}")
                ->assertOk();

            $rows = $response->json('data.data') ?? [];

            $this->assertCount(
                $expectedSizes[$page],
                $rows,
                "{$page}페이지가 요청한 크기(3)대로 잘리지 않았다"
            );

            foreach ($rows as $row) {
                $collected[] = (int) $row['id'];
            }
        }

        sort($expected);
        $unique = array_unique($collected);
        sort($unique);

        $this->assertSame(count($collected), count($unique), '같은 반응이 두 페이지에 걸쳐 나왔다');
        $this->assertSame($expected, $unique, '페이지를 다 걷었는데 전체 집합과 다르다');
    }

    /**
     * 목록 총계가 실제 행 수와 같다.
     *
     * @scenario case=admin_reaction_pagination_covers_all_rows
     *
     * @effects admin_reaction_total_matches_row_count
     *
     * @return void 반환값 없음
     */
    public function test_total_matches_actual_row_count(): void
    {
        $this->seedReactions(5);

        $response = $this->actingAs($this->admin)
            ->getJson(self::ENDPOINT.'?per_page=2')
            ->assertOk();

        $total = $response->json('data.pagination.total');

        $this->assertSame(5, (int) $total);
    }

    /**
     * 행마다 회원·게시판·유형·대상이 실려 온다 — 관계 적재와 hydrateTargets 가 살아 있다.
     *
     * 관계가 빠지면 화면의 이름·게시판·유형 칸이 비고, hydrateTargets 가 빠지면 제목이 빈다.
     *
     * @scenario case=admin_reaction_pagination_covers_all_rows
     *
     * @effects admin_reaction_rows_carry_user_board_type_and_target
     *
     * @return void 반환값 없음
     */
    public function test_rows_carry_eager_loaded_relations_and_target(): void
    {
        $this->seedReactions(1);

        $row = $this->actingAs($this->admin)
            ->getJson(self::ENDPOINT.'?per_page=10')
            ->assertOk()
            ->json('data.data.0');

        $this->assertNotNull($row, '반응 행이 하나도 오지 않았다');

        // 관계 적재 — 회원·게시판·유형.
        $this->assertNotNull($row['member'] ?? null, '회원 관계가 실리지 않았다');
        $this->assertNotEmpty($row['board']['slug'] ?? null, '게시판 관계가 실리지 않았다');
        $this->assertNotEmpty($row['reaction_type']['code'] ?? null, '반응 유형 관계가 실리지 않았다');

        // hydrateTargets — 폴리모픽 대상의 제목.
        $this->assertNotEmpty($row['target_title'] ?? null, '대상(게시글)이 채워지지 않았다 — hydrateTargets 누락');
    }
}
