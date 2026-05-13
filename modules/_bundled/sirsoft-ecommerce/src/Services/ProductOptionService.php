<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 상품 옵션 서비스
 */
class ProductOptionService
{
    public function __construct(
        protected ProductOptionRepositoryInterface $repository,
        protected ProductRepositoryInterface $productRepository
    ) {}

    /**
     * "productId-optionId" 형식의 문자열 배열에서 옵션 ID만 추출
     *
     * @param array $mixedIds "productId-optionId" 형식의 문자열 배열
     * @return array 옵션 ID 배열
     */
    private function parseOptionIdsFromMixed(array $mixedIds): array
    {
        $optionIds = [];
        foreach ($mixedIds as $mixed) {
            $parts = explode('-', $mixed);
            if (count($parts) === 2 && is_numeric($parts[1])) {
                $optionIds[] = (int) $parts[1];
            }
        }

        return $optionIds;
    }

    /**
     * 상품 ID와 옵션 ID를 병합하여 최종 옵션 ID 배열 생성
     *
     * @param array $productIds 상품 ID 배열 (해당 상품의 모든 옵션 포함)
     * @param array $mixedOptionIds "productId-optionId" 형식의 문자열 배열
     * @return array 중복 제거된 옵션 ID 배열
     */
    private function mergeOptionIds(array $productIds, array $mixedOptionIds): array
    {
        // 1. 상품 ID로 해당 상품의 모든 옵션 ID 조회
        $optionIdsFromProducts = [];
        if (! empty($productIds)) {
            $optionIdsFromProducts = $this->repository->getIdsByProductIds($productIds);
        }

        // 2. 개별 선택된 옵션 ID 파싱
        $directOptionIds = $this->parseOptionIdsFromMixed($mixedOptionIds);

        // 3. 병합 및 중복 제거
        return array_unique(array_merge($optionIdsFromProducts, $directOptionIds));
    }

    /**
     * 상품 ID 배열과 옵션 ID 배열을 병합하여 옵션 판매가 일괄 변경
     *
     * @param array $productIds 상품 ID 배열 (해당 상품의 모든 옵션 대상)
     * @param array $mixedOptionIds "productId-optionId" 형식의 문자열 배열 (개별 선택된 옵션)
     * @param string $method 변경 방식 (increase, decrease, fixed)
     * @param int $value 변경 값
     * @param string $unit 단위 (won, percent)
     * @return array 결과 (updated_count, requested_product_count)
     */
    public function bulkUpdatePriceByMixedIds(
        array $productIds,
        array $mixedOptionIds,
        string $method,
        int $value,
        string $unit
    ): array {
        // 1. 옵션 ID 병합
        $optionIds = $this->mergeOptionIds($productIds, $mixedOptionIds);

        if (empty($optionIds)) {
            return [
                'updated_count' => 0,
                'requested_product_count' => count($productIds),
            ];
        }

        // 2. 수정 전 스냅샷 캡처 (after 훅에 전달)
        $snapshots = \Modules\Sirsoft\Ecommerce\Models\ProductOption::whereIn('id', $optionIds)
            ->get()->keyBy('id')->map->toArray()->all();

        // 3. before 훅
        HookManager::doAction('sirsoft-ecommerce.product_option.before_bulk_price_update', $optionIds, [
            'method' => $method,
            'value' => $value,
            'unit' => $unit,
        ]);

        // 4. 옵션 판매가 일괄 변경
        $updatedCount = $this->repository->bulkUpdatePrice($optionIds, $method, $value, $unit);

        // 5. after 훅 (리스너에서 변경 감지 + 로깅)
        HookManager::doAction('sirsoft-ecommerce.product_option.after_bulk_price_update', $optionIds, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_product_count' => count($productIds),
        ];
    }

    /**
     * 상품 ID 배열과 옵션 ID 배열을 병합하여 옵션 재고 일괄 변경
     *
     * @param array $productIds 상품 ID 배열 (해당 상품의 모든 옵션 대상)
     * @param array $mixedOptionIds "productId-optionId" 형식의 문자열 배열 (개별 선택된 옵션)
     * @param string $method 변경 방식 (increase, decrease, set)
     * @param int $value 변경 값
     * @return array 결과 (updated_count, requested_product_count)
     */
    public function bulkUpdateStockByMixedIds(
        array $productIds,
        array $mixedOptionIds,
        string $method,
        int $value
    ): array {
        // 1. 옵션 ID 병합
        $optionIds = $this->mergeOptionIds($productIds, $mixedOptionIds);

        if (empty($optionIds)) {
            return [
                'updated_count' => 0,
                'requested_product_count' => count($productIds),
            ];
        }

        // 2. 수정 전 스냅샷 캡처 (after 훅에 전달)
        $snapshots = \Modules\Sirsoft\Ecommerce\Models\ProductOption::whereIn('id', $optionIds)
            ->get()->keyBy('id')->map->toArray()->all();

        // 3. before 훅
        HookManager::doAction('sirsoft-ecommerce.product_option.before_bulk_stock_update', $optionIds, [
            'method' => $method,
            'value' => $value,
        ]);

        // 4. 옵션 재고 일괄 변경
        $updatedCount = $this->repository->bulkUpdateStock($optionIds, $method, $value);

        // 5. after 훅 (리스너에서 변경 감지 + 로깅)
        HookManager::doAction('sirsoft-ecommerce.product_option.after_bulk_stock_update', $optionIds, $updatedCount, $snapshots);

        return [
            'updated_count' => $updatedCount,
            'requested_product_count' => count($productIds),
        ];
    }

    /**
     * 상품 ID 배열로 해당 상품들의 모든 옵션 판매가 일괄 변경
     *
     * @deprecated Use bulkUpdatePriceByMixedIds instead
     * @param array $productIds 상품 ID 배열
     * @param string $method 변경 방식 (increase, decrease, fixed)
     * @param int $value 변경 값
     * @param string $unit 단위 (won, percent)
     * @return array 결과 (updated_count, requested_product_count)
     */
    public function bulkUpdatePriceByProductIds(array $productIds, string $method, int $value, string $unit): array
    {
        return $this->bulkUpdatePriceByMixedIds($productIds, [], $method, $value, $unit);
    }

    /**
     * 상품 ID 배열로 해당 상품들의 모든 옵션 재고 일괄 변경
     *
     * @deprecated Use bulkUpdateStockByMixedIds instead
     * @param array $productIds 상품 ID 배열
     * @param string $method 변경 방식 (increase, decrease, set)
     * @param int $value 변경 값
     * @return array 결과 (updated_count, requested_product_count)
     */
    public function bulkUpdateStockByProductIds(array $productIds, string $method, int $value): array
    {
        return $this->bulkUpdateStockByMixedIds($productIds, [], $method, $value);
    }

    /**
     * 옵션 통합 일괄 업데이트 (일괄 변경 + 개별 인라인 수정 동시 처리)
     *
     * 일괄 변경 조건이 설정된 필드는 우선 적용되며, 나머지는 개별 수정이 적용됩니다.
     *
     * @param array $data 업데이트 데이터 (product_ids/ids, bulk_changes, items)
     * @return array 업데이트 결과 (options_updated)
     */
    public function bulkUpdate(array $data): array
    {
        // 1. before 훅 실행
        HookManager::doAction('sirsoft-ecommerce.option.before_bulk_update', $data);

        // 2. filter 훅으로 데이터 변형 허용
        $data = HookManager::applyFilters('sirsoft-ecommerce.option.filter_bulk_update_data', $data);

        $optionsUpdated = 0;

        // 3. 옵션 ID 추출 (product_ids 또는 ids에서)
        $optionIds = [];
        if (! empty($data['product_ids'])) {
            // 상품 API에서 호출됨 - 상품 ID로 옵션 ID 추출
            $optionIds = $this->repository->getIdsByProductIds($data['product_ids']);
        } elseif (! empty($data['ids'])) {
            // 옵션 API에서 직접 호출됨 - "productId-optionId" 형식
            $optionIds = $this->parseOptionIdsFromMixed($data['ids']);
        }

        // 4. 수정 전 스냅샷 캡처 (after 훅에 전달)
        $snapshots = [];
        if (! empty($optionIds)) {
            $snapshots = \Modules\Sirsoft\Ecommerce\Models\ProductOption::whereIn('id', $optionIds)
                ->get()->keyBy('id')->map->toArray()->all();
        }

        if (empty($optionIds)) {
            return ['options_updated' => 0];
        }

        $bulkChanges = $data['bulk_changes'] ?? [];
        $items = $data['items'] ?? [];

        // 5. 일괄 변경 조건 처리 (bulk_changes)
        if (! empty($bulkChanges)) {
            // price_adjustment 일괄 변경
            if (isset($bulkChanges['price_adjustment'])) {
                $priceChange = $bulkChanges['price_adjustment'];
                $method = $priceChange['method'] ?? 'set';
                $value = (int) ($priceChange['value'] ?? 0);

                foreach ($optionIds as $optionId) {
                    $this->applyPriceAdjustment($optionId, $method, $value);
                    $optionsUpdated++;
                }
            }

            // stock_quantity 일괄 변경
            if (isset($bulkChanges['stock_quantity'])) {
                $stockChange = $bulkChanges['stock_quantity'];
                $method = $stockChange['method'] ?? 'set';
                $value = (int) ($stockChange['value'] ?? 0);

                foreach ($optionIds as $optionId) {
                    $this->applyStockChange($optionId, $method, $value);
                    $optionsUpdated++;
                }

                // 재고 변경 후 상품 재고 동기화 훅 호출
                HookManager::doAction('sirsoft-ecommerce.product_option.after_bulk_stock_update', $optionIds, count($optionIds));
            }
        }

        // 6. 개별 인라인 수정 처리 (bulk_changes 필드 제외)
        if (! empty($items)) {
            foreach ($items as $item) {
                $optionId = $item['option_id'] ?? null;
                if (! $optionId || ! in_array($optionId, $optionIds)) {
                    continue;
                }

                $updateData = $this->filterBulkFields($item, $bulkChanges);
                if (! empty($updateData)) {
                    $this->repository->update($optionId, $updateData);
                    $optionsUpdated++;
                }
            }
        }

        // 7. 옵션 재고 변경 시 상품 재고 동기화
        $hasStockChange = isset($bulkChanges['stock_quantity']);
        $hasItemStockChange = collect($items)->contains(fn ($item) => isset($item['stock_quantity']));

        if ($hasStockChange || $hasItemStockChange) {
            // product_ids 또는 ids("productId-optionId")에서 상품 ID 추출
            $affectedProductIds = collect($data['product_ids'] ?? []);

            if (! empty($data['ids'])) {
                $idsFromMixed = collect($data['ids'])->map(fn ($id) => (int) explode('-', $id)[0]);
                $affectedProductIds = $affectedProductIds->merge($idsFromMixed);
            }

            $affectedProductIds = $affectedProductIds
                ->merge(collect($items)->pluck('product_id'))
                ->unique()
                ->filter()
                ->values();

            foreach ($affectedProductIds as $productId) {
                $this->productRepository->syncStockFromOptions((int) $productId);
            }
        }

        $result = ['options_updated' => $optionsUpdated];

        // 8. after 훅 (리스너에서 변경 감지 + 로깅)
        HookManager::doAction('sirsoft-ecommerce.option.after_bulk_update', $result, $data, $snapshots);

        return $result;
    }

    /**
     * 가격 조정 적용
     *
     * @param int $optionId 옵션 ID
     * @param string $method 변경 방식 (set, add, percent)
     * @param int $value 변경 값
     * @return void
     */
    private function applyPriceAdjustment(int $optionId, string $method, int $value): void
    {
        $option = $this->repository->findById($optionId);
        if (! $option) {
            return;
        }

        $newValue = match ($method) {
            'set' => $value,
            'add' => $option->price_adjustment + $value,
            'percent' => (int) floor($option->price_adjustment * (1 + $value / 100)),
            default => $option->price_adjustment,
        };

        $this->repository->update($optionId, ['price_adjustment' => $newValue]);
    }

    /**
     * 재고 변경 적용
     *
     * @param int $optionId 옵션 ID
     * @param string $method 변경 방식 (set, add, subtract)
     * @param int $value 변경 값
     * @return void
     */
    private function applyStockChange(int $optionId, string $method, int $value): void
    {
        $option = $this->repository->findById($optionId);
        if (! $option) {
            return;
        }

        $newValue = match ($method) {
            'set' => max(0, $value),
            'add' => max(0, $option->stock_quantity + $value),
            'subtract' => max(0, $option->stock_quantity - $value),
            default => $option->stock_quantity,
        };

        $this->repository->update($optionId, ['stock_quantity' => $newValue]);
    }

    /**
     * bulk_changes에 설정된 필드를 제외한 데이터 반환
     *
     * @param array $item 개별 수정 데이터
     * @param array $bulkChanges 일괄 변경 조건
     * @return array bulk_changes 필드가 제외된 데이터
     */
    private function filterBulkFields(array $item, array $bulkChanges): array
    {
        $filtered = $item;
        unset($filtered['product_id'], $filtered['option_id']);

        // bulk_changes에 설정된 필드 제외
        if (isset($bulkChanges['price_adjustment'])) {
            unset($filtered['price_adjustment']);
        }
        if (isset($bulkChanges['stock_quantity'])) {
            unset($filtered['stock_quantity']);
        }

        return $filtered;
    }
}
