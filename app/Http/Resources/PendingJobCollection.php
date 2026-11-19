<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * 큐 대기 작업 목록 컬렉션 (#17)
 *
 * `meta` 에 측정 가능 여부를 싣는다 — database 드라이버가 아니면 목록은 비고 `measurable=false` 다.
 */
class PendingJobCollection extends BaseApiCollection
{
    /**
     * 목록 메타 (measurable·connection·driver)
     *
     * @var array<string, mixed>
     */
    private array $listMeta = [];

    /**
     * 목록 메타를 지정합니다.
     *
     * @param  array<string, mixed>  $meta  메타 (measurable·connection·driver)
     * @return $this 체이닝용
     */
    public function withListMeta(array $meta): static
    {
        $this->listMeta = $meta;

        return $this;
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(fn ($row) => (new PendingJobResource($row))->toArray($request))->values()->all(),
            ...$this->paginationMeta(),
            'meta' => $this->listMeta,
        ];
    }
}
