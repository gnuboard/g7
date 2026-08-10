<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Exceptions\PostNotFoundException;
use Modules\Sirsoft\Board\Exceptions\ReactionNotAllowedException;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * ReactionService 검증.
 *
 * 등록/전환/해제, reaction_counts 증감 정합성(전환 포함), use_reaction off,
 * 비활성 유형, 본인 글 차단, 스코프 검증을 검증한다 (이슈 #525 §10 테스트 범위).
 */
class ReactionServiceTest extends BoardTestCase
{
    private ReactionService $service;

    private int $likeId;

    private int $dislikeId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);

        $this->service = app(ReactionService::class);
    }

    /**
     * 반응이 없던 게시글에 반응하면 신규 등록되고 카운트가 +1 된다.
     *
     * @scenario case=register_first_like
     * @effects register_inserts_row_and_increments_count
     */
    public function test_react_adds_new_reaction_and_increments_count(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $result = $this->service->react($reactor->id, $this->board, $postId, $this->likeId);

        $this->assertSame('add', $result['action']);
        $this->assertSame($this->likeId, $result['reaction_type_id']);
        $this->assertSame(1, $result['reaction_counts'][(string) $this->likeId]);

        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'reaction_type_id' => $this->likeId,
        ]);
    }

    /**
     * 다른 유형으로 전환하면 이전 유형 -1·신규 유형 +1 이 동시 반영된다 (단일 트랜잭션).
     *
     * @scenario case=switch_like_to_dislike
     * @effects switch_updates_row_and_adjusts_both_counts, switch_count_atomic_in_single_transaction
     */
    public function test_react_switches_type_and_adjusts_both_counts(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->service->react($reactor->id, $this->board, $postId, $this->likeId);
        $result = $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->dislikeId);

        $this->assertSame('change', $result['action']);
        $this->assertSame(0, $result['reaction_counts'][(string) $this->likeId]);
        $this->assertSame(1, $result['reaction_counts'][(string) $this->dislikeId]);

        // 사용자당 1행 유지 (전환은 UPDATE)
        $this->assertSame(1, Reaction::query()->where('user_id', $reactor->id)->where('target_id', $postId)->count());
        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
            'reaction_type_id' => $this->dislikeId,
        ]);
    }

    /**
     * 같은 유형을 재클릭하면 해제되어 이력 행이 삭제되고 카운트가 -1 된다.
     *
     * @scenario case=remove_same_type
     * @effects remove_deletes_row_and_decrements_count
     */
    public function test_react_same_type_removes_reaction_and_decrements_count(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->service->react($reactor->id, $this->board, $postId, $this->likeId);
        $result = $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->likeId);

        $this->assertSame('remove', $result['action']);
        $this->assertNull($result['reaction_type_id']);
        $this->assertSame(0, $result['reaction_counts'][(string) $this->likeId]);

        $this->assertDatabaseMissing('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
        ]);
    }

    /**
     * 카운트는 0 미만으로 내려가지 않는다 (해제 시 클램프).
     *
     * @scenario case=count_never_negative_on_over_remove
     * @effects reaction_count_never_negative
     */
    public function test_reaction_count_never_goes_below_zero(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id, 'reaction_counts' => json_encode([])]);

        $this->service->react($reactor->id, $this->board, $postId, $this->likeId);
        $result = $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->likeId);

        $this->assertGreaterThanOrEqual(0, $result['reaction_counts'][(string) $this->likeId]);
    }

    /**
     * 캐시된 reaction_counts 가 실제 board_reactions 행 수와 어긋나 있어도(예: 짧은
     * 시간에 연속 전송된 요청이 서버 도착 순서와 다르게 처리되어 캐시가 오염된 경우),
     * 다음 반응 처리가 실제 행을 재집계해 캐시를 실제 데이터와 일치하는 값으로
     * 정정한다. 델타(증감) 누적 방식은 이런 오염을 스스로 고치지 못하고 계속
     * 누적시키므로, 실제 COUNT 재집계 방식임을 검증한다.
     *
     * @scenario case=polluted_cache_self_heals_on_next_reaction
     * @effects reaction_counts_matches_actual_rows_after_recalculation
     */
    public function test_react_recalculates_from_actual_rows_and_heals_polluted_cache(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        // 캐시에는 실제와 무관한 오염된 값(양쪽 다 1)을 미리 심어둔다.
        $postId = $this->createTestPost([
            'user_id' => $author->id,
            'reaction_counts' => json_encode([(string) $this->likeId => 1, (string) $this->dislikeId => 1]),
        ]);

        $result = $this->service->react($reactor->id, $this->board, $postId, $this->likeId);

        // 실제 반응 행은 이번에 등록된 like 1건뿐 — 오염된 dislike=1 은 사라지고
        // 캐시가 실제 상태(like=1, dislike=0)로 정정되어야 한다.
        $this->assertSame(1, $result['reaction_counts'][(string) $this->likeId]);
        $this->assertSame(0, $result['reaction_counts'][(string) $this->dislikeId]);
        $this->assertSame(
            1,
            Reaction::query()->where('target_id', $postId)->where('target_type', 'post')->count(),
        );
    }

    /**
     * use_reaction 이 꺼진 게시판은 반응이 차단된다.
     *
     * @scenario case=use_reaction_off_react_blocked
     * @effects use_reaction_off_react_returns_422
     */
    public function test_react_blocked_when_use_reaction_off(): void
    {
        $this->updateBoardSettings(['use_reaction' => false]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->expectException(ReactionNotAllowedException::class);
        $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->likeId);
    }

    /**
     * 게시판이 켜지 않은(비활성) 유형은 차단된다.
     *
     * @scenario case=inactive_type_react_blocked
     * @effects inactive_type_react_returns_422
     */
    public function test_react_blocked_for_inactive_type_on_board(): void
    {
        $this->updateBoardSettings(['active_reaction_types' => ['like']]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->expectException(ReactionNotAllowedException::class);
        $this->service->react($reactor->id, $this->board->fresh(), $postId, $this->dislikeId);
    }

    /**
     * 본인 글에는 반응할 수 없다.
     *
     * @scenario case=self_post_react_blocked
     * @effects self_post_react_returns_422
     */
    public function test_react_blocked_on_own_post(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->expectException(ReactionNotAllowedException::class);
        $this->service->react($author->id, $this->board, $postId, $this->likeId);
    }

    /**
     * 다른 게시판 소속 게시글 ID 로는 반응할 수 없다 (스코프 검증).
     *
     * @scenario case=post_not_in_board
     * @effects post_not_in_board_returns_404
     */
    public function test_react_blocked_for_post_not_in_board(): void
    {
        $reactor = $this->createUser();

        $this->expectException(PostNotFoundException::class);
        $this->service->react($reactor->id, $this->board, 999999, $this->likeId);
    }

    /**
     * 스코프 검증 통과 직후·락 획득 시점 사이에 게시글이 삭제되는 레이스 상황에서도
     * PostNotFoundException 으로 일관되게 처리된다.
     *
     * lockPostForReaction() 은 findOrFail() 을 사용해 락을 거는데, 이 시점에 게시글이
     * 없으면 Eloquent 의 ModelNotFoundException 이 던져진다. 이를 감싸지 않으면 컨트롤러의
     * catch 블록 어디에도 걸리지 않아 일반 \Exception 으로 떨어져 500 에러가 되고, 사용자는
     * "반응 처리에 실패했습니다"만 보게 되어 게시글이 없다는 실제 원인을 알 수 없다.
     *
     * @scenario case=post_deleted_between_scope_check_and_lock
     * @effects post_not_in_board_returns_404
     */
    public function test_react_wraps_model_not_found_during_lock_as_post_not_found(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $repository = $this->mock(ReactionRepositoryInterface::class);
        $repository->shouldReceive('lockPostForReaction')
            ->once()
            ->with($postId)
            ->andThrow(new ModelNotFoundException());

        $service = new ReactionService(
            $repository,
            app(\Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface::class),
            app(\Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface::class),
        );

        $this->expectException(PostNotFoundException::class);
        $service->react($reactor->id, $this->board, $postId, $this->likeId);
    }
}
