<?php

namespace App\Repositories\Analytics;

use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\StatsDimension;
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
     * 지정한 시각의 시간 집계 행을 **방문자 유형 구분 없이 전부** 지우고 새로 넣습니다.
     *
     * 유형별로 나눠 부르면 안 됩니다 — 첫 호출이 다른 유형 행까지 지웁니다. 자세한
     * 계약은 인터페이스 쪽 설명을 보세요.
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
        $terms = array_values(array_filter(
            array_unique((array) ($options['search'] ?? [])),
            fn ($term): bool => is_string($term) && $term !== '',
        ));

        return AnalyticsDimensionStat::query()
            ->selectRaw('label_hash, MIN(label) as label, MIN(action_name_id) as action_name_id, '
                .'SUM(visits) as visits, SUM(hits) as hits, SUM(entries) as entries, '
                .'SUM(exits) as exits, SUM(bounces) as bounces, SUM(total_time_seconds) as total_time_seconds')
            ->where('dimension', $dimension)
            ->whereBetween('date', [$from, $to])
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            // 검색어는 글자 그대로 찾는다 — `%`·`_` 를 풀어 두면 밑줄 하나가 모든 라벨과 맞는다. 여러 형태가 오면
            // (화면용·저장용) 어느 하나와 맞으면 잡는다.
            ->when($terms !== [], fn (Builder $q) => $q->where(function (Builder $any) use ($terms): void {
                foreach ($terms as $term) {
                    $any->orWhere('label', 'like', '%'.addcslashes($term, '%_\\').'%');
                }
            }))
            ->groupBy('label_hash')
            ->orderByDesc($options['sort'] ?? 'visits')
            // CSV 는 쪽마다 독립 쿼리를 던진다. 정렬 키가 지표 하나뿐이면 동점 구간의
            // 순서가 쿼리마다 달라져 어떤 라벨은 두 번 실리고 어떤 라벨은 빠진다.
            // 묶음 키로 순서를 완전히 결정해 둔다.
            ->orderBy('label_hash')
            ->offset($offset)
            ->limit($limit)
            ->get();
    }

    /**
     * 차원 집계의 기간 합계(요청 수·라벨 수)를 조회합니다.
     *
     * 목록은 쪽으로 잘려 오므로 그 행을 더한 값은 「이 쪽의 합」일 뿐이다 — 기간 전체를 말하는
     * 숫자는 쪽과 무관하게 따로 센다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return array{hits: int, labels: int} 기간 합계
     */
    public function dimensionTotals(string $dimension, string $from, string $to, array $visitorTypes = []): array
    {
        $row = AnalyticsDimensionStat::query()
            ->selectRaw('COALESCE(SUM(hits), 0) as hits, COUNT(DISTINCT label_hash) as labels')
            ->where('dimension', $dimension)
            ->whereBetween('date', [$from, $to])
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->first();

        return [
            'hits' => (int) ($row->hits ?? 0),
            'labels' => (int) ($row->labels ?? 0),
        ];
    }

    /**
     * 라벨별 부가 값(`extra`)을 기간 내내 합산해 조회합니다.
     *
     * `extra` 는 JSON 이라 합산 질의의 SUM 으로 묶을 수 없다 — 드라이버마다 JSON 함수가
     * 달라 SQL 로 풀면 이식성을 잃는다. 대상 라벨이 한 쪽(최대 100건)뿐이므로 그 행들의
     * 값만 읽어 PHP 에서 키별로 더한다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $labelHashes  합산할 라벨 묶음 키
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return array<string, array<string, int>> 라벨 묶음 키별 부가 값
     */
    public function dimensionExtra(string $dimension, string $from, string $to, array $labelHashes, array $visitorTypes = []): array
    {
        if ($labelHashes === []) {
            return [];
        }

        $totals = [];

        AnalyticsDimensionStat::query()
            ->select(['label_hash', 'extra'])
            ->where('dimension', $dimension)
            ->whereBetween('date', [$from, $to])
            ->whereIn('label_hash', $labelHashes)
            ->whereNotNull('extra')
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->each(function ($row) use (&$totals): void {
                $extra = is_array($row->extra) ? $row->extra : json_decode((string) $row->extra, true);

                if (! is_array($extra)) {
                    return;
                }

                $hash = (string) $row->label_hash;

                foreach ($extra as $key => $value) {
                    $totals[$hash][$key] = ($totals[$hash][$key] ?? 0) + (int) $value;
                }
            });

        return $totals;
    }

    /**
     * 차원 라벨 하나의 날짜별 합계를 조회합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $labelHash  라벨 묶음 키 (`md5(label)`)
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $visitorTypes  포함할 방문자 유형
     * @return Collection<int, object> 날짜별 합계 행
     */
    public function dimensionSeries(string $dimension, string $labelHash, string $from, string $to, array $visitorTypes = []): Collection
    {
        return AnalyticsDimensionStat::query()
            ->selectRaw('date, SUM(visits) as visits, SUM(hits) as hits, SUM(bounces) as bounces, '
                .'SUM(total_time_seconds) as total_time_seconds, SUM(entries) as entries, SUM(exits) as exits')
            ->where('dimension', $dimension)
            ->where('label_hash', $labelHash)
            ->whereBetween('date', [$from, $to])
            ->when($visitorTypes !== [], fn (Builder $q) => $q->whereIn('visitor_type', $visitorTypes))
            ->groupBy('date')
            ->orderBy('date')
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
     * 집계 표에 행이 남아 있는 확장 차원 이름을 반환합니다.
     *
     * @return array<int, string> 차원 이름 목록 (ext:식별자:키)
     */
    public function extensionDimensionNames(): array
    {
        return AnalyticsDimensionStat::query()
            ->where('dimension', 'like', 'ext:%')
            ->distinct()
            ->orderBy('dimension')
            ->pluck('dimension')
            ->map(fn ($dimension): string => (string) $dimension)
            ->all();
    }

    /**
     * 확장이 등록한 차원의 집계 행과 그 확장 목표의 전환 집계 행을 삭제합니다.
     *
     * @param  string  $extensionIdentifier  확장 식별자
     * @return int 삭제한 행 수
     */
    public function deleteDimensionsForExtension(string $extensionIdentifier): int
    {
        return AnalyticsDimensionStat::query()
            ->where('dimension', 'like', 'ext:'.$extensionIdentifier.':%')
            ->orWhere(fn (Builder $query) => $query
                ->where('dimension', StatsDimension::Goal->value)
                ->where('label', 'like', $extensionIdentifier.'.%'))
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

    /**
     * 그 날짜의 집계가 확정됐는지 확인합니다.
     *
     * @param  string  $date  일자 (Y-m-d)
     * @return bool 확정된 행이 하나라도 있으면 true
     */
    public function isDailyFinal(string $date): bool
    {
        return AnalyticsStatsDaily::query()->where('date', $date)->where('is_final', true)->exists();
    }

    /**
     * 기준 시각보다 오래된 시간 집계 수를 셉니다.
     *
     * @param  Carbon  $before  이 시각 이전이 대상
     * @return int 해당 건수
     */
    public function countHourlyOlderThan(Carbon $before): int
    {
        return AnalyticsStatsHourly::query()
            ->where('hour_start', '<', $before->format('Y-m-d H:i:s'))
            ->count();
    }

    /**
     * 집계 표 셋을 모두 비웁니다.
     *
     * @return int 삭제한 행 수
     */
    public function deleteAllStats(): int
    {
        return AnalyticsStatsHourly::query()->delete()
            + AnalyticsStatsDaily::query()->delete()
            + AnalyticsDimensionStat::query()->delete();
    }
}
