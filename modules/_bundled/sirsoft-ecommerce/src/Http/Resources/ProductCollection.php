<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiCollection;
use App\Http\Resources\Traits\HasAbilityCheck;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 상품 컬렉션 리소스
 *
 * 상품 목록을 페이지네이션 및 통계와 함께 반환합니다.
 */
class ProductCollection extends BaseApiCollection
{
    use HasAbilityCheck;
    use HasMultiCurrencyPrices;

    /**
     * 컬렉션 레벨 능력(can_*) 매핑을 반환합니다.
     *
     * @return array<string, string> 능력 매핑
     */
    protected function abilityMap(): array
    {
        return [
            'can_create' => 'sirsoft-ecommerce.products.create',
            'can_update' => 'sirsoft-ecommerce.products.update',
            'can_delete' => 'sirsoft-ecommerce.products.delete',
        ];
    }

    /**
     * 상품 컬렉션을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<int|string, mixed> 변환된 상품 컬렉션 배열
     */
    public function toArray(Request $request): array
    {
        $this->prefetchCategoryAncestors();

        return [
            'data' => $this->mapWithRowNumber(function ($product) {
                return (new ProductListResource($product))->resolve(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), $request->user()),
            // 표준 메타를 쓴다. 상한형 페이지에서는 total_relation/result_cap 이 함께 실리고,
            // 총 건수를 정확히 알 수 없으면 last_page 가 null 로 나간다.
            ...$this->paginationMeta(),
        ];
    }

    /**
     * 이번 응답이 그릴 분류 경로의 조상 카테고리를 한 번에 예열합니다.
     *
     * 상품마다 예열하면 예열 쿼리가 상품 수만큼 반복됩니다. 카테고리는 이미 적재돼
     * 있으므로 path 를 메모리에서 읽어 조상 조회를 응답당 1회로 고정합니다.
     */
    private function prefetchCategoryAncestors(): void
    {
        Category::prefetchAncestorsFor(
            $this->collection
                ->filter(fn ($product) => $product->relationLoaded('categories'))
                ->flatMap(fn ($product) => $product->categories)
                ->unique('id')
        );
    }

    /**
     * 통계가 포함된 형태의 배열을 반환합니다.
     *
     * @param  array  $statistics  통계 데이터 배열
     * @return array<string, mixed> 통계 정보가 포함된 상품 컬렉션
     */
    public function withStatistics(array $statistics = []): array
    {
        $this->prefetchCategoryAncestors();

        return [
            'data' => $this->mapWithRowNumber(function ($product) {
                return (new ProductListResource($product))->resolve(request());
            }),
            'abilities' => $this->resolveAbilitiesFromMap($this->abilityMap(), request()->user()),
            'statistics' => $statistics,
            ...$this->paginationMeta(),
        ];
    }

    /**
     * 간단한 목록 형태의 배열을 반환합니다.
     *
     * @return array<int, array<string, mixed>> 간략한 상품 정보 배열
     */
    public function toSimpleArray(): array
    {
        return $this->collection->map(function ($product) {
            return [
                'id' => $product->id,
                'name' => $product->getLocalizedName(),
                'product_code' => $product->product_code,
                'selling_price' => $this->roundToBaseCurrency($product->selling_price),
            ];
        })->toArray();
    }
}
