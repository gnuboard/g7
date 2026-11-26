<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sirsoft\Ecommerce\Support\MemberDisplayName;

/**
 * 마일리지 거래 컬렉션 리소스
 *
 * 마일리지 거래 목록을 페이지네이션 및 통화 필터 옵션과 함께 반환합니다.
 */
class MileageTransactionCollection extends BaseApiCollection
{
    use HasAbilityCheck;

    /**
     * 통화 필터 옵션 목록 (설정 currency_rules 기준)
     *
     * @var array<int, string>
     */
    protected array $currencies = [];

    /**
     * 통화 필터 옵션을 주입합니다.
     *
     * @param  array<int, string>  $currencies  통화 코드 배열
     * @return $this
     */
    public function withCurrencies(array $currencies): static
    {
        $this->currencies = array_values($currencies);

        return $this;
    }

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_manage' => 'sirsoft-ecommerce.mileage.manage',
            // [내보내기] 버튼 — 목록 API 가 이미 이 목록의 조회 권한을 요구하므로 내보내기 요청 권한 하나로 정한다
            // audit:allow abilitymap-permission-matches-route-ssot reason: 이 플래그가 여는 동작은 내보내기 요청 API(POST admin/exports)이고 그 라우트 권한(SSoT)이 exports.create 다 — 목록 리소스 권한으로 게이팅하면 내보내기 권한 없는 운영자에게 버튼이 보인다
            'can_export' => 'sirsoft-ecommerce.exports.create',
        ];
    }

    /**
     * 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return array<int|string, mixed> 변환된 배열
     */
    public function toArray(Request $request): array
    {
        // 행마다 회원과 처리 관리자의 표시 이름을 계산하기 전에 필요한 값을 한 번에 읽어 둔다.
        MemberDisplayName::preloadStaffRelations($this->collection, ['user', 'grantedByUser'], $request);

        $result = [
            'data' => $this->mapWithRowNumber(function ($transaction) {
                return (new MileageTransactionResource($transaction))->toArray(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
            'currencies' => $this->currencies,
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
