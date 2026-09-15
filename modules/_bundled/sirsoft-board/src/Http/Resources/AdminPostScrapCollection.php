<?php

namespace Modules\Sirsoft\Board\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use Illuminate\Http\Request;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 관리자 스크랩 목록 컬렉션
 *
 * 신고 현황(ReportCollection::withStatisticsAndPermissions)과 같은 모양에서 statistics 만 뺀
 * 형태다 — 스크랩은 상태 전이가 없어 집계 카드가 없다.
 *
 * 페이지네이션 메타는 BaseApiCollection::paginationMeta() 를 그대로 쓴다. 관리자 목록은
 * 지연 조인 + resultCap 경로라 결과가 BoundedPage 일 수 있고, 그 형태의 total_relation·
 * result_cap 까지 실어내는 규칙이 부모에 한 벌만 있어야 한다.
 */
class AdminPostScrapCollection extends BaseApiCollection
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
        return array_merge([
            'data' => $this->collection->map(fn ($scrap) => new AdminPostScrapResource($scrap)),
        ], $this->paginationMeta());
    }

    /**
     * 권한 정보가 포함된 형태의 배열을 반환합니다.
     *
     * 화면이 일괄 삭제 버튼·행 액션의 활성 여부를 이 값으로 판정한다 — 행마다의
     * abilities.can_delete 와 같은 근거이되, 선택이 0건이거나 목록이 비었을 때도
     * 버튼 상태를 정할 수 있다.
     *
     * @return array<string, mixed> 권한 정보가 포함된 스크랩 컬렉션
     */
    public function withPermissions(): array
    {
        return array_merge([
            'data' => $this->collection->map(fn ($scrap) => new AdminPostScrapResource($scrap)),
        ], $this->paginationMeta(), [
            'abilities' => [
                'can_view' => $this->checkModulePermission('scraps', 'view'),
                'can_manage' => $this->checkModulePermission('scraps', 'manage'),
            ],
        ]);
    }
}
