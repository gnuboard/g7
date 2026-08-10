<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductAdditionalOptionValueRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 추가옵션 선택 검증·정규화 서비스
 *
 * 담기(CartService) 와 바로구매(TempOrderService) 양 진입점에서 동일한 서버 SSoT
 * 검증을 재사용하기 위한 도메인 서비스입니다.
 */
class AdditionalOptionSelectionService
{
    /**
     * 상품 ID → 활성 선택지 맵 (요청 스코프)
     *
     * @var array<int, Collection>
     */
    private array $activeValuesCache = [];

    /**
     * 상품 ID → 필수 그룹 판정용 상품 모델 (요청 스코프)
     *
     * @var array<int, Product|null>
     */
    private array $productCache = [];

    public function __construct(
        protected ProductAdditionalOptionValueRepositoryInterface $additionalOptionValueRepository,
        protected ProductRepositoryInterface $productRepository
    ) {}

    /**
     * 여러 상품의 검증 자료를 한 번에 적재합니다.
     *
     * 장바구니 병합·바로구매처럼 항목이 여러 개인 경로는 검증 전에 이 메서드를 부릅니다.
     * 부르지 않아도 동작은 같지만, 그 경우 상품마다 조회가 발생합니다.
     *
     * @param  array<int, int>  $productIds  상품 ID 목록
     */
    public function prefetchForProducts(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $missing = array_values(array_diff($productIds, array_keys($this->activeValuesCache)));

        if (empty($missing)) {
            return;
        }

        $valuesByProduct = $this->additionalOptionValueRepository->getActiveByProductIdsKeyed($missing);

        foreach ($missing as $productId) {
            $this->activeValuesCache[$productId] = $valuesByProduct->get($productId) ?? collect();
        }

        // 필수 그룹 판정에 쓰는 상품 + 그룹도 한 번에 읽는다.
        $products = $this->productRepository->findByIdsWithRelationsKeyed($missing, ['additionalOptions']);

        foreach ($missing as $productId) {
            $this->productCache[$productId] = $products->get($productId);
        }
    }

    /**
     * 추가옵션 선택을 검증하고 정규화합니다.
     *
     * - value_id 가 활성·해당 상품 소속이 아니면 422 (D12)
     * - 필수 그룹 미선택 시 422 (D9/D12)
     * - 그룹당 1개 선택(라디오) 고정 — 같은 그룹 중복 선택은 마지막 값으로 정규화
     * - 선택지의 allow_custom_text=true 이면 custom_text 입력 필수 (빈값/공백만이면 422, E4/Q-E1)
     * - allow_custom_text=false 인데 custom_text 전송 시 무시(드롭, E4)
     *
     * @param  int  $productId  상품 ID
     * @param  array|null  $selections  추가옵션 선택 [{additional_option_id, value_id, custom_text?}]
     * @return array 정규화된 선택 배열 (그룹당 1개, 빈 배열 가능)
     *
     * @throws CartUnavailableException 유효하지 않은/필수 미선택 추가옵션인 경우
     */
    public function validateAndNormalize(int $productId, ?array $selections): array
    {
        $selections = $selections ?? [];

        // 상품에 정의된 활성 선택지 lookup (value_id 키)
        // prefetchForProducts() 를 먼저 부른 경로는 여기서 쿼리가 나가지 않는다.
        $activeValues = $this->resolveActiveValues($productId);

        // 그룹당 1개로 정규화 (라디오) — 값과 직접입력 텍스트를 함께 보관
        $byGroup = [];
        foreach ($selections as $selection) {
            $valueId = (int) ($selection['value_id'] ?? 0);
            if ($valueId <= 0) {
                continue;
            }

            $value = $activeValues->get($valueId);

            // value_id 가 활성·해당 상품 소속이 아니면 422 (D12)
            if (! $value) {
                throw CartUnavailableException::fromItems([[
                    'product_id' => $productId,
                    'value_id' => $valueId,
                    'reason' => 'additional_option_invalid',
                ]]);
            }

            // 직접입력 텍스트 정규화: allow_custom_text 인 선택지만 보존, 그 외엔 드롭 (E4)
            // 빈값 필수 검증은 그룹 is_required 가 필요하므로 아래 그룹 루프에서 수행
            $customText = null;
            $allowCustomText = (bool) $value->allow_custom_text;
            if ($allowCustomText) {
                $customText = trim((string) ($selection['custom_text'] ?? ''));
            }

            $byGroup[(int) $value->additional_option_id] = [
                'value_id' => $valueId,
                'custom_text' => $customText,
                'allow_custom_text' => $allowCustomText,
                'value_name' => $value->getLocalizedName(),
            ];
        }

        // 필수 그룹 미선택 + 필수 그룹의 직접입력 빈값 검증 (D9/D12/Q-E1)
        $product = $this->resolveProduct($productId);
        if ($product) {
            foreach ($product->additionalOptions as $group) {
                // 필수 그룹 미선택 차단
                if ($group->is_required && ! isset($byGroup[$group->id])) {
                    throw CartUnavailableException::fromItems([[
                        'product_id' => $productId,
                        'additional_option_id' => $group->id,
                        'name' => $group->getLocalizedName(),
                        'reason' => 'additional_option_required',
                    ]]);
                }

                // 직접입력 텍스트 필수는 "필수 그룹"에 한해 강제 (비필수 그룹은 빈값 허용)
                $entry = $byGroup[$group->id] ?? null;
                if ($group->is_required && $entry && $entry['allow_custom_text'] && $entry['custom_text'] === '') {
                    throw CartUnavailableException::fromItems([[
                        'product_id' => $productId,
                        'additional_option_id' => $group->id,
                        'value_id' => $entry['value_id'],
                        'name' => $group->getLocalizedName(),
                        'reason' => 'additional_option_custom_text_required',
                    ]]);
                }
            }
        }

        $normalized = [];
        foreach ($byGroup as $additionalOptionId => $entry) {
            $row = [
                'additional_option_id' => $additionalOptionId,
                'value_id' => $entry['value_id'],
            ];

            // 직접입력 텍스트는 존재할 때만 포함 (allow_custom_text 선택지 한정)
            if ($entry['custom_text'] !== null && $entry['custom_text'] !== '') {
                $row['custom_text'] = $entry['custom_text'];
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * 상품의 활성 선택지 맵을 반환합니다 (요청 스코프 캐시).
     *
     * @param  int  $productId  상품 ID
     * @return Collection value_id 키 맵
     */
    private function resolveActiveValues(int $productId)
    {
        if (! array_key_exists($productId, $this->activeValuesCache)) {
            $this->activeValuesCache[$productId] = $this->additionalOptionValueRepository
                ->getActiveByProductKeyed($productId);
        }

        return $this->activeValuesCache[$productId];
    }

    /**
     * 필수 그룹 판정용 상품을 반환합니다 (요청 스코프 캐시, additionalOptions 적재).
     *
     * @param  int  $productId  상품 ID
     * @return Product|null 상품 모델
     */
    private function resolveProduct(int $productId)
    {
        if (! array_key_exists($productId, $this->productCache)) {
            $product = $this->productRepository->find($productId);
            $product?->loadMissing('additionalOptions');
            $this->productCache[$productId] = $product;
        }

        return $this->productCache[$productId];
    }
}
