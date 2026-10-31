<?php

namespace App\Http\Resources;

use App\Privacy\DTO\PrivacyRetentionTarget;
use Illuminate\Http\Request;

/**
 * 개인정보 조치 대상 목록 컬렉션
 *
 * 보유기간 핸들러 · 휴면 단계 · 확장의 통지 대상이 모두 같은 모양으로 응답하도록 한다 —
 * 관리자 화면의 「대상 목록」 모달 하나가 어느 공급자든 그대로 그린다.
 *
 * 응답: `data[]`(행) · `columns`(열 이름 => 라벨, 없는 열은 그리지 않음) · `pagination`(표준 메타).
 */
class PrivacyTargetCollection extends BaseApiCollection
{
    /**
     * 열 라벨
     *
     * @var array<string, string>
     */
    private array $columns = [];

    /**
     * 열 라벨을 지정합니다.
     *
     * @param  array<string, string>  $columns  열 이름 => 라벨
     * @return static 체이닝용
     */
    public function withColumns(array $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection
                ->map(fn (PrivacyRetentionTarget $target) => $target->toArray())
                ->values()
                ->all(),
            'columns' => $this->columns,
            // 표준 메타 — 모달의 무한스크롤이 has_more_pages 로 다음 페이지를 판정한다.
            ...$this->paginationMeta(),
        ];
    }
}
