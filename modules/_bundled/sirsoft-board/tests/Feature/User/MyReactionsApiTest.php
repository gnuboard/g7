<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 마이페이지 「내 반응」 API Feature 테스트.
 *
 * 회원이 남긴 반응을 게시글·댓글 구분 없이 한 목록으로 보여주고, 단건·선택 해제를 제공한다.
 * 반응이 꺼진 게시판·삭제된 대상의 반응도 **해제는 허용**한다 (결정 R11) —
 * 남긴 기록을 회수하는 행위는 게시판 설정과 무관한 회원의 권리다.
 */
class MyReactionsApiTest extends BoardTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/me/reactions';

    private int $likeId;

    private int $dislikeId;

    private User $member;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->member = $this->createUser();

        $this->updateBoardSettings([
            'use_reaction' => true,
            'use_comment_reaction' => true,
            'use_comment' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 반응 행을 직접 만듭니다 (API 를 거치지 않는 픽스처).
     *
     * @param  $userId  회원 ID
     * @param  $targetType  대상 유형
     * @param  $targetId  대상 ID
     * @param  $typeId  유형 ID
     * @param  $boardId  게시판 ID
     * @return int 정수 식별자
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
     * 게시글·댓글 반응이 한 목록에 대상 정보와 함께 나온다.
     *
     * @scenario case=list_mixes_post_and_comment_rows
     *
     * @effects list_mixes_post_and_comment_targets, targets_hydrated_without_n_plus_one
     *
     * @return void 반환값 없음
     */
    public function test_index_lists_post_and_comment_reactions_with_target_hydration(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id, 'title' => '알파 제목']);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id, 'content' => '베타 본문 키워드']);

        $this->seedReaction($this->member->id, 'post', $postId, $this->likeId);
        $this->seedReaction($this->member->id, 'comment', $commentId, $this->dislikeId);

        $response = $this->actingAs($this->member, 'sanctum')->getJson(self::ENDPOINT);

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(2, $rows);

        $postRow = collect($rows)->firstWhere('target_type', 'post');
        $this->assertSame('알파 제목', $postRow['title']);
        $this->assertSame($postId, $postRow['post_id']);
        $this->assertNull($postRow['comment_id']);
        $this->assertFalse($postRow['target_deleted']);
        $this->assertTrue($postRow['reaction_enabled']);
        $this->assertSame($this->board->slug, $postRow['board_slug']);

        $commentRow = collect($rows)->firstWhere('target_type', 'comment');
        $this->assertSame($commentId, $commentRow['comment_id']);
        $this->assertSame($postId, $commentRow['post_id'], '댓글 반응도 이동은 부모 글로 간다.');
        $this->assertSame('알파 제목', $commentRow['title']);
        $this->assertStringContainsString('베타 본문', (string) $commentRow['excerpt']);
    }

    /**
     * 대상 구분·유형·게시판·정렬 필터가 동작한다.
     *
     * @scenario case=filters_target_type_reaction_type_board_and_sort
     *
     * @effects list_filters_target_type_reaction_type_board_and_sort
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_target_type_reaction_type_board_slug_and_sort(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->seedReaction($this->member->id, 'post', $postId, $this->likeId);
        $this->seedReaction($this->member->id, 'comment', $commentId, $this->dislikeId);

        // 대상 구분
        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?target_type=comment')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.target_type', 'comment');

        // 유형
        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?reaction_type_id='.$this->likeId)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.target_type', 'post');

        // 게시판 slug
        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?board_slug='.$this->board->slug)
            ->assertOk()
            ->assertJsonCount(2, 'data.data');

        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?board_slug=nonexistent-board')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');

        // 정렬 (oldest 면 게시글 반응이 먼저 들어갔으므로 앞에 온다)
        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?sort=oldest')
            ->assertOk()
            ->assertJsonPath('data.data.0.target_type', 'post');
    }

    /**
     * 검색은 LIKE 로 동작하고 삭제된 대상은 검색 결과에서 빠진다.
     *
     * @scenario case=search_title_excludes_deleted_targets
     *
     * @effects search_uses_like_and_excludes_deleted_targets
     *
     * @return void 반환값 없음
     */
    public function test_index_search_title_content_uses_like_and_excludes_deleted_targets(): void
    {
        $author = $this->createUser();
        $alive = $this->createTestPost(['user_id' => $author->id, 'title' => '검색대상 제목']);
        $deleted = $this->createTestPost(['user_id' => $author->id, 'title' => '검색대상 삭제글', 'deleted_at' => now()]);

        $this->seedReaction($this->member->id, 'post', $alive, $this->likeId);
        $this->seedReaction($this->member->id, 'post', $deleted, $this->likeId);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?search=검색대상&search_field=title');

        $response->assertOk()->assertJsonCount(1, 'data.data');
        $this->assertSame('검색대상 제목', $response->json('data.data.0.title'));
    }

    /**
     * 잘못된 대상 구분은 422.
     *
     * @scenario case=invalid_filter_values_rejected
     *
     * @effects invalid_filter_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_index_rejects_invalid_target_type(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?target_type=board')
            ->assertStatus(422);
    }

    /**
     * 잘못된 검색 대상은 422 가 아니라 기본(제목+내용)으로 처리하고, 적용한 값을 data.query 로 되돌린다
     * (게시판 목록·관리자 목록과 같은 처리, 7.1.0 실측 O-K3-3).
     *
     * @scenario case=unknown_search_field_falls_back
     *
     * @effects unknown_search_field_falls_back_to_title_content
     *
     * @return void 반환값 없음
     */
    public function test_index_unknown_search_field_falls_back_to_title_content(): void
    {
        $author = $this->createUser();
        $titleHit = $this->createTestPost(['user_id' => $author->id, 'title' => '대체검색 제목', 'content' => '무관']);
        $contentHit = $this->createTestPost(['user_id' => $author->id, 'title' => '무관', 'content' => '대체검색 본문']);

        $this->seedReaction($this->member->id, 'post', $titleHit, $this->likeId);
        $this->seedReaction($this->member->id, 'post', $contentHit, $this->likeId);

        foreach (['author', 'abc'] as $unknown) {
            $this->actingAs($this->member, 'sanctum')
                ->getJson(self::ENDPOINT.'?search=대체검색&search_field='.$unknown)
                ->assertOk()
                ->assertJsonCount(2, 'data.data')
                ->assertJsonPath('data.query.search_field', 'title_content');
        }
    }

    /**
     * 반응 꺼진 게시판·삭제된 게시판·삭제된 대상이 각각 표시된다.
     *
     * @scenario case=row_marks_target_board_deleted_and_reaction_off
     *
     * @effects row_marks_reaction_off_board_deleted_and_target_deleted
     *
     * @return void 반환값 없음
     */
    public function test_index_marks_reaction_disabled_board_deleted_and_target_deleted(): void
    {
        $author = $this->createUser();

        // 삭제된 대상
        $deletedPost = $this->createTestPost(['user_id' => $author->id, 'deleted_at' => now()]);
        $this->seedReaction($this->member->id, 'post', $deletedPost, $this->likeId);

        // 게시판 삭제 (board_id NULL)
        $orphanPost = $this->createTestPost(['user_id' => $author->id]);
        $this->seedReaction($this->member->id, 'post', $orphanPost, $this->likeId, boardId: null);
        DB::table('board_reactions')->where('target_id', $orphanPost)->update(['board_id' => null]);

        $rows = $this->actingAs($this->member, 'sanctum')->getJson(self::ENDPOINT)->json('data.data');

        $deletedRow = collect($rows)->firstWhere('target_id', $deletedPost);
        $this->assertTrue($deletedRow['target_deleted']);
        $this->assertFalse($deletedRow['reaction_enabled']);

        $orphanRow = collect($rows)->firstWhere('target_id', $orphanPost);
        $this->assertTrue($orphanRow['board_deleted']);
        $this->assertNull($orphanRow['board']);
        $this->assertFalse($orphanRow['reaction_enabled']);

        // 반응 OFF 게시판
        $this->updateBoardSettings(['use_reaction' => false]);
        $offRows = $this->actingAs($this->member, 'sanctum')->getJson(self::ENDPOINT)->json('data.data');
        $offRow = collect($offRows)->firstWhere('target_id', $deletedPost);
        $this->assertFalse($offRow['reaction_enabled']);
    }

    /**
     * 응답에 필터 셀렉트용 유형 메타와 적용 필터 에코가 실린다.
     *
     * @scenario case=response_carries_type_meta_and_query_echo
     *
     * @effects response_carries_reaction_types_meta_and_query_echo
     *
     * @return void 반환값 없음
     */
    public function test_index_response_carries_reaction_types_meta_and_query_echo(): void
    {
        // 비활성 유형도 필터 옵션에 있어야 과거 반응을 골라낼 수 있다.
        ReactionType::create([
            'code' => 'inactive_old',
            'name' => ['ko' => '옛유형'],
            'display_order' => 9,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT.'?target_type=post&sort=oldest');

        $response->assertOk();

        $types = $response->json('data.reaction_types');
        $this->assertCount(3, $types, '비활성 유형까지 필터 옵션에 포함되어야 합니다.');
        $this->assertContains('inactive_old', array_column($types, 'code'));

        $this->assertSame('post', $response->json('data.query.target_type'));
        $this->assertSame('oldest', $response->json('data.query.sort'));
    }

    /**
     * 단건 해제는 본인 것만 지우고 대상의 카운트를 다시 집계한다.
     *
     * @scenario case=remove_single_own_only_and_recalculates
     *
     * @effects remove_own_only_and_recalculates_counts
     *
     * @return void 반환값 없음
     */
    public function test_destroy_own_only_and_recalculates_counts(): void
    {
        $author = $this->createUser();
        $other = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        // API 로 남겨 카운트 캐시까지 만든다.
        $this->actingAs($this->member, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/react", ['reaction_type_id' => $this->likeId])
            ->assertOk();
        $this->actingAs($other, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/react", ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $this->assertSame(2, (int) (Post::find($postId)->reaction_counts[(string) $this->likeId] ?? 0));

        $mine = DB::table('board_reactions')->where('user_id', $this->member->id)->value('id');
        $theirs = DB::table('board_reactions')->where('user_id', $other->id)->value('id');

        // 남의 반응은 못 지운다 (404)
        $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$theirs)
            ->assertNotFound();

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$mine)
            ->assertOk();

        $this->assertDatabaseMissing('board_reactions', ['id' => $mine]);
        $this->assertSame(
            1,
            (int) (Post::find($postId)->reaction_counts[(string) $this->likeId] ?? 0),
            '해제 후 카운트 캐시가 실제 행 수와 같아야 합니다.',
        );
    }

    /**
     * 일괄 해제는 남의 ID 를 조용히 건너뛰고 실제 건수만 돌려준다.
     *
     * @scenario case=bulk_remove_ignores_foreign_ids
     *
     * @effects bulk_remove_ignores_foreign_ids_and_reports_count
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_ignores_foreign_ids_silently_and_reports_count(): void
    {
        $author = $this->createUser();
        $other = $this->createUser();
        $postA = $this->createTestPost(['user_id' => $author->id]);
        $postB = $this->createTestPost(['user_id' => $author->id]);

        $mineA = $this->seedReaction($this->member->id, 'post', $postA, $this->likeId);
        $mineB = $this->seedReaction($this->member->id, 'post', $postB, $this->likeId);
        $theirs = $this->seedReaction($other->id, 'post', $postA, $this->dislikeId);

        $response = $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT, ['ids' => [$mineA, $mineB, $theirs, 99999999]]);

        $response->assertOk()->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseMissing('board_reactions', ['id' => $mineA]);
        $this->assertDatabaseMissing('board_reactions', ['id' => $mineB]);
        $this->assertDatabaseHas('board_reactions', ['id' => $theirs]);
    }

    /**
     * 일괄 해제는 ids 를 검증한다.
     *
     * @scenario case=bulk_remove_validates_ids
     *
     * @effects bulk_remove_validates_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_validates_ids(): void
    {
        $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT, ['ids' => []])
            ->assertStatus(422);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT, ['ids' => range(1, 101)])
            ->assertStatus(422);
    }

    /**
     * 반응이 꺼진 게시판이라도 해제는 허용한다 (결정 R11).
     *
     * @scenario case=remove_allowed_when_reaction_off
     *
     * @effects remove_allowed_when_board_reaction_off
     *
     * @return void 반환값 없음
     */
    public function test_remove_allowed_when_board_use_reaction_off(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $id = $this->seedReaction($this->member->id, 'post', $postId, $this->likeId);

        $this->updateBoardSettings(['use_reaction' => false]);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$id)
            ->assertOk();

        $this->assertDatabaseMissing('board_reactions', ['id' => $id]);
    }

    /**
     * 대상이 하드 삭제된 반응도 해제할 수 있다 (재집계 대상이 없을 뿐).
     *
     * @scenario case=remove_tolerates_hard_deleted_target
     *
     * @effects remove_tolerates_missing_target
     *
     * @return void 반환값 없음
     */
    public function test_remove_tolerates_hard_deleted_target(): void
    {
        $id = $this->seedReaction($this->member->id, 'post', 99999999, $this->likeId);

        $this->actingAs($this->member, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$id)
            ->assertOk();

        $this->assertDatabaseMissing('board_reactions', ['id' => $id]);
    }

    /**
     * 비로그인은 401.
     *
     * @scenario case=anonymous_user_unauthorized
     *
     * @effects guest_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->deleteJson(self::ENDPOINT, ['ids' => [1]])->assertUnauthorized();
    }

    /**
     * 활동 통계에 반응·신고 카운터가 실린다.
     *
     * @scenario case=activity_stats_include_totals
     *
     * @effects activity_stats_include_reaction_and_report_totals
     *
     * @return void 반환값 없음
     */
    public function test_activity_stats_include_total_reactions_and_reports(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $this->seedReaction($this->member->id, 'post', $postId, $this->likeId);

        $response = $this->actingAs($this->member, 'sanctum')
            ->getJson('/api/modules/sirsoft-board/me/activity-stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.total_reactions'));
        $this->assertSame(0, $response->json('data.total_reports'));
    }

    /**
     * 내 반응 목록이 처리 중 서버 오류를 만나면 입력 오류(400)가 아니라 서버 오류(500)로 답한다.
     *
     * 종전에는 400 으로 답해 화면이 「입력을 확인하세요」 류로 받아들였다(1.2.0 Fixed).
     *
     * @scenario case=list_mixes_post_and_comment_rows
     *
     * @effects my_reactions_server_error_returns_500
     *
     * @return void 반환값 없음
     */
    public function test_index_server_error_returns_500(): void
    {
        $this->mock(ReactionService::class)
            ->shouldReceive('paginateForUser')
            ->andThrow(new \RuntimeException('simulated failure'));

        $this->actingAs($this->member, 'sanctum')
            ->getJson(self::ENDPOINT)
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }
}
