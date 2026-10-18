<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\StatsRepositoryInterface;
use App\Enums\Analytics\VisitorType;
use Illuminate\Support\Carbon;

/**
 * 요일·시간대 이용 패턴
 *
 * 「우리 사이트는 언제 붐비는가」에 답합니다. 별도 집계 행을 두지 않고 이미 있는 일·
 * 시간 집계를 묶어 만듭니다 — 저장 행이 늘지 않습니다.
 *
 * 합이 아니라 평균을 냅니다. 기간에 월요일이 다섯 번, 화요일이 네 번 들어 있으면
 * 합계만으로는 월요일이 더 붐비는 것처럼 보입니다.
 */
class PatternService
{
    /**
     * 패턴 조회기를 생성합니다.
     *
     * @param  StatsRepositoryInterface  $stats  집계 저장소
     * @param  SeriesService  $series  시계열 서비스 (시간 집계 가용 구간 판정)
     */
    public function __construct(
        private readonly StatsRepositoryInterface $stats,
        private readonly SeriesService $series,
    ) {}

    /**
     * 기간의 요일·시간대 패턴을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  AnalyticsConfig  $config  설정
     * @param  array<string, mixed>  $options  조회 옵션 (visitor_types)
     * @return array{weekday: array<int, array<string, mixed>>, hour: array<int, array<string, mixed>>, hourly_available_from: string}
     *                                                                                                                                 패턴
     */
    public function patterns(string $from, string $to, AnalyticsConfig $config, array $options = []): array
    {
        $types = $options['visitor_types'] ?? $this->humanTypes();
        $availableFrom = $this->series->hourlyAvailableFrom($config);

        // 시간 집계는 보존기간이 지나면 지워진다 — 그보다 이른 구간을 물으면 그 앞은
        // 재료가 없으므로 가용 시작점부터만 읽는다.
        $hourFrom = Carbon::parse($from)->greaterThan($availableFrom)
            ? Carbon::parse($from)
            : $availableFrom;

        return [
            'weekday' => $this->weekday($from, $to, $types),
            'hour' => $this->hour($hourFrom->toDateString(), $to, $types),
            'hourly_available_from' => $availableFrom->toDateString(),
        ];
    }

    /**
     * 요일별 평균을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @return array<int, array<string, mixed>> 요일 0(일)~6(토) 일곱 칸
     */
    private function weekday(string $from, string $to, array $types): array
    {
        $buckets = $this->emptyBuckets(7);
        $seenDates = [];

        foreach ($this->stats->daily($from, $to, $types) as $row) {
            $date = Carbon::parse($row->date);
            $index = (int) $date->dayOfWeek;

            $buckets[$index]['visits'] += (int) $row->visits;
            $buckets[$index]['visitors'] += (int) $row->visitors;
            $buckets[$index]['pageviews'] += (int) $row->pageviews;

            // 같은 날에 유형별 행이 여럿이므로, 날짜를 한 번만 센다.
            $seenDates[$index][$date->toDateString()] = true;
        }

        foreach ($buckets as $index => $bucket) {
            $buckets[$index] = $this->averaged($bucket, count($seenDates[$index] ?? []), ['dow' => $index]);
        }

        return array_values($buckets);
    }

    /**
     * 시간대별 평균을 만듭니다.
     *
     * @param  string  $from  시작일 (Y-m-d)
     * @param  string  $to  종료일 (Y-m-d)
     * @param  array<int, string>  $types  포함할 방문자 유형
     * @return array<int, array<string, mixed>> 0~23시 스물네 칸
     */
    private function hour(string $from, string $to, array $types): array
    {
        $buckets = $this->emptyBuckets(24);
        $seenSlots = [];

        foreach ($this->stats->hourly($from.' 00:00:00', $to.' 23:59:59', $types) as $row) {
            $moment = Carbon::parse($row->hour_start);
            $index = (int) $moment->hour;

            $buckets[$index]['visits'] += (int) $row->visits;
            $buckets[$index]['visitors'] += (int) $row->visitors;
            $buckets[$index]['pageviews'] += (int) $row->pageviews;

            $seenSlots[$index][$moment->toDateString()] = true;
        }

        foreach ($buckets as $index => $bucket) {
            $buckets[$index] = $this->averaged($bucket, count($seenSlots[$index] ?? []), ['hour' => $index]);
        }

        return array_values($buckets);
    }

    /**
     * 빈 칸 배열을 만듭니다.
     *
     * @param  int  $count  칸 수
     * @return array<int, array<string, int>> 빈 칸 배열
     */
    private function emptyBuckets(int $count): array
    {
        return array_fill(0, $count, ['visits' => 0, 'visitors' => 0, 'pageviews' => 0]);
    }

    /**
     * 칸 하나를 등장 횟수로 나눠 평균을 냅니다.
     *
     * @param  array<string, int>  $bucket  누적값
     * @param  int  $days  그 칸이 기간에 몇 번 들어 있었는지
     * @param  array<string, int>  $extra  칸을 가리키는 값 (요일·시각)
     * @return array<string, mixed> 평균이 담긴 칸
     */
    private function averaged(array $bucket, int $days, array $extra): array
    {
        $divisor = max(1, $days);

        return array_merge($extra, [
            'days' => $days,
            'visits' => round($bucket['visits'] / $divisor, 1),
            'visitors' => round($bucket['visitors'] / $divisor, 1),
            'pageviews' => round($bucket['pageviews'] / $divisor, 1),
        ]);
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
