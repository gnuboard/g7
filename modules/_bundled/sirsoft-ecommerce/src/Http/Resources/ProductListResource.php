<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;

/**
 * 상품 목록 리소스
 */
class ProductListResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 상품 목록 리소스 배열 (다중 통화 가격 포함)
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_localized' => $this->getLocalizedName(),
            'product_code' => $this->product_code,
            'sku' => $this->sku,
            'thumbnail_url' => $this->getThumbnailUrl(),

            // 가격
            'list_price' => $this->roundToBaseCurrency($this->list_price),
            'list_price_formatted' => $this->formatBaseCurrency($this->list_price),
            'selling_price' => $this->roundToBaseCurrency($this->selling_price),
            'selling_price_formatted' => $this->formatBaseCurrency($this->selling_price),
            'discount_rate' => $this->getDiscountRate(),

            // 다중 통화 가격
            'multi_currency_list_price' => $this->buildMultiCurrencyPrices($this->list_price),
            'multi_currency_selling_price' => $this->buildMultiCurrencyPrices($this->selling_price),

            // 재고
            'stock_quantity' => $this->stock_quantity,
            'safe_stock_quantity' => $this->safe_stock_quantity,
            'is_below_safe_stock' => $this->isBelowSafeStock(),
            'option_stock_sum' => $this->resolveActiveOptionStockSum(),

            // 상태
            'sales_status' => $this->sales_status->value,
            'sales_status_label' => $this->sales_status->label(),
            'sales_status_variant' => $this->sales_status->variant(),
            'display_status' => $this->display_status->value,
            'display_status_label' => $this->display_status->label(),
            'display_status_variant' => $this->display_status->variant(),

            // 카테고리 (다대다)
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($cat) => [
                'id' => $cat->id,
                'name' => $cat->getLocalizedName(),
                'is_primary' => $cat->pivot->is_primary,
            ])),
            'primary_category' => $this->whenLoaded('categories', fn () => $this->categories->firstWhere('pivot.is_primary', true)?->getLocalizedName()
            ),
            // 경로는 카테고리당 한 번만 만든다. 두 번 부르면 조상 조회도 두 번 나간다.
            //
            // 조상 예열은 여기가 아니라 ProductCollection 이 응답 단위로 한 번 수행한다.
            // 상품마다 예열하면 예열 쿼리가 상품 수만큼 반복된다. 단건 사용처(컬렉션을
            // 거치지 않는 경로)에서는 getBreadcrumb() 이 스스로 필요한 조상만 읽는다.
            'categories_with_path' => $this->whenLoaded('categories', function () {
                return $this->categories->map(function ($cat) {
                    $breadcrumb = $cat->getBreadcrumb();

                    return [
                        'id' => $cat->id,
                        'path' => $breadcrumb,
                        'path_string' => collect($breadcrumb)->pluck('name')->implode(' > '),
                        'is_primary' => $cat->pivot->is_primary,
                    ];
                });
            }),

            // 브랜드 (다국어)
            'brand_name' => $this->whenLoaded('brand', fn () => $this->brand?->getLocalizedName()),

            // 배송 정책
            'shipping_policy_id' => $this->shipping_policy_id,
            'shipping_policy_name' => $this->whenLoaded('shippingPolicy', fn () => $this->shippingPolicy?->getLocalizedName()),

            // 구매 수량 제한
            'min_purchase_qty' => $this->min_purchase_qty,
            'max_purchase_qty' => $this->max_purchase_qty,

            // 옵션
            // 옵션 배열은 관계가 실제로 로드된 경우에만 실린다. 관리자 목록은 기본적으로 옵션을
            // 로드하지 않고(`?with_options=1` opt-in), 행을 펼칠 때 배치 엔드포인트로 가져간다.
            'has_options' => $this->has_options,
            'options_count' => $this->resolveActiveOptionCount(),
            'options_total_count' => $this->resolveTotalOptionCount(),
            'options' => $this->whenLoaded(
                'options',
                fn () => ProductOptionResource::collection($this->options),
                fn () => ProductOptionResource::collection($this->whenLoaded('activeOptions'))
            ),

            // 라벨
            'labels' => $this->whenLoaded('activeLabelAssignments', fn () => $this->activeLabelAssignments
                ->filter(fn ($a) => $a->label && $a->label->is_active)
                ->sortBy(fn ($a) => $a->label->sort_order)
                ->map(fn ($a) => [
                    'name' => $a->label->name[app()->getLocale()]
                        ?? $a->label->name[config('app.fallback_locale')]
                        ?? array_values($a->label->name ?? [])[0] ?? '',
                    'color' => $a->label->color,
                ])->values()
            ),

            // 리뷰 통계 — 조회 시 조인으로 붙는 집계다.
            // 값이 null 인지가 아니라 **집계 컬럼이 붙었는지**로 판정한다. 리뷰 0건이면 COUNT/AVG
            // 별칭은 존재하고 값만 null 이므로 종전대로 0 으로 표기되고, 집계를 아예 붙이지 않은
            // 경로에서는 필드를 생략한다 — 0 으로 채우면 "세어보니 0" 과 구분되지 않아
            // 리뷰가 달린 상품도 평점 0.0 으로 나간다.
            'review_count' => $this->resolveReviewCount(),
            'rating_avg' => $this->resolveRatingAvg(),

            // 날짜
            'created_at' => $this->formatDateTimeStringForUser($this->created_at),
            'updated_at' => $this->formatDateTimeStringForUser($this->updated_at),

            // 권한 정보 (is_owner + abilities)
            ...$this->resourceMeta($request),
        ];
    }

    /**
     * 활성 옵션의 재고 합계를 반환합니다.
     *
     * 목록 쿼리가 붙인 DB 집계(`withSum`)를 우선 사용합니다. 옵션이 0건이면 SUM 은 `0` 이 아니라
     * `null` 을 돌려주므로 반드시 0 으로 확정합니다 — null 을 그대로 흘리면 화면의 재고 불일치
     * 비교(`stock_quantity !== option_stock_sum`)가 전 상품에서 오탐합니다.
     *
     * 집계가 없는 호출 경로(인기/신상품/위시리스트 등)는 종전의 관계 기반 계산을 유지합니다.
     *
     * @return mixed 재고 합계(int) 또는 계산 불가 시 MissingValue
     */
    protected function resolveActiveOptionStockSum(): mixed
    {
        // 값이 null 인지가 아니라 **집계 컬럼이 붙었는지**로 판정한다. 옵션 0건이면 SUM 은 NULL 을
        // 돌려주므로 null 검사로는 "집계를 안 했다" 와 구분되지 않아, 옵션 없는 상품에서 필드가
        // 통째로 사라진다.
        if ($this->hasAggregateAttribute('option_stock_sum')) {
            return (int) $this->option_stock_sum;
        }

        if ($this->relationLoaded('options')) {
            return (int) $this->options->where('is_active', true)->sum('stock_quantity');
        }

        return $this->whenLoaded('activeOptions', fn () => (int) $this->activeOptions->sum('stock_quantity'));
    }

    /**
     * 활성 옵션 개수를 반환합니다.
     *
     * @return mixed 활성 옵션 수(int) 또는 계산 불가 시 MissingValue
     */
    protected function resolveActiveOptionCount(): mixed
    {
        if ($this->hasAggregateAttribute('options_count')) {
            return (int) $this->options_count;
        }

        if ($this->relationLoaded('options')) {
            return $this->options->where('is_active', true)->count();
        }

        return $this->whenLoaded('activeOptions', fn () => $this->activeOptions->count());
    }

    /**
     * 노출 리뷰 수를 반환합니다.
     *
     * 집계를 붙이지 않은 조회 경로에서는 0 을 지어내지 않고 필드를 생략합니다.
     *
     * @return mixed 리뷰 수(int) 또는 집계 부재 시 MissingValue
     */
    protected function resolveReviewCount(): mixed
    {
        return $this->hasAggregateAttribute('review_count')
            ? (int) $this->review_count
            : new MissingValue;
    }

    /**
     * 노출 리뷰의 평균 평점을 반환합니다.
     *
     * 리뷰가 0건이면 AVG 는 null 이고 별칭은 존재하므로 0.0 으로 표기합니다.
     *
     * @return mixed 평균 평점(float) 또는 집계 부재 시 MissingValue
     */
    protected function resolveRatingAvg(): mixed
    {
        return $this->hasAggregateAttribute('rating_avg')
            ? round((float) $this->rating_avg, 1)
            : new MissingValue;
    }

    /**
     * 비활성을 포함한 전체 옵션 개수를 반환합니다.
     *
     * 일괄 변경은 상품 ID 만 받아 서버에서 그 상품의 **모든** 옵션으로 전개하므로(비활성 포함),
     * 확인 모달이 실제 적용 대상 수를 보여주려면 활성 개수가 아니라 이 값을 세야 합니다.
     *
     * @return mixed 전체 옵션 수(int) 또는 계산 불가 시 MissingValue
     */
    protected function resolveTotalOptionCount(): mixed
    {
        if ($this->hasAggregateAttribute('options_total_count')) {
            return (int) $this->options_total_count;
        }

        return $this->whenLoaded('options', fn () => $this->options->count());
    }

    /**
     * 목록 쿼리가 붙인 집계 컬럼이 이 행에 존재하는지 확인합니다.
     *
     * 컬렉션 경로에서는 이 리소스가 다른 리소스를 한 겹 감싼 형태로 들어온다
     * (`ProductCollection` 이 Laravel 규칙에 따라 `ProductResource` 로 먼저 감싼다).
     * 그래서 내부 모델까지 풀어낸 뒤 확인한다 — `HasAbilityCheck` 와 같은 방식이다.
     *
     * @param  string  $key  집계 별칭
     * @return bool 컬럼 존재 여부 (값이 NULL 이어도 true)
     */
    private function hasAggregateAttribute(string $key): bool
    {
        $model = $this->resource;

        while ($model instanceof JsonResource) {
            $model = $model->resource;
        }

        return $model instanceof Model && array_key_exists($key, $model->getAttributes());
    }

    /**
     * 권한 체크 매핑을 반환합니다.
     *
     * @return array<string, string>
     */
    protected function abilityMap(): array
    {
        return [
            'can_update' => 'sirsoft-ecommerce.products.update',
            'can_delete' => 'sirsoft-ecommerce.products.delete',
        ];
    }

    /**
     * 소유자 필드명을 반환합니다.
     */
    protected function ownerField(): ?string
    {
        return 'created_by';
    }
}
