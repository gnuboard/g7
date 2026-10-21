<?php

namespace App\Services\Core;

/**
 * 코어 업데이트가 이번에 손댈 번들(배치 집합 P)과 건너뛸 번들(미배치 집합 U)의 판정 결과입니다.
 *
 * placed 항목의 reason:
 *  - installed: DB 에 설치 행이 있음
 *  - on_disk: 설치되진 않았지만 `_bundled/{id}` 가 디스크에 있음
 *  - dependency: P 에 든 확장(또는 운영자 선택)의 새 판 manifest 가 요구하는 번들
 *  - selected: 운영자가 배치하기로 고름
 *  - core: scope=core 언어팩 (항상 배치)
 *  - target_placed: 대상 확장이 배치·설치돼 함께 배치하는 확장 언어팩
 */
final class BundledPlacementPlan
{
    /**
     * @param  array<string, array{entry: array<string, mixed>, reason: string, required_by: array<int, string>}>  $placed  배치 집합
     * @param  array<string, array<string, mixed>>  $unplaced  미배치 집합 (`{type}:{identifier}` => 인벤토리 항목)
     */
    public function __construct(
        public readonly array $placed,
        public readonly array $unplaced,
    ) {}

    /**
     * 미배치 번들의 base 상대경로 목록(Step 6.5·7 이 건너뛸 경로)을 돌려줍니다.
     *
     * @return array<int, string> 정렬된 base 상대경로 목록
     */
    public function skipPaths(): array
    {
        $paths = array_values(array_map(fn (array $entry) => (string) $entry['path'], $this->unplaced));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * 사유가 같은 배치 항목만 골라 돌려줍니다.
     *
     * @param  string  $reason  installed|on_disk|dependency|selected|core|target_placed
     * @return array<string, array{entry: array<string, mixed>, reason: string, required_by: array<int, string>}> 배치 항목
     */
    public function placedBy(string $reason): array
    {
        return array_filter($this->placed, fn (array $row) => $row['reason'] === $reason);
    }
}
