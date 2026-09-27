<?php

namespace App\Services\Analytics;

/**
 * 시간 단위 집계 누적기
 *
 * 하루치 원장을 한 번 훑는 동안 시간 × 방문자 유형 칸을 함께 채웁니다. 일 집계와
 * 같은 순회에서 만들어야 원장을 두 번 읽지 않습니다.
 *
 * 시각은 사이트 표준시의 시(hour)를 그대로 씁니다 — 시간대별 이용 패턴을 시로 묶을
 * 때 UTC 로 저장해 두면 사이트 기준과 어긋납니다.
 */
class HourlyAccumulator
{
    /**
     * 시각 × 방문자 유형별 지표
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $hours = [];

    /**
     * 방문 하나를 그 방문이 시작한 시각에 더합니다.
     *
     * 방문 수는 방문이 시작한 한 시간에만 셉니다 — 자정을 넘긴 방문을 두 시간에
     * 걸쳐 세면 방문 수 합이 실제 방문보다 커집니다.
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  string  $visitorType  방문자 유형
     * @param  array<string, mixed>  $metrics  방문 지표 (is_bounce, server_only, is_returning, visitor_key)
     * @return void 없음
     */
    public function addVisitStart(string $hourStart, string $visitorType, array $metrics = []): void
    {
        $bucket = &$this->bucket($hourStart, $visitorType);

        $bucket['visits']++;

        if (! empty($metrics['is_bounce'])) {
            $bucket['bounces']++;
        }

        if (! empty($metrics['server_only'])) {
            $bucket['server_only_visits']++;
        }

        if (array_key_exists('is_returning', $metrics) && $metrics['is_returning'] !== null) {
            $bucket['has_returning_data'] = true;

            if ($metrics['is_returning']) {
                $bucket['returning_visitor_keys'][$metrics['visitor_key'] ?? ''] = true;
            } else {
                $bucket['new_visitor_keys'][$metrics['visitor_key'] ?? ''] = true;
            }
        }
    }

    /**
     * 액션 하나를 그 액션이 일어난 시각에 더합니다.
     *
     * 순방문자는 그 시간에 움직인 사람 수라, 방문 시작이 아니라 액션 기준으로
     * 셉니다.
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  string  $visitorType  방문자 유형
     * @param  string  $visitorKey  방문자 키
     * @param  bool  $isPageview  페이지뷰 여부
     * @param  int  $timeSpentSeconds  이 액션에 머문 시간 (초)
     * @return void 없음
     */
    public function addAction(
        string $hourStart,
        string $visitorType,
        string $visitorKey,
        bool $isPageview,
        int $timeSpentSeconds = 0,
    ): void {
        $bucket = &$this->bucket($hourStart, $visitorType);

        $bucket['actions']++;
        $bucket['visitor_keys'][$visitorKey] = true;
        $bucket['total_time_seconds'] += $timeSpentSeconds;

        if ($isPageview) {
            $bucket['pageviews']++;
        }
    }

    /**
     * 집계에 쓰인 시각 목록을 반환합니다.
     *
     * @return array<int, string> 시각 목록 (Y-m-d H:00:00)
     */
    public function hourStarts(): array
    {
        return array_keys($this->hours);
    }

    /**
     * 한 시각의 집계 행을 만듭니다.
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  string  $archivedAt  집계 수행 시각 (Y-m-d H:i:s)
     * @return array<int, array<string, mixed>> 시간 집계 행 목록
     */
    public function hourlyRows(string $hourStart, string $archivedAt): array
    {
        $rows = [];

        foreach ($this->hours[$hourStart] ?? [] as $visitorType => $bucket) {
            $rows[] = [
                'hour_start' => $hourStart,
                'visitor_type' => $visitorType,
                'visits' => $bucket['visits'],
                'visitors' => count($bucket['visitor_keys']),
                'pageviews' => $bucket['pageviews'],
                'actions' => $bucket['actions'],
                'bounces' => $bucket['bounces'],
                'server_only_visits' => $bucket['server_only_visits'],
                'new_visitors' => $bucket['has_returning_data'] ? count($bucket['new_visitor_keys']) : null,
                'returning_visitors' => $bucket['has_returning_data'] ? count($bucket['returning_visitor_keys']) : null,
                'total_time_seconds' => $bucket['total_time_seconds'],
                'archived_at' => $archivedAt,
            ];
        }

        return $rows;
    }

    /**
     * 시각 × 유형 칸을 꺼냅니다 (없으면 만듭니다).
     *
     * @param  string  $hourStart  사이트 표준시 기준 시각 (Y-m-d H:00:00)
     * @param  string  $visitorType  방문자 유형
     * @return array<string, mixed> 지표 칸 참조
     */
    private function &bucket(string $hourStart, string $visitorType): array
    {
        if (! isset($this->hours[$hourStart][$visitorType])) {
            $this->hours[$hourStart][$visitorType] = [
                'visits' => 0,
                'visitor_keys' => [],
                'pageviews' => 0,
                'actions' => 0,
                'bounces' => 0,
                'server_only_visits' => 0,
                'total_time_seconds' => 0,
                'new_visitor_keys' => [],
                'returning_visitor_keys' => [],
                'has_returning_data' => false,
            ];
        }

        return $this->hours[$hourStart][$visitorType];
    }
}
