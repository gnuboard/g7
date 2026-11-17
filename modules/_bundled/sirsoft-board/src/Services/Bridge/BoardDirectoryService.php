<?php

namespace Modules\Sirsoft\Board\Services\Bridge;

use Modules\Sirsoft\Board\Contracts\BoardDirectory;
use Modules\Sirsoft\Board\Contracts\Data\BoardSummary;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;

/**
 * 게시판 조회 공개 계약 구현 — 게시판 저장소에 위임하고 요약 DTO 로 바꿔 돌려준다.
 *
 * @internal 다른 확장은 {@see BoardDirectory} 로 주입받는다.
 */
class BoardDirectoryService implements BoardDirectory
{
    /**
     * @param  BoardRepositoryInterface  $boards  게시판 저장소
     */
    public function __construct(private BoardRepositoryInterface $boards) {}

    /**
     * {@inheritDoc}
     *
     * @param  string  $slug  게시판 slug
     * @return BoardSummary|null 없으면 null
     */
    public function findBySlug(string $slug): ?BoardSummary
    {
        $slug = trim($slug);
        $board = $slug === '' ? null : $this->boards->findBySlug($slug);

        return $board === null ? null : BoardSummary::fromModel($board);
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $id  게시판 id
     * @return BoardSummary|null 없으면 null
     */
    public function findById(int $id): ?BoardSummary
    {
        $board = $id > 0 ? $this->boards->find($id) : null;

        return $board === null ? null : BoardSummary::fromModel($board);
    }

    /**
     * {@inheritDoc}
     *
     * 게시판 수는 운영상 수십 개라 한 번에 읽어 거른다(id 묶음 조회 저장소 메서드가 없다).
     *
     * @param  array<int, int>  $ids  게시판 id 목록
     * @return array<int, BoardSummary> id => 요약
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        if (count($ids) === 1) {
            $one = $this->findById($ids[0]);

            return $one === null ? [] : [$ids[0] => $one];
        }

        $byId = $this->boards->all()->keyBy(fn (Board $board) => (int) $board->id);
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[$id] = BoardSummary::fromModel($byId[$id]);
            }
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $slugs  게시판 slug 목록
     * @return array<string, string> slug => 게시판 이름
     */
    public function labelsBySlugs(array $slugs): array
    {
        $labels = [];
        foreach (array_unique(array_filter(array_map(fn ($slug) => trim((string) $slug), $slugs))) as $slug) {
            $board = $this->boards->findBySlug($slug);
            if ($board !== null) {
                $labels[$slug] = $board->getLocalizedName();
            }
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어
     * @param  int  $limit  최대 건수
     * @return array<int, BoardSummary> 요약 목록
     */
    public function search(string $keyword, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $keyword = trim($keyword);
        $boards = $keyword === '' ? $this->boards->all() : $this->boards->search($keyword);

        return $boards->take($limit)->map(fn (Board $board) => BoardSummary::fromModel($board))->values()->all();
    }
}
