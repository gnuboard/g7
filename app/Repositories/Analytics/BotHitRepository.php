<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Models\Analytics\AnalyticsBotHit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 봇 요청 Repository
 */
class BotHitRepository implements BotHitRepositoryInterface
{
    /**
     * 봇 요청을 기록합니다.
     *
     * @param  array<string, mixed>  $attributes  봇 요청 속성
     * @return AnalyticsBotHit 생성된 봇 요청
     */
    public function create(array $attributes): AnalyticsBotHit
    {
        return AnalyticsBotHit::query()->create($attributes);
    }

    /**
     * 최근 봇 요청을 조회합니다.
     *
     * @param  int  $limit  최대 건수
     * @return Collection<int, AnalyticsBotHit> 봇 요청 목록
     */
    public function recent(int $limit = 50): Collection
    {
        return AnalyticsBotHit::query()
            ->select(['id', 'bot_name', 'url', 'ip', 'cache_status', 'occurred_at'])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 보존기간이 지난 봇 요청을 나눠서 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 요청이 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsBotHit::query()
                ->where('occurred_at', '<', $before)
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 기간 안의 봇 기록을 ID 순서로 나눠 읽습니다.
     *
     * @param  Carbon  $from  시작 시각 (포함, UTC)
     * @param  Carbon  $to  끝 시각 (미포함, UTC)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkBetween(Carbon $from, Carbon $to, int $chunkSize, callable $callback): void
    {
        AnalyticsBotHit::query()
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $to)
            ->orderBy('id')
            ->chunkById(max(1, $chunkSize), $callback);
    }

    /**
     * 기준 시각보다 오래된 봇 기록 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countOlderThan(Carbon $before): int
    {
        return AnalyticsBotHit::query()->where('occurred_at', '<', $before)->count();
    }
}
