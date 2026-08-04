<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Models\PostScrap;
use Modules\Sirsoft\Board\Repositories\Contracts\PostScrapRepositoryInterface;

/**
 * 게시글 스크랩 Repository 구현체
 */
class PostScrapRepository implements PostScrapRepositoryInterface
{
    public function __construct(
        protected PostScrap $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function toggle(int $userId, int $postId, ?int $boardId = null): array
    {
        $existing = $this->model
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->first();

        if ($existing) {
            $existing->delete();

            return ['added' => false, 'scrap' => null];
        }

        $scrap = $this->model->create([
            'user_id' => $userId,
            'post_id' => $postId,
            'board_id' => $boardId,
        ]);

        return ['added' => true, 'scrap' => $scrap];
    }

    /**
     * {@inheritDoc}
     */
    public function deleteByIdAndUser(int $id, int $userId): ?PostScrap
    {
        $scrap = $this->model
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (! $scrap) {
            return null;
        }

        $scrap->delete();

        return $scrap;
    }

    /**
     * {@inheritDoc}
     */
    public function isScraped(int $userId, int $postId): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('post_id', $postId)
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function paginateByUser(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->model
            ->where('user_id', $userId)
            // 원글이 소프트 삭제돼도 카드를 "삭제된 게시글" 로 표시하므로 withTrashed 로 함께 싣는다.
            ->with([
                'post' => fn ($query) => $query->withTrashed(),
                'post.user',
                'board',
            ])
            ->orderByDesc('created_at')
            // 전순서 보장 — 한 번에 여러 건을 담았을 때의 created_at 동률 대비
            ->orderByDesc('id')
            // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 스크랩 목록 —
            // where(user_id) 로 이미 좁혀지고, 피벗 성격의 테이블이라 넓은 컬럼이 없다
            ->paginate($perPage);
    }
}
