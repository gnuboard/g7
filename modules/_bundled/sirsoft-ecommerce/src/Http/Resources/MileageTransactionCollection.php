<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

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
