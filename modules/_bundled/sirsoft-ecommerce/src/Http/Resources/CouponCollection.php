<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use App\Http\Resources\BaseApiCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 쿠폰 컬렉션 리소스
 */
class CouponCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * The resource that this resource collects.
     *
     * @var string
     */
    public $collects = CouponResource::class;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.promotion-coupon.create',
            'can_update' => 'sirsoft-ecommerce.promotion-coupon.update',
            'can_delete' => 'sirsoft-ecommerce.promotion-coupon.delete',
        ];
    }

    /**
     * 쿠폰 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 쿠폰 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $result = [
            'data' => $this->collection,
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
}
