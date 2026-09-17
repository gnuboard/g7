<?php

namespace App\Services\Analytics;

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
     * 시간 집계를 언제부터 볼 수 있는지 돌려줍니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return Carbon 조회 가능한 가장 이른 시각
     */
    public function hourlyAvailableFrom(AnalyticsConfig $config): Carbon
    {
        return Carbon::now()->subDays(max(1, $config->hourlyRetentionDays))->startOfDay();
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

        return $this->aggregate($rows, fn ($row): string => Carbon::parse($row->hour_start)->format('Y-m-d H:00:00'));
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

        return $this->aggregate($rows, fn ($row): string => match ($unit) {
            'week' => Carbon::parse($row->date)->startOfWeek()->toDateString(),
            'month' => Carbon::parse($row->date)->format('Y-m-01'),
            'year' => Carbon::parse($row->date)->format('Y-01-01'),
            default => Carbon::parse($row->date)->toDateString(),
        });
    }

    /**
     * 행을 시각별로 묶어 지표를 더합니다.
     *
     * @param  Collection<int, object>  $rows  집계 행
     * @param  callable  $bucket  행에서 시각 키를 만드는 함수
     * @return array<int, array<string, mixed>> 점 목록
     */
    private function aggregate($rows, callable $bucket): array
    {
        $points = [];

        foreach ($rows as $row) {
            $key = $bucket($row);

            if (! isset($points[$key])) {
                $points[$key] = [
                    'ts' => $key,
                    'visits' => 0,
                    'visitors' => 0,
                    'pageviews' => 0,
                    'bounces' => 0,
                    'total_time_seconds' => 0,
                ];
            }

            $points[$key]['visits'] += (int) $row->visits;
            // 방문자 키는 날마다 바뀔 수 있어 여러 날을 아우르는 진짜 순방문자는 셀 수
            // 없다 — 일별 순방문자의 합으로 정의하고 화면에도 그렇게 적는다.
            $points[$key]['visitors'] += (int) $row->visitors;
            $points[$key]['pageviews'] += (int) $row->pageviews;
            $points[$key]['bounces'] += (int) $row->bounces;
            $points[$key]['total_time_seconds'] += (int) $row->total_time_seconds;
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
     * 사람 방문자 유형 목록을 돌려줍니다.
     *
     * @return array<int, string> 유형 목록 (봇 제외)
     */
    private function humanTypes(): array
    {
        return [VisitorType::Guest->value, VisitorType::Member->value, VisitorType::Admin->value];
    }
}
