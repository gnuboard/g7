<?php

namespace Modules\Sirsoft\Board\Repositories;

use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Reaction;
use Modules\Sirsoft\Board\Repositories\Contracts\ReactionRepositoryInterface;

/**
 * 반응 이력 Repository
 *
 * 반응 이력 데이터 접근 계층을 담당합니다.
 */
class ReactionRepository implements ReactionRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function findByUserAndTarget(int $userId, string $targetType, int $targetId): ?Reaction
    {
        return Reaction::query()
            ->where('user_id', $userId)
            ->byTarget($targetType, $targetId)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function lockPostForReaction(int $postId): void
    {
        Post::query()->lockForUpdate()->findOrFail($postId);
    }

    /**
     * {@inheritDoc}
     */
    public function upsert(int $userId, string $targetType, int $targetId, int $reactionTypeId, ?int $boardId): Reaction
    {
        return Reaction::updateOrCreate(
            [
                'user_id' => $userId,
                'target_type' => $targetType,
                'target_id' => $targetId,
            ],
            [
                'reaction_type_id' => $reactionTypeId,
                'board_id' => $boardId,
            ],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(Reaction $reaction): bool
    {
        return (bool) $reaction->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function recalculatePostReactionCounts(int $postId): array
    {
        /** @var Post $post */
        $post = Post::query()->lockForUpdate()->findOrFail($postId);

        // 이전에 캐시에 등장했던 유형은 반응이 0건이 되어도 키 자체는 유지한다
        // (API 응답 계약 — 한 번 노출된 유형의 카운트는 0으로라도 항상 존재).
        $zeroed = array_fill_keys(array_keys($post->reaction_counts ?? []), 0);

        $actual = Reaction::query()
            ->byTarget('post', $postId)
            ->selectRaw('reaction_type_id, COUNT(*) as total')
            ->groupBy('reaction_type_id')
            ->pluck('total', 'reaction_type_id')
            ->mapWithKeys(fn ($total, $typeId) => [(string) $typeId => (int) $total])
            ->toArray();

        // array_merge 는 숫자형 키(반응 유형 ID)를 재색인해 순서/키를 잃는다
        // (예: [1=>1] 이 [0=>1] 로 바뀌어 JSON 직렬화 시 객체 대신 리스트가 됨).
        // + 연산자는 키를 그대로 보존하면서 좌측을 우선하므로 실제 COUNT($actual)로
        // zeroed 를 덮어쓰려면 우측에 두어야 한다.
        $counts = $actual + $zeroed;

        $post->reaction_counts = $counts;
        $post->save();

        return $counts;
    }
}
