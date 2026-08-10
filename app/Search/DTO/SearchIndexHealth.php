<?php

namespace App\Search\DTO;

use App\Enums\SearchIndexStatus;

/**
 * 검색 인덱스 건강도 판정 결과 (Value Object · 엔진 중립)
 *
 * 어떤 엔진이든 공통으로 답할 수 있는 것만 필드로 둡니다 — 무엇을(`identifier`),
 * 어떤 상태로(`status`), 무엇을 근거로(`measurement`) 판정했는가.
 *
 * 엔진마다 다른 세부(FULLTEXT 의 테이블·컬럼·파서, 외부 엔진의 샤드·문서 수 등)는
 * `details` 에 담습니다. 코어가 그 내용을 해석하지 않으므로 새 엔진이 추가되어도
 * 코어를 고칠 일이 없습니다.
 *
 * `context` 는 엔진 구현이 재생성에 필요한 자기 정보를 실어 두는 자리입니다
 * (코어는 그대로 되돌려 줄 뿐 들여다보지 않습니다).
 */
readonly class SearchIndexHealth
{
    /**
     * @param  string  $driver  판정을 만든 Scout 드라이버명
     * @param  string  $identifier  인덱스 식별자 (엔진 내에서 유일)
     * @param  SearchIndexStatus  $status  판정 등급
     * @param  string  $measurement  판정 근거 수치·사유 (사람이 읽는 한 줄)
     * @param  array<string, mixed>  $details  엔진별 부가 정보 (표시 전용)
     * @param  array<string, mixed>  $context  재생성에 필요한 엔진 내부 정보
     */
    public function __construct(
        public string $driver,
        public string $identifier,
        public SearchIndexStatus $status,
        public string $measurement = '',
        public array $details = [],
        public array $context = [],
    ) {}

    /**
     * 재생성 대상인지 여부.
     *
     * @return bool
     */
    public function needsRebuild(): bool
    {
        return $this->status === SearchIndexStatus::Stale;
    }

    /**
     * 기계 판독용 배열로 반환합니다.
     *
     * `context` 는 엔진 내부 정보라 응답에 싣지 않습니다.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'identifier' => $this->identifier,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'measurement' => $this->measurement,
            'details' => $this->details,
        ];
    }
}
