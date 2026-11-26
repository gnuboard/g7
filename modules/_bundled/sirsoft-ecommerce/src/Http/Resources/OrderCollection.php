<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 주문 컬렉션 리소스
 *
 * 주문 목록을 페이지네이션 및 통계와 함께 반환합니다.
 */
class OrderCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.orders.update',
            // [내보내기] 버튼 — 목록 API 가 이미 이 목록의 조회 권한을 요구하므로 내보내기 요청 권한 하나로 정한다
            // audit:allow abilitymap-permission-matches-route-ssot reason: 이 플래그가 여는 동작은 내보내기 요청 API(POST admin/exports)이고 그 라우트 권한(SSoT)이 exports.create 다 — 목록 리소스 권한으로 게이팅하면 내보내기 권한 없는 운영자에게 버튼이 보인다
            'can_export' => 'sirsoft-ecommerce.exports.create',
        ];
    }

    /**
     * 행마다 주문 회원의 표시 이름을 계산하기 전에 필요한 값을 한 번에 읽어 둡니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return void 반환값 없음
     */
    private function preloadMemberNames(Request $request): void
    {
        MemberDisplayName::preloadStaffRelations($this->collection, ['user'], $request);
    }

    /**
     * 주문 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 주문 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $this->preloadMemberNames($request);

        $result = [
            'data' => $this->mapWithRowNumber(function ($order) {
                return (new OrderListResource($order))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $result['pagination'] = [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ];
        }

        return $result;
    }

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 주문 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $this->preloadMemberNames(request());

        $result = [
            'data' => $this->mapWithRowNumber(function ($order) {
                return (new OrderListResource($order))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), request()->user()),
            'statistics' => $statistics,
        ];

        if ($this->resource instanceof LengthAwarePaginator) {
            $result['pagination'] = [
                'current_page' => $this->resource->currentPage(),
                'last_page' => $this->resource->lastPage(),
                'per_page' => $this->resource->perPage(),
                'total' => $this->resource->total(),
                'from' => $this->resource->firstItem(),
                'to' => $this->resource->lastItem(),
                'has_more_pages' => $this->resource->hasMorePages(),
            ];
        }

        return $result;
    }
}
