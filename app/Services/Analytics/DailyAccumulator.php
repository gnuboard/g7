<?php

namespace App\Services\Analytics;

use App\Analytics\ActionNameHasher;

/**
 * 일 단위 집계 누적기
 *
 * 하루치 방문을 청크로 나눠 읽는 동안 지표를 메모리에 쌓습니다. 방문 하나를 한 번만
 * 보고 지나가므로, 같은 원장을 지표 수만큼 다시 읽지 않습니다.
 *
 * 순방문자는 키 집합의 크기라 합계로 구할 수 없습니다. 그래서 방문자 키를 유형별로
 * 모아 두었다가 마지막에 셉니다 — 하루치 방문자 수만큼의 문자열이 메모리에 남습니다.
 */
class DailyAccumulator
{
    /**
     * 방문자 유형별 지표
     *
     * @var array<string, array<string, mixed>>
     */
    private array $types = [];

    /**
     * 차원 × 라벨별 지표
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $dimensions = [];

    /**
     * 방문 하나의 지표를 더합니다.
     *
     * @param  string  $visitorType  방문자 유형
     * @param  string  $visitorKey  방문자 키 (순방문자 계산용)
     * @param  array<string, mixed>  $metrics  방문 지표 (pageviews, actions, time, bounce, server_only, is_returning)
     * @return void 없음
     */
    public function addVisit(string $visitorType, string $visitorKey, array $metrics): void
    {
        $bucket = &$this->typeBucket($visitorType);

        $bucket['visits']++;
        $bucket['visitor_keys'][$visitorKey] = true;
        $bucket['pageviews'] += (int) ($metrics['pageviews'] ?? 0);
        $bucket['actions'] += (int) ($metrics['actions'] ?? 0);
        $bucket['total_time_seconds'] += (int) ($metrics['total_time_seconds'] ?? 0);

        if (! empty($metrics['is_bounce'])) {
            $bucket['bounces']++;
        }

        if (! empty($metrics['server_only'])) {
            $bucket['server_only_visits']++;
        }

        // 재방문 여부는 쿠키를 쓰는 모드에서만 정해진다 — 익명 모드에서는 null 이
        // 들어오고, 그때는 신규·재방문 두 칸을 모두 비워 둔다(0 이 아니다).
        if (array_key_exists('is_returning', $metrics) && $metrics['is_returning'] !== null) {
            $bucket['has_returning_data'] = true;

            if ($metrics['is_returning']) {
                $bucket['returning_visitor_keys'][$visitorKey] = true;
            } else {
                $bucket['new_visitor_keys'][$visitorKey] = true;
            }
        }
    }

    /**
     * 차원 라벨 하나의 지표를 더합니다.
     *
     * @param  string  $dimension  차원 이름
     * @param  string  $label  라벨
     * @param  string  $visitorType  방문자 유형
     * @param  array<string, mixed>  $metrics  지표 (visit_key, hits, entry, exit, bounce, time, action_name_id, extra)
     * @return void 없음
     */
    public function addDimension(string $dimension, string $label, string $visitorType, array $metrics = []): void
    {
        if ($label === '') {
            return;
        }

        $label = mb_substr($label, 0, 512);
        $key = $visitorType.'|'.$label;

        if (! isset($this->dimensions[$dimension][$key])) {
            $this->dimensions[$dimension][$key] = [
                'visitor_type' => $visitorType,
                'label' => $label,
                'action_name_id' => $metrics['action_name_id'] ?? null,
                'visit_keys' => [],
                'hits' => 0,
                'entries' => 0,
                'exits' => 0,
                'bounces' => 0,
                'total_time_seconds' => 0,
                'extra' => [],
            ];
        }

        $row = &$this->dimensions[$dimension][$key];

        // 같은 방문이 한 라벨을 여러 번 봐도 방문 수는 1이다 — 방문 수와 조회 수는
        // 다른 질문에 답한다.
        if (isset($metrics['visit_key'])) {
            $row['visit_keys'][$metrics['visit_key']] = true;
        }

        $row['hits'] += (int) ($metrics['hits'] ?? 0);
        $row['entries'] += (int) ($metrics['entries'] ?? 0);
        $row['exits'] += (int) ($metrics['exits'] ?? 0);
        $row['bounces'] += (int) ($metrics['bounces'] ?? 0);
        $row['total_time_seconds'] += (int) ($metrics['total_time_seconds'] ?? 0);

        if ($row['action_name_id'] === null && isset($metrics['action_name_id'])) {
            $row['action_name_id'] = $metrics['action_name_id'];
        }

        foreach ((array) ($metrics['extra'] ?? []) as $extraKey => $extraValue) {
            $row['extra'][$extraKey] = ($row['extra'][$extraKey] ?? 0) + (int) $extraValue;
        }
    }

    /**
     * 일 집계 표에 넣을 행을 만듭니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d)
     * @param  bool  $isFinal  확정 여부
     * @param  string  $archivedAt  집계 수행 시각 (Y-m-d H:i:s)
     * @return array<int, array<string, mixed>> 일 집계 행 목록
     */
    public function dailyRows(string $date, bool $isFinal, string $archivedAt): array
    {
        $rows = [];

        foreach ($this->types as $visitorType => $bucket) {
            $rows[] = [
                'date' => $date,
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
                'is_final' => $isFinal,
                'archived_at' => $archivedAt,
            ];
        }

        return $rows;
    }

    /**
     * 차원 집계 표에 넣을 행을 만듭니다.
     *
     * 확장 차원은 선언한 라벨 수 상한(`$caps`)을 넘으면 방문 수가 많은 라벨부터 그 수만큼만
     * 남깁니다(원장은 그대로). 상한 없이 두면 값의 종류가 많은 차원(회원 번호 등) 하나가 하루
     * 차원 표를 수십만 행으로 부풀립니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d)
     * @param  string  $archivedAt  집계 수행 시각 (Y-m-d H:i:s)
     * @param  array<string, int>  $caps  차원 이름 → 라벨 수 상한
     * @return array<int, array<string, mixed>> 차원 집계 행 목록
     */
    public function dimensionRows(string $date, string $archivedAt, array $caps = []): array
    {
        $rows = [];

        foreach ($this->dimensions as $dimension => $labels) {
            if (isset($caps[$dimension])) {
                $labels = $this->keepTopLabels($labels, max(1, $caps[$dimension]));
            }

            foreach ($labels as $row) {
                $rows[] = [
                    'date' => $date,
                    'visitor_type' => $row['visitor_type'],
                    'dimension' => $dimension,
                    'label_hash' => ActionNameHasher::labelHash($row['label']),
                    'label' => $row['label'],
                    'action_name_id' => $row['action_name_id'],
                    'visits' => count($row['visit_keys']),
                    'hits' => $row['hits'],
                    'entries' => $row['entries'],
                    'exits' => $row['exits'],
                    'bounces' => $row['bounces'],
                    'total_time_seconds' => $row['total_time_seconds'],
                    'extra' => $row['extra'] === [] ? null : json_encode($row['extra'], JSON_UNESCAPED_UNICODE),
                    'archived_at' => $archivedAt,
                ];
            }
        }

        return $rows;
    }

    /**
     * 방문 수가 많은 라벨만 상한 수만큼 남깁니다.
     *
     * 방문 수가 같으면 라벨 순으로 고릅니다 — 재집계할 때마다 남는 라벨이 바뀌면 안 됩니다.
     *
     * @param  array<string, array<string, mixed>>  $rows  한 차원의 행 (유형|라벨 → 행)
     * @param  int  $limit  남길 라벨 수
     * @return array<string, array<string, mixed>> 남은 행
     */
    private function keepTopLabels(array $rows, int $limit): array
    {
        $visitsByLabel = [];

        foreach ($rows as $row) {
            $visitsByLabel[$row['label']] = ($visitsByLabel[$row['label']] ?? 0) + count($row['visit_keys']);
        }

        if (count($visitsByLabel) <= $limit) {
            return $rows;
        }

        // 방문 수가 같으면 라벨 순으로 — 재집계할 때마다 남는 라벨이 바뀌면 안 된다.
        uksort($visitsByLabel, fn ($a, $b) => [$visitsByLabel[$b], (string) $a] <=> [$visitsByLabel[$a], (string) $b]);
        $kept = array_flip(array_slice(array_map('strval', array_keys($visitsByLabel)), 0, $limit));

        return array_filter($rows, fn (array $row): bool => isset($kept[(string) $row['label']]));
    }

    /**
     * 유형별 지표 칸을 꺼냅니다 (없으면 만듭니다).
     *
     * @param  string  $visitorType  방문자 유형
     * @return array<string, mixed> 지표 칸 참조
     */
    private function &typeBucket(string $visitorType): array
    {
        if (! isset($this->types[$visitorType])) {
            $this->types[$visitorType] = [
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

        return $this->types[$visitorType];
    }
}
