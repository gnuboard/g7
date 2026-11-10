<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 회원 최근 검색어 한 건 응답 리소스
 *
 * 검색창 펼침 목록이 그리는 최소 필드만 낸다 — 범위·회원 id 는 요청한 본인이 이미 안다.
 *
 * @since 7.1.5
 */
class SearchHistoryResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  요청
     * @return array{id: int, keyword: string, searched_at: string|null, searched_at_ms: int|null} 검색어 한 건
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getValue('id'),
            'keyword' => (string) $this->getValue('keyword'),
            // ISO 8601 (사용자 시간대) — 화면 표시용
            'searched_at' => $this->formatDateTimeForUser($this->resource->searched_at),
            // 밀리초 시각 — 엔진이 비회원 목록과 같은 단위로 비교한다
            'searched_at_ms' => $this->resource->searched_at?->getTimestampMs(),
        ];
    }
}
