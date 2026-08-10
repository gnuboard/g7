<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionTypeRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * ReactionRepository / ReactionTypeRepository 검증.
 *
 * upsert(등록/전환)·delete(해제)·recalculatePostReactionCounts(실제 행 기준 재집계) 및
 * 유형 조회(getActive/findByCodes/findById/findByIds)를 검증한다.
 */
class ReactionRepositoryTest extends BoardTestCase
{
    private ReactionRepositoryInterface $reactionRepository;

    private ReactionTypeRepositoryInterface $reactionTypeRepository;

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

        $this->reactionRepository = app(ReactionRepositoryInterface::class);
        $this->reactionTypeRepository = app(ReactionTypeRepositoryInterface::class);
    }

    /**
     * upsert 는 없으면 INSERT, 있으면 유형만 UPDATE 한다 (사용자당 1행 유지).
     *
     * @scenario case=register_first_like
     * @effects register_inserts_row_and_increments_count
     */
    public function test_upsert_inserts_then_updates_single_row(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $first = $this->reactionRepository->upsert($user->id, 'post', $postId, $this->likeId, $this->board->id);
        $this->assertSame($this->likeId, (int) $first->reaction_type_id);

        $second = $this->reactionRepository->upsert($user->id, 'post', $postId, $this->dislikeId, $this->board->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame($this->dislikeId, (int) $second->reaction_type_id);
        $this->assertSame(1, \Modules\Sirsoft\Board\Models\Reaction::query()
            ->where('user_id', $user->id)->where('target_id', $postId)->count());
    }

    /**
     * findByUserAndTarget 은 사용자+대상 유일 키로 조회한다.
     */
    public function test_find_by_user_and_target(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $this->reactionRepository->upsert($user->id, 'post', $postId, $this->likeId, $this->board->id);

        $found = $this->reactionRepository->findByUserAndTarget($user->id, 'post', $postId);
        $this->assertNotNull($found);
        $this->assertSame($this->likeId, (int) $found->reaction_type_id);

        $this->assertNull($this->reactionRepository->findByUserAndTarget($user->id, 'post', $postId + 999));
    }

    /**
     * delete 는 이력 행을 삭제한다.
     */
    public function test_delete_removes_reaction(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();
        $reaction = $this->reactionRepository->upsert($user->id, 'post', $postId, $this->likeId, $this->board->id);

        $this->assertTrue($this->reactionRepository->delete($reaction));
        $this->assertNull($this->reactionRepository->findByUserAndTarget($user->id, 'post', $postId));
    }

    /**
     * recalculatePostReactionCounts 는 board_reactions 실제 행 수를 그대로 반영한다
     * (델타 누적이 아닌 COUNT 재집계 — 캐시가 실제 데이터와 항상 일치해야 함).
     */
    public function test_recalculate_post_reaction_counts_reflects_actual_rows(): void
    {
        $postId = $this->createTestPost(['reaction_counts' => json_encode([(string) $this->likeId => 99])]);

        // 실제 반응 행 없음 → 캐시에 남아있던 오염된 값(99)은 사라지고, 이전에
        // 노출됐던 유형 키는 0으로 유지된다 (API 응답 계약 — 키 자체는 보존).
        $counts = $this->reactionRepository->recalculatePostReactionCounts($postId);
        $this->assertSame([(string) $this->likeId => 0], $counts);

        $userA = $this->createUser();
        $userB = $this->createUser();
        $this->reactionRepository->upsert($userA->id, 'post', $postId, $this->likeId, $this->board->id);
        $this->reactionRepository->upsert($userB->id, 'post', $postId, $this->dislikeId, $this->board->id);

        $counts = $this->reactionRepository->recalculatePostReactionCounts($postId);
        $this->assertSame(1, $counts[(string) $this->likeId]);
        $this->assertSame(1, $counts[(string) $this->dislikeId]);

        $post = Post::findOrFail($postId);
        $this->assertSame($counts, $post->reaction_counts);
    }

    /**
     * getActive 는 활성 유형을 display_order 순으로 반환한다.
     */
    public function test_get_active_returns_ordered_active_types(): void
    {
        $active = $this->reactionTypeRepository->getActive();

        $this->assertSame(['like', 'dislike'], $active->pluck('code')->all());
    }

    /**
     * findByCodes 는 code 목록으로 활성 유형만 조회한다.
     */
    public function test_find_by_codes(): void
    {
        $found = $this->reactionTypeRepository->findByCodes(['dislike']);
        $this->assertSame(['dislike'], $found->pluck('code')->all());

        $this->assertTrue($this->reactionTypeRepository->findByCodes([])->isEmpty());
    }

    /**
     * findById / findByIds 로 유형을 조회한다.
     */
    public function test_find_by_id_and_ids(): void
    {
        $this->assertSame('like', $this->reactionTypeRepository->findById($this->likeId)?->code);
        $this->assertNull($this->reactionTypeRepository->findById(999999));

        $byIds = $this->reactionTypeRepository->findByIds([$this->likeId, $this->dislikeId]);
        $this->assertSame(['like', 'dislike'], $byIds->pluck('code')->all());
        $this->assertTrue($this->reactionTypeRepository->findByIds([])->isEmpty());
    }
}
