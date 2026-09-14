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
    /**
     * PostScrapRepository 생성자
     *
     * @param  PostScrap  $model  스크랩 모델
     */
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
    public function paginateByUser(int $userId, int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        $query = $this->model
            ->where('user_id', $userId)
            // 원글이 소프트 삭제돼도 카드를 "삭제된 게시글" 로 표시하므로 withTrashed 로 함께 싣는다.
            ->with([
                'post' => fn ($q) => $q->withTrashed(),
                'post.user',
                'board',
            ]);

        if ($search) {
            $keyword = $this->escapeLikeKeyword($search);
            // whereHas 는 기본적으로 소프트 삭제된 원글을 제외한다 — 삭제된 원글은 응답에서
            // title 자체가 노출되지 않으므로(PostScrapResource), 검색 대상에서도 자연스럽게 빠진다.
            $query->whereHas('post', fn ($q) => $q->where('title', 'like', "%{$keyword}%"));
        }

        return $query
            ->orderByDesc('created_at')
            // 전순서 보장 — 한 번에 여러 건을 담았을 때의 created_at 동률 대비
            ->orderByDesc('id')
            // audit:allow repository-paginate-column-pruning reason: 사용자 1명에 종속된 스크랩 목록 —
            // where(user_id) 로 이미 좁혀지고, 피벗 성격의 테이블이라 넓은 컬럼이 없다
            ->paginate($perPage);
    }

    /**
     * LIKE 쿼리용 키워드를 이스케이프합니다.
     * MySQL LIKE 와일드카드 문자(%, _)를 이스케이프하여 특수문자 검색을 가능하게 합니다.
     *
     * @param  string  $keyword  검색 키워드
     * @return string 이스케이프된 키워드
     */
    private function escapeLikeKeyword(string $keyword): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
    }
}
