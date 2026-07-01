<?php

namespace Modules\Sirsoft\Board\Repositories;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardStatRepositoryInterface;

/**
 * 게시판 일별 집계 Repository
 *
 * board_stats 테이블의 upsert / 범위 조회를 담당합니다.
 */
class BoardStatRepository implements BoardStatRepositoryInterface
{
    /**
     * 특정 날짜의 집계 행을 upsert 합니다 (멱등).
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @param  int  $postCount  게시글 수
     * @param  int  $commentCount  댓글 수
     * @return BoardStat upsert 된 집계 행
     */
    public function upsertForDate(string $date, int $postCount, int $commentCount): BoardStat
    {
        return BoardStat::updateOrCreate(
            ['date' => $date],
            ['post_count' => $postCount, 'comment_count' => $commentCount],
        );
    }

    /**
     * 날짜 범위(포함)의 집계 행을 날짜 오름차순으로 조회합니다.
     *
     * @param  string  $startDate  시작 날짜 (Y-m-d, 포함)
     * @param  string  $endDate  종료 날짜 (Y-m-d, 포함)
     * @return Collection<int, BoardStat> 날짜 오름차순 집계 행 컬렉션
     */
    public function getByDateRange(string $startDate, string $endDate): Collection
    {
        return BoardStat::query()
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();
    }

    /**
     * 특정 날짜의 집계 행을 조회합니다.
     *
     * @param  string  $date  집계 기준 날짜 (Y-m-d)
     * @return BoardStat|null 집계 행 또는 null
     */
    public function findByDate(string $date): ?BoardStat
    {
        return BoardStat::query()->where('date', $date)->first();
    }
}
