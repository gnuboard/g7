<?php

namespace App\Extension\Layout\DTO;

/**
 * 레이아웃 3자 병합 결과 (Value Object).
 *
 * `verdict` 는 병합기가 정한 판정이다 — 동기화기가 행 단위 판정(`kept_no_base` 등)을 더한다.
 *  - `took_theirs` 운영자가 고치지 않아(또는 새 판과 같아) 새 판을 그대로 씀
 *  - `kept` 새 판이 바꾼 것이 없어(또는 겹치지 않는 변경이 없어) 운영자 판 유지
 *  - `merged` 새 판 변경을 한 곳 이상 반영
 *  - `conflict` 반영한 곳은 없고 겹친 곳만 있음 — 운영자 판 유지
 *  - `kept_invalid` 병합 결과가 검증을 통과하지 못해 운영자 판 유지
 */
final readonly class LayoutMergeResult
{
    /**
     * @param  array<string, mixed>  $merged  병합된 본문 (조각 출처 표식·자리표시 없음)
     * @param  string  $verdict  판정
     * @param  list<array{unit: string, kind: string}>  $applied  새 판 변경을 반영한 단위
     * @param  list<array{unit: string, reason: string}>  $conflicts  운영자 판을 남긴 겹친 단위
     */
    public function __construct(
        public array $merged,
        public string $verdict,
        public array $applied = [],
        public array $conflicts = [],
    ) {}

    /**
     * 판정만 바꾼 사본을 만듭니다 (검증 실패 시 운영자 판으로 되돌릴 때). 원본은 바뀌지 않는다.
     *
     * @param  string  $verdict  새 판정
     * @param  array<string, mixed>  $merged  새 본문
     * @return self 사본
     */
    public function withVerdict(string $verdict, array $merged): self
    {
        return new self($merged, $verdict, $verdict === 'kept_invalid' ? [] : $this->applied, $this->conflicts);
    }

    /**
     * 배열로 바꿉니다 (진단·로그용).
     *
     * @return array{merged: array<string, mixed>, verdict: string, applied: list<array{unit: string, kind: string}>, conflicts: list<array{unit: string, reason: string}>} 배열
     */
    public function toArray(): array
    {
        return [
            'merged' => $this->merged,
            'verdict' => $this->verdict,
            'applied' => $this->applied,
            'conflicts' => $this->conflicts,
        ];
    }
}
