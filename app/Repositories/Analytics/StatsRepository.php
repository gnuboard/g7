<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Models\Analytics\AnalyticsDimensionStat;
use App\Models\Analytics\AnalyticsStatsDaily;
use App\Models\Analytics\AnalyticsStatsHourly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 집계 Repository
 *
 * 시간·일·차원 세 집계 표를 함께 다룹니다. 세 표는 언제나 한 배치가 같은 기간을
 * 한꺼번에 다시 쓰므로 표마다 Repository 를 쪼개면 트랜잭션 경계가 흩어집니다.
 */
class StatsRepository implements StatsRepositoryInterface
{
    /**
     * 한 번에 넣을 행 수
     *
     * 차원 집계는 하루치가 수천 행이 될 수 있어 한 문장으로 넣으면 쿼리가 너무
     * 길어집니다.
     */
    private const INSERT_CHUNK = 500;

    /**
     * 지정한 기간·유형의 시간 집계 행을 지우고 새로 넣습니다.
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    public function replaceHourly(string $hourStart, array $rows): int
    {
        AnalyticsStatsHourly::query()->where('hour_start', $hourStart)->delete();

        return $this->insertChunked(AnalyticsStatsHourly::query()->getModel()->getTable(), $rows);
    }

    /**
     * 지정한 일자의 일 집계 행을 지우고 새로 넣습니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    public function replaceDaily(string $date, array $rows): int
    {
        AnalyticsStatsDaily::query()->where('date', $date)->delete();

        return $this->insertChunked(AnalyticsStatsDaily::query()->getModel()->getTable(), $rows);
    }

    /**
     * 지정한 일자의 차원 집계 행을 지우고 새로 넣습니다.
     *
     * @param  string  $date  사이트 표준시 기준 일자 (Y-m-d)
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    public function replaceDimensions(string $date, array $rows): int
    {
        AnalyticsDimensionStat::query()->where('date', $date)->delete();

        return $this->insertChunked(AnalyticsDimensionStat::query()->getModel()->getTable(), $rows);
    }

    /**
     * 기간의 일 집계를 조회합니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return Collection<int, object> 일 집계 행 목록
     */
    public function daily(string $from, string $to, array $visitorTypes = []): Collection
    {
        return AnalyticsStatsDaily::query()
            ->whereBetween('date', [$from, $to])
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->orderBy('date')
            ->get();
    }

    /**
     * 기간의 시간 집계를 조회합니다.
     *
     * @param  string  $from  시작 시각 (Y-m-d H:i:s)
     * @param  string  $to  종료 시각 (Y-m-d H:i:s)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return Collection<int, object> 시간 집계 행 목록
     */
    public function hourly(string $from, string $to, array $visitorTypes = []): Collection
    {
        return AnalyticsStatsHourly::query()
            ->whereBetween('hour_start', [$from, $to])
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->orderBy('hour_start')
            ->get();
    }

    /**
     * 차원 집계를 라벨별로 합산해 조회합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  정렬·검색·페이지 옵션
     * @return Collection<int, object> 합산된 차원 행 목록
     */
    public function dimension(string $dimension, string $from, string $to, array $options = []): Collection
    {
        $visitorTypes = $options['visitor_types'] ?? [];
        $limit = (int) ($options['limit'] ?? 25);
        $offset = (int) ($options['offset'] ?? 0);
        $search = $options['search'] ?? null;

        return AnalyticsDimensionStat::query()
            ->selectRaw('label_hash, MIN(label) as label, MIN(action_name_id) as action_name_id, '
                .'SUM(visits) as visits, SUM(hits) as hits, SUM(entries) as entries, '
                .'SUM(exits) as exits, SUM(bounces) as bounces, SUM(total_time_seconds) as total_time_seconds')
            ->where('dimension', $dimension)
            ->whereBetween('date', [$from, $to])
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->when($search !== null && $search !== '', fn (Builder $q) => $q->where('label', 'like', '%'.$search.'%'))
            ->groupBy('label_hash')
            ->orderByDesc($options['sort'] ?? 'visits')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * 일 집계에서 방문자 수가 가장 많았던 날을 찾습니다.
     *
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return object|null 날짜와 방문자 수 (집계가 없으면 null)
     */
    public function maxDailyVisitors(array $visitorTypes = []): ?object
    {
        return AnalyticsStatsDaily::query()
            ->selectRaw('date, SUM(visitors) as visitors')
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->groupBy('date')
            ->orderByDesc('visitors')
            ->first();
    }

    /**
     * 일 집계의 방문자 수를 모두 더합니다.
     *
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @param  string|null  $before  이 일자 이전까지만 (Y-m-d)
     * @return int 방문자 수 합계
     */
    public function sumDailyVisitors(array $visitorTypes = [], ?string $before = null): int
    {
        return (int) AnalyticsStatsDaily::query()
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->when($before !== null, fn (Builder $q) => $q->where('date', '<', $before))
            ->sum('visitors');
    }

    /**
     * 보존기간이 지난 시간 집계를 삭제합니다.
     *
     * @param  Carbon  $before  이 시각 이전 집계가 대상
     * @param  int  $chunkSize  한 번에 지울 건수
     * @return int 삭제한 행 수
     */
    public function deleteHourlyOlderThanInBatches(Carbon $before, int $chunkSize = 1000): int
    {
        $deleted = 0;

        do {
            $count = AnalyticsStatsHourly::query()
                ->where('hour_start', '<', $before->format('Y-m-d H:i:s'))
                ->limit($chunkSize)
                ->delete();

            $deleted += $count;
        } while ($count > 0);

        return $deleted;
    }

    /**
     * 확장이 등록한 차원의 집계 행을 삭제합니다.
     *
     * @param  string  $extensionIdentifier  확장 식별자
     * @return int 삭제한 행 수
     */
    public function deleteDimensionsForExtension(string $extensionIdentifier): int
    {
        return AnalyticsDimensionStat::query()
            ->where('dimension', 'like', 'ext:'.$extensionIdentifier.':%')
            ->delete();
    }

    /**
     * 행 목록을 나눠서 넣습니다.
     *
     * @param  string  $table  대상 테이블명
     * @param  array<int, array<string, mixed>>  $rows  넣을 행 목록
     * @return int 넣은 행 수
     */
    private function insertChunked(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }

        return count($rows);
    }
}
