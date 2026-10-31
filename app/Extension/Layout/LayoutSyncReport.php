<?php

namespace App\Extension\Layout;

use App\Enums\LayoutSyncMode;
use App\Extension\Layout\DTO\LayoutSyncEntry;

/**
 * 한 번의 확장 레이아웃 동기화(업데이트·활성화)에서 나온 행별 결과를 모읍니다.
 *
 * 알림 요약·활동 로그·CLI 표가 같은 숫자를 쓰도록 집계를 여기 한 곳에서 한다.
 */
final class LayoutSyncReport
{
    /** @var list<LayoutSyncEntry> */
    private array $entries = [];

    /**
     * @param  LayoutSyncMode  $strategy  레이아웃 처리 방식 (배열로 낼 때 `keep`/`overwrite` 문자열)
     */
    public function __construct(private LayoutSyncMode $strategy = LayoutSyncMode::Keep) {}

    /** @var array<string, int> 행 키 => entries 위치 */
    private array $index = [];

    /**
     * 결과 한 건을 더합니다 (null 은 무시).
     *
     * 한 업데이트 안에서 같은 행을 두 경로가 지날 수 있다(모듈 업데이트의 조각 사전 재등록 → keep 갱신). 먼저 지난
     * 경로가 병합을 끝내면 뒤 경로는 "운영자 것 유지(변경 없음)" 만 보므로, 행마다 **처음 나온 의미 있는 결과**를 남긴다.
     *
     * @param  LayoutSyncEntry|null  $entry  결과
     * @return void 반환값 없음
     */
    public function add(?LayoutSyncEntry $entry): void
    {
        if ($entry === null) {
            return;
        }

        $key = $entry->kind.'|'.$entry->template.'|'.$entry->name;

        if (! isset($this->index[$key])) {
            $this->index[$key] = count($this->entries);
            $this->entries[] = $entry;

            return;
        }

        $previous = $this->entries[$this->index[$key]];
        $informative = fn (LayoutSyncEntry $e) => $e->isReported() && ($e->verdict !== 'kept' || $e->applied !== [] || $e->conflicts !== []);

        if (! $informative($previous) && $informative($entry)) {
            $this->entries[$this->index[$key]] = $entry;
        }
    }

    /**
     * 모든 결과를 돌려줍니다.
     *
     * @return list<LayoutSyncEntry> 결과 목록
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * 판정별 개수를 셉니다.
     *
     * @param  string  $verdict  판정
     * @return int 개수
     */
    public function count(string $verdict): int
    {
        return count(array_filter($this->entries, fn (LayoutSyncEntry $e) => $e->verdict === $verdict));
    }

    /**
     * 요약·보고용 배열 — `entries` 에는 운영자 관련 행만 싣는다.
     *
     * @return array{strategy: string, applied_count: int, conflict_count: int, kept_no_base_count: int, kept_removed_count: int, kept_deleted_by_operator_count: int, kept_name_collision_count: int, kept_invalid_count: int, modified_count: int, entries: list<array<string, mixed>>} 요약
     */
    public function toArray(): array
    {
        $reported = array_values(array_filter($this->entries, fn (LayoutSyncEntry $e) => $e->isReported()));

        return [
            'strategy' => $this->strategy->value,
            'applied_count' => array_sum(array_map(fn (LayoutSyncEntry $e) => count($e->applied), $reported)),
            'conflict_count' => array_sum(array_map(fn (LayoutSyncEntry $e) => count($e->conflicts), $reported)),
            'kept_no_base_count' => $this->count('kept_no_base'),
            'kept_removed_count' => $this->count('kept_removed'),
            'kept_deleted_by_operator_count' => $this->count('kept_deleted_by_operator'),
            'kept_name_collision_count' => $this->count('kept_name_collision'),
            'kept_invalid_count' => $this->count('kept_invalid'),
            'modified_count' => count($reported),
            'entries' => array_map(fn (LayoutSyncEntry $e) => $e->toArray(), $reported),
        ];
    }
}
