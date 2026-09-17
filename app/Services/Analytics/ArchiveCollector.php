<?php

namespace App\Services\Analytics;

use App\Analytics\ActionNameHasher;

/**
 * 확장 집계 수집기
 *
 * 집계 배치가 하루치 원장을 다 읽은 뒤, 확장이 자기 기준으로 만든 차원 행을 받아
 * 둡니다. 배치는 이 수집기가 모아 둔 행을 자기 행과 함께 한 트랜잭션으로 넣습니다.
 *
 * 확장이 집계 표에 직접 쓰지 않고 이 수집기를 거치는 이유는, 집계가 「지우고 넣기」라
 * 중간에 확장이 따로 써 넣은 행은 배치가 지워 버리기 때문입니다.
 */
class ArchiveCollector
{
    /**
     * 모인 차원 행 목록
     *
     * @var array<int, array<string, mixed>>
     */
    private array $rows = [];

    /**
     * 집계 대상 일자를 지닌 수집기를 만듭니다.
     *
     * @param  string  $date  집계 일자 (Y-m-d, 사이트 표준시 기준)
     */
    public function __construct(
        public readonly string $date,
    ) {}

    /**
     * 확장 차원 행을 하나 더합니다.
     *
     * 차원 이름은 ext:{확장 식별자}:{키} 형태여야 합니다 — 코어 차원과 섞이면
     * 확장을 지울 때 코어 집계까지 함께 사라집니다.
     *
     * @param  string  $dimension  차원 이름 (ext:식별자:키)
     * @param  string  $label  라벨
     * @param  string  $visitorType  방문자 유형
     * @param  array<string, mixed>  $metrics  지표 값 (visits, hits, entries, exits, bounces, total_time_seconds, extra)
     * @return void 없음
     */
    public function addDimensionRow(string $dimension, string $label, string $visitorType, array $metrics = []): void
    {
        if (! str_starts_with($dimension, 'ext:')) {
            return;
        }

        $this->rows[] = [
            'date' => $this->date,
            'visitor_type' => $visitorType,
            'dimension' => $dimension,
            'label_hash' => ActionNameHasher::labelHash($label),
            'label' => mb_substr($label, 0, 512),
            'action_name_id' => $metrics['action_name_id'] ?? null,
            'visits' => (int) ($metrics['visits'] ?? 0),
            'hits' => (int) ($metrics['hits'] ?? 0),
            'entries' => (int) ($metrics['entries'] ?? 0),
            'exits' => (int) ($metrics['exits'] ?? 0),
            'bounces' => (int) ($metrics['bounces'] ?? 0),
            'total_time_seconds' => (int) ($metrics['total_time_seconds'] ?? 0),
            'extra' => isset($metrics['extra']) ? json_encode($metrics['extra'], JSON_UNESCAPED_UNICODE) : null,
        ];
    }

    /**
     * 모인 행을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 차원 집계 행 목록
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * 모인 행이 있는지 확인합니다.
     *
     * @return bool 행이 하나라도 있으면 true
     */
    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
