<?php

namespace App\Repositories\Analytics;

use App\Analytics\AnalyticsClock;
use App\Contracts\Repositories\Analytics\BotHitRepositoryInterface;
use App\Models\Analytics\AnalyticsBotHit;
use App\Repositories\Concerns\FiltersByDateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 봇 요청 Repository
 */
class BotHitRepository implements BotHitRepositoryInterface
{
    use FiltersByDateRange;

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
     * @param  string|null  $from  시작일 (Y-m-d, 사이트 표준시 — null 이면 제한 없음)
     * @param  string|null  $to  종료일 (Y-m-d, 사이트 표준시 — null 이면 제한 없음)
     * @return Collection<int, AnalyticsBotHit> 봇 요청 목록 (최신순)
     */
    public function recent(int $limit = 50, ?string $from = null, ?string $to = null): Collection
    {
        $query = AnalyticsBotHit::query()
            ->select(['id', 'bot_name', 'url', 'ip', 'cache_status', 'occurred_at']);

        // 봇 화면의 다른 숫자처럼 사이트 표준시 하루로 자른다 — UTC 하루면 서울 새벽 요청이 전날로 간다.
        $this->applyDateRangeFilterInTimezone($query, 'occurred_at', $from, $to, AnalyticsClock::siteTimezone());

        return $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
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

    /**
     * 기간 안의 봇 기록을 나눠서 순회합니다.
     *
     * @param  string|null  $from  시작 일자 (Y-m-d, null 이면 처음부터)
     * @param  string|null  $to  끝 일자 (Y-m-d, null 이면 끝까지)
     * @param  int  $chunkSize  한 번에 읽을 건수
     * @param  callable  $callback  청크마다 부를 콜백
     * @return void 없음
     */
    public function chunkInDateRange(?string $from, ?string $to, int $chunkSize, callable $callback): void
    {
        $query = AnalyticsBotHit::query();

        // 같은 익명화 실행이 방문 원장을 `visit_date`(사이트 표준시 일자)로 자른다 — 곁 원장도
        // 사이트 표준시 하루로 잘라야 같은 기간을 가리킨다(UTC 하루면 한국 새벽 기록이 어긋난다).
        $this->applyDateRangeFilterInTimezone($query, 'occurred_at', $from, $to, AnalyticsClock::siteTimezone());

        $query->orderBy('id')->chunkById(max(1, $chunkSize), $callback);
    }

    /**
     * 봇 기록의 주소를 덮어씁니다.
     *
     * @param  AnalyticsBotHit  $hit  대상 기록
     * @param  string|null  $ip  새 주소 (null 이면 지움)
     * @return void 없음
     */
    public function updateIp(AnalyticsBotHit $hit, ?string $ip): void
    {
        $hit->forceFill(['ip' => $ip])->save();
    }
}
