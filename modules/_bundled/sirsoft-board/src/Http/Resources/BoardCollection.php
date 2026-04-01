<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시판 목록 리소스 컬렉션
 *
 * 게시판 목록을 페이지네이션과 함께 반환합니다.
 */
class BoardCollection extends ResourceCollection
{
    use ChecksBoardPermission;

    /**
     * 리소스 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<string, mixed> 변환된 배열 데이터
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection->map(function ($board) {
                return new BoardResource($board);
            }),
            'pagination' => $this->when($this->resource instanceof LengthAwarePaginator, fn () => [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ]),
        ];
    }

    /**
     * 권한 정보가 포함된 형태의 배열을 반환합니다.
     *
     * @return array<string, mixed> 권한 정보가 포함된 게시판 컬렉션
     */
    public function withPermissions(): array
    {
        return [
            'data' => $this->collection->map(function ($board) {
                return new BoardResource($board);
            }),
            'pagination' => $this->resource instanceof LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
            'abilities' => [
                'can_create' => $this->checkModulePermission('boards', 'create'),
                'can_update' => $this->checkModulePermission('boards', 'update'),
                'can_delete' => $this->checkModulePermission('boards', 'delete'),
            ],
        ];
    }
}
