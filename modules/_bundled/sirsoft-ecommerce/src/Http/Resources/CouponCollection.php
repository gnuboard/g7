<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Services\BirthdayFieldResolver;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

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
        // 행마다 등록자의 표시 이름을 계산하기 전에 필요한 값을 한 번에 읽어 둔다.
        MemberDisplayName::preloadStaffRelations($this->collection, ['creator'], $request);

        $result = [
            'data' => $this->collection,
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
            // 「생일로 쓸 회원 항목」 지정 여부 — 등록 폼(쿠폰 응답이 없는 진입)의 생일 쿠폰 경고 근거
            'birthday_field_configured' => app(BirthdayFieldResolver::class)->isConfigured(),
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
