<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시판 목록 리소스 컬렉션
 *
 * 게시판 목록을 페이지네이션과 함께 반환합니다.
 */
class BoardCollection extends BaseApiCollection
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
            'pagination' => $this->resource instanceof LengthAwarePaginator ? [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ] : null,
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
            // 목록은 경량 표현만 내려준다. 전체 표현(toArray)은 게시판마다
            // 매니저/스텝 역할과 그 소속 사용자 전원을 조회해 붙이는데(행당 추가 쿼리),
            // 관리자 목록 화면은 그 값을 쓰지 않는다 — 역할 편집은 게시판 상세/설정이 공급한다.
            'data' => $this->collection->map(function ($board) {
                return (new BoardResource($board))->toAdminListArray(request());
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
