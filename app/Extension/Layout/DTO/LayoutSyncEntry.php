<?php

namespace App\Extension\Layout\DTO;

/**
 * 레이아웃 행 하나의 동기화 결과 (Value Object).
 *
 * 판정(`verdict`):
 *  - 운영자와 무관: `created` · `updated` · `unchanged` · `took_theirs` · `deleted`
 *  - 운영자 관련(보고 대상): `merged` · `conflict` · `kept` · `kept_no_base` · `kept_removed` · `kept_invalid`
 *    · `kept_deleted_by_operator` · `kept_name_collision`
 */
final readonly class LayoutSyncEntry
{
    /**
     * 보고(알림·활동 로그·CLI 표)에 싣는 판정 — 운영자가 고친 행과 관련된 것만.
     */
    public const REPORTED_VERDICTS = ['merged', 'conflict', 'kept', 'kept_no_base', 'kept_removed', 'kept_invalid', 'kept_deleted_by_operator', 'kept_name_collision'];

    /**
     * @param  string  $kind  `layout` 또는 `extension`
     * @param  string  $template  템플릿 식별자
     * @param  string  $name  레이아웃 이름 (조각은 `대상:조각키`)
     * @param  string  $verdict  판정
     * @param  list<array{unit: string, kind: string}>  $applied  새 판 변경을 반영한 단위
     * @param  list<array{unit: string, reason: string}>  $conflicts  겹쳐서 운영자 판을 남긴 단위
     */
    public function __construct(
        public string $kind,
        public string $template,
        public string $name,
        public string $verdict,
        public array $applied = [],
        public array $conflicts = [],
    ) {}

    /**
     * 보고 대상 판정인지 판정합니다.
     *
     * @return bool 운영자 관련 행이면 true
     */
    public function isReported(): bool
    {
        return in_array($this->verdict, self::REPORTED_VERDICTS, true);
    }

    /**
     * 배열로 바꿉니다 (API 응답·활동 로그용).
     *
     * @return array{kind: string, template: string, name: string, verdict: string, applied: list<array{unit: string, kind: string}>, conflicts: list<array{unit: string, reason: string}>} 배열
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'template' => $this->template,
            'name' => $this->name,
            'verdict' => $this->verdict,
            'applied' => $this->applied,
            'conflicts' => $this->conflicts,
        ];
    }
}
