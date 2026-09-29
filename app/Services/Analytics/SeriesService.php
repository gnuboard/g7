<?php

namespace App\Services\Analytics;

use App\Analytics\ActionNameHasher;
use App\Analytics\AnalyticsClock;
use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\VisitorType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 추이 시계열
 *
 * 기간을 받아 차트가 그릴 점 목록을 만듭니다. 구간 길이에 따라 단위를 스스로 고릅니다 —
 * 1년치를 시간 단위로 그리면 점이 팔천 개가 되어 브라우저가 멈춥니다.
 *
 * 시간 단위는 그 집계의 보존기간 안에서만 됩니다. 밖이면 일 단위로 낮추고 그 사실을
 * 응답에 적어 화면이 안내할 수 있게 합니다.
 */
class SeriesService
{
    /**
     * 자동 단위 선택 경계 (일)
     */
    private const AUTO_HOUR_MAX_DAYS = 2;

    /**
     * @see self::AUTO_HOUR_MAX_DAYS
     */
    private const AUTO_DAY_MAX_DAYS = 92;

    /**
     * @see self::AUTO_HOUR_MAX_DAYS
     */
    private const AUTO_WEEK_MAX_DAYS = 730;

    /**
     * 시계열 조회기를 생성합니다.
     *
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     */
    public function __construct(
        private readonly StatsRepositoryInterface $stats,
    ) {}

    /**
     * 기간의 시계열을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  AnalyticsConfig  $config  설정
     * @param  array<string, mixed>  $options  조회 옵션 (unit, visitor_types, metrics)
     * @return array{unit: string, unit_downgraded: bool, points: array<int, array<string, mixed>>} 시계열
     */
    public function series(string $from, string $to, AnalyticsConfig $config, array $options = []): array
    {
        $types = $options['visitor_types'] ?? $this->humanTypes();
        $requested = (string) ($options['unit'] ?? 'auto');
        $unit = $requested === 'auto' ? $this->autoUnit($from, $to) : $requested;

        $downgraded = false;

        // 시간 집계는 보존기간이 지나면 지워진다. 그 밖을 시간 단위로 달라고 하면 빈
        // 차트가 나가므로, 일 단위로 낮추고 낮췄다는 사실을 함께 보낸다.
        if ($unit === 'hour' && ! $this->hourlyAvailableFrom($config)->lessThanOrEqualTo(Carbon::parse($from))) {
            $unit = 'day';
            $downgraded = true;
        }

        $points = $unit === 'hour'
            ? $this->hourlyPoints($from, $to, $types)
            : $this->dailyPoints($from, $to, $types, $unit);

        return [
            'unit' => $unit,
            'unit_downgraded' => $downgraded,
            'points' => $points,
        ];
    }

    /**
     * 차원 라벨 하나의 추이를 만듭니다.
     *
     * 차원 집계는 일 단위뿐이라 시간 단위를 달라고 하면 일 단위로 낮추고 그 사실을 함께 보낸다.
     * 자동 단위는 92일까지 일, 그 뒤는 주다(기간 상한이 366일이라 월은 생기지 않는다).
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $label  저장 라벨
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<string, mixed>  $options  조회 옵션 (unit, visitor_types)
     * @return array{unit: string, unit_downgraded: bool, points: array<int, array<string, mixed>>} 시계열
     */
    public function dimensionSeries(string $dimension, string $label, string $from, string $to, array $options = []): array
    {
        $types = $options['visitor_types'] ?? $this->humanTypes();
        $requested = (string) ($options['unit'] ?? 'auto');
        $downgraded = false;

        if ($requested === 'hour') {
            $unit = 'day';
            $downgraded = true;
        } elseif ($requested === 'auto' || $requested === '') {
            $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
            $unit = $days <= self::AUTO_DAY_MAX_DAYS ? 'day' : 'week';
        } else {
            $unit = $requested;
        }

        $rows = $this->stats->dimensionSeries($dimension, ActionNameHasher::labelHash($label), $from, $to, $types);

        return [
            'unit' => $unit,
            'unit_downgraded' => $downgraded,
            'points' => $this->dimensionPoints($rows, $from, $to, $unit),
        ];
    }

    /**
     * 차원 합계 행을 칸별로 묶어 점 목록을 만듭니다.
     *
     * 기록이 없는 칸은 0 으로 채우고, 기간 전체에 기록이 없으면 빈 목록을 돌려준다(추이 시계열과 같은 규약).
     *
     * @param  Collection<int, object>  $rows  날짜별 합계 행
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  string  $unit  단위 (day, week, month, year)
     * @return array<int, array<string, mixed>> 점 목록
     */
    private function dimensionPoints(Collection $rows, string $from, string $to, string $unit): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $bucketOf = $this->bucketOf($unit);
        $empty = static fn (string $key): array => [
            'ts' => $key,
            'visits' => 0,
            'hits' => 0,
            'bounces' => 0,
            'total_time_seconds' => 0,
        ];

        $points = [];

        foreach ($this->dayKeys($from, $to, $bucketOf) as $key) {
            $points[$key] = $empty($key);
        }

        foreach ($rows as $row) {
            $key = $bucketOf(Carbon::parse($row->date));
            $points[$key] ??= $empty($key);
            $points[$key]['visits'] += (int) $row->visits;
            $points[$key]['hits'] += (int) $row->hits;
            $points[$key]['bounces'] += (int) $row->bounces;
            $points[$key]['total_time_seconds'] += (int) $row->total_time_seconds;
        }

        ksort($points);

        return array_values(array_map(static function (array $point): array {
            $visits = $point['visits'];
            $point['bounce_rate'] = $visits > 0 ? round($point['bounces'] / $visits * 100, 1) : 0.0;
            $point['avg_time'] = $visits > 0 ? (int) round($point['total_time_seconds'] / $visits) : 0;

            return $point;
        }, $points));
    }

    /**
     * 단위에 맞는 칸 키 함수를 만듭니다.
     *
     * @param  string  $unit  단위 (day, week, month, year)
     * @return callable(Carbon): string 날짜에서 칸 키를 만드는 함수
     */
    private function bucketOf(string $unit): callable
    {
        return fn (Carbon $date): string => match ($unit) {
            'week' => $date->copy()->startOfWeek()->toDateString(),
            'month' => $date->format('Y-m-01'),
            'year' => $date->format('Y-01-01'),
            default => $date->toDateString(),
        };
    }

    /**
     * 시간 집계를 언제부터 볼 수 있는지 돌려줍니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return Carbon 조회 가능한 가장 이른 시각
     */
    public function hourlyAvailableFrom(AnalyticsConfig $config): Carbon
    {
        // 경계는 **사이트 표준시 일자**로 센다 — 보존기간은 사이트 자정으로 끊기고 화면도 그 일자를
        // 그린다(#682 S3 라운드 7). `Carbon::now()` 는 `config('app.timezone')`(UTC)을 따르므로
        // UTC 보다 앞선 사이트에서는 새벽 시간대에 하루 앞 날짜가 나와, 안내 문구와 단위 강등
        // 분기가 함께 어긋났다.
        //
        // 일자를 정한 뒤 다시 파싱해 **UTC 자정**으로 돌려준다 — 호출부 두 곳이 일(日) 단위로
        // 비교하므로(`$from` 도 날짜 문자열을 파싱한다) 양쪽이 같은 기준이어야 한다.
        $boundary = Carbon::now(AnalyticsClock::siteTimezone())
            ->subDays(max(1, $config->hourlyRetentionDays))
            ->toDateString();

        return Carbon::parse($boundary);
    }

    /**
     * 구간 길이로 단위를 고릅니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @return string 단위 (hour, day, week, month)
     */
    private function autoUnit(string $from, string $to): string
    {
        $days = (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        return match (true) {
            $days <= self::AUTO_HOUR_MAX_DAYS => 'hour',
            $days <= self::AUTO_DAY_MAX_DAYS => 'day',
            $days <= self::AUTO_WEEK_MAX_DAYS => 'week',
            default => 'month',
        };
    }

    /**
     * 시간 단위 점 목록을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @return array<int, array<string, mixed>> 점 목록
     */
    private function hourlyPoints(string $from, string $to, array $types): array
    {
        $rows = $this->stats->hourly($from.' 00:00:00', $to.' 23:59:59', $types);

        return $this->aggregate(
            $rows,
            fn ($row): string => Carbon::parse($row->hour_start)->format('Y-m-d H:00:00'),
            $this->hourKeys($from, $to),
        );
    }

    /**
     * 일·주·월 단위 점 목록을 만듭니다.
     *
     * 주·월은 따로 집계하지 않고 일 집계를 묶어 만듭니다 — 저장 행이 늘지 않습니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @param  string  $unit  단위 (day, week, month, year)
     * @return array<int, array<string, mixed>> 점 목록
     */
    private function dailyPoints(string $from, string $to, array $types, string $unit): array
    {
        $rows = $this->stats->daily($from, $to, $types);

        $bucketOf = $this->bucketOf($unit);

        return $this->aggregate(
            $rows,
            fn ($row): string => $bucketOf(Carbon::parse($row->date)),
            $this->dayKeys($from, $to, $bucketOf),
        );
    }

    /**
     * 시간 단위 칸 키를 만듭니다.
     *
     * 시간 집계의 시각은 사이트 표준시 벽시계라 칸도 같은 기준으로 셉니다. 지금 시간
     * 뒤의 칸은 만들지 않습니다 — 0 으로 미리 그리면 방문이 끊긴 것처럼 보입니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @return array<int, string> 칸 키 목록 (Y-m-d H:00:00)
     */
    private function hourKeys(string $from, string $to): array
    {
        $timezone = AnalyticsClock::siteTimezone();
        $end = Carbon::parse($to.' 23:00:00', $timezone);
        $current = Carbon::now($timezone)->startOfHour();

        if ($current->lessThan($end)) {
            $end = $current;
        }

        $keys = [];

        for ($hour = Carbon::parse($from.' 00:00:00', $timezone); $hour->lessThanOrEqualTo($end); $hour->addHour()) {
            $keys[] = $hour->format('Y-m-d H:00:00');
        }

        return $keys;
    }

    /**
     * 일·주·월·연 단위 칸 키를 만듭니다.
     *
     * 기간의 하루하루를 행과 같은 규칙으로 칸에 넣어 셉니다 — 주의 시작 요일 같은 규칙이
     * 행 쪽과 어긋날 수 없습니다. 오늘 뒤의 날은 넣지 않습니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  callable(Carbon): string  $bucketOf  날짜에서 칸 키를 만드는 함수
     * @return array<int, string> 칸 키 목록
     */
    private function dayKeys(string $from, string $to, callable $bucketOf): array
    {
        $end = Carbon::parse($to);
        $today = Carbon::parse(AnalyticsClock::today());

        if ($today->lessThan($end)) {
            $end = $today;
        }

        $keys = [];

        for ($day = Carbon::parse($from); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $keys[$bucketOf($day)] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * 행을 칸별로 묶어 지표를 더합니다.
     *
     * 기록이 없는 칸은 0 으로 채웁니다 — 건너뛰면 차트가 앞뒤 칸을 곧장 이어 그려 추이가
     * 거짓이 됩니다. 다만 기간 전체에 기록이 하나도 없으면 빈 목록을 돌려줍니다. 평평한
     * 0 선은 화면의 「선택 기간에 기록이 없습니다」 안내를 가립니다.
     *
     * @param  Collection<int, object>  $rows  집계 행
     * @param  callable  $bucket  행에서 칸 키를 만드는 함수
     * @param  array<int, string>  $keys  기간의 칸 키 목록 (지금 뒤는 제외)
     * @return array<int, array<string, mixed>> 점 목록
     */
    private function aggregate(Collection $rows, callable $bucket, array $keys): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $points = [];

        foreach ($keys as $key) {
            $points[$key] = $this->emptyPoint($key);
        }

        foreach ($rows as $row) {
            $key = $bucket($row);
            $points[$key] ??= $this->emptyPoint($key);

            $points[$key]['visits'] += (int) $row->visits;
            // 방문자 키는 날마다 바뀔 수 있어 여러 날을 아우르는 진짜 순방문자는 셀 수
            // 없다 — 일별 순방문자의 합으로 정의하고 화면에도 그렇게 적는다.
            $points[$key]['visitors'] += (int) $row->visitors;
            $points[$key]['pageviews'] += (int) $row->pageviews;
            $points[$key]['bounces'] += (int) $row->bounces;
            $points[$key]['total_time_seconds'] += (int) $row->total_time_seconds;

            // 신규·재방문은 쿠키를 쓰는 모드에서만 센다 — 익명 모드 행은 null 이다. 칸의 행이 모두 null 이면
            // 칸도 null(모름)로 두고, 하나라도 값이 있으면 값이 있는 행만 더한다.
            foreach (['new_visitors', 'returning_visitors'] as $field) {
                if ($row->{$field} !== null) {
                    $points[$key][$field] = ($points[$key][$field] ?? 0) + (int) $row->{$field};
                }
            }
        }

        ksort($points);

        return array_values(array_map(function (array $point): array {
            $visits = $point['visits'];

            $point['bounce_rate'] = $visits > 0 ? round($point['bounces'] / $visits * 100, 1) : 0.0;
            $point['avg_time'] = $visits > 0 ? (int) round($point['total_time_seconds'] / $visits) : 0;
            $point['pages_per_visit'] = $visits > 0 ? round($point['pageviews'] / $visits, 2) : 0.0;

            return $point;
        }, $points));
    }

    /**
     * 기록이 없는 칸 하나를 만듭니다.
     *
     * @param  string  $key  칸 키
     * @return array<string, mixed> 0 으로 채운 점
     */
    private function emptyPoint(string $key): array
    {
        return [
            'ts' => $key,
            'visits' => 0,
            'visitors' => 0,
            'pageviews' => 0,
            'bounces' => 0,
            'total_time_seconds' => 0,
            'new_visitors' => null,
            'returning_visitors' => null,
        ];
    }

    /**
     * 사람 방문자 유형 목록을 돌려줍니다.
     *
     * @return array<int, string> 유형 목록 (봇 제외)
     */
    private function humanTypes(): array
    {
        return [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value];
    }
}
