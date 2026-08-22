<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 옵션 변경 후 상품 재고 자동 동기화 리스너
 *
 * 옵션의 재고 변경 시 상품의 재고를 자동으로 동기화합니다.
 * - 재고: 모든 옵션의 재고 합계로 상품 재고 설정
 * - 동기화 후 product.after_stock_sync 훅을 발행하여 활동 로그 리스너에 위임
 *
 * 주의: 옵션 가격 변경 시 상품 판매가 동기화는 비활성화됨
 * (옵션은 price_adjustment(조정액)만 관리하고, 상품 판매가는 별도로 관리)
 */
class SyncProductFromOptionListener implements HookListenerInterface
{
    /**
     * @param  ProductRepositoryInterface  $productRepository  상품 키 맵 조회/재고 갱신
     * @param  ProductOptionRepositoryInterface  $productOptionRepository  옵션 ID → product_id, stock 합계
     */
    public function __construct(
        protected ProductRepositoryInterface $productRepository,
        protected ProductOptionRepositoryInterface $productOptionRepository,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * 옵션 가격 변경 시 상품 판매가 동기화는 비활성화
     * (옵션은 조정액만 관리하고, 상품 판매가는 별도로 관리)
     *
     * @return array
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 옵션 재고 일괄 변경 후 상품 재고 동기화
            'sirsoft-ecommerce.product_option.after_bulk_stock_update' => [
                'method' => 'syncProductStockFromOptions',
                'priority' => 10,
            ],
            // 옵션 통합 일괄 수정 후 상품 재고 동기화 (개별 items 경로)
            'sirsoft-ecommerce.option.after_bulk_update' => [
                'method' => 'syncProductStockFromBulkUpdate',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * getSubscribedHooks()에서 개별 메서드를 지정하므로,
     * 이 메서드는 호출되지 않지만 인터페이스 준수를 위해 구현
     *
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 옵션 재고 합계로 상품 재고 동기화
     *
     * 재고 동기화 후 product.after_stock_sync 훅을 발행하여
     * 활동 로그 기록을 ProductActivityLogListener에 위임합니다.
     *
     * @param array $optionIds 변경된 옵션 ID 배열
     * @param int $updatedCount 업데이트된 옵션 수
     * @return void
     */
    public function syncProductStockFromOptions(array $optionIds, int $updatedCount): void
    {
        if (empty($optionIds)) {
            return;
        }

        try {
            // 1. 변경된 옵션들의 상품 ID 추출
            $productIds = $this->productOptionRepository->pluckProductIds($optionIds);

            // 2. 각 상품의 변경 전 스냅샷 캡처
            $products = $this->productRepository->findByIdsKeyed($productIds);
            $snapshots = $products->map->toArray()->all();

            // 3. 각 상품의 옵션 재고 합계로 상품 재고 업데이트
            foreach ($productIds as $productId) {
                $totalStock = $this->productOptionRepository->sumStockByProduct((int) $productId);
                $this->productRepository->updateStockQuantity((int) $productId, $totalStock);
            }

            // 4. 변경된 상품별로 훅 발행 → ProductActivityLogListener에서 로그 기록
            $freshProducts = $this->productRepository->findByIdsKeyed($productIds);
            foreach ($productIds as $productId) {
                $product = $freshProducts->get($productId);
                $snapshot = $snapshots[$productId] ?? null;

                if ($product && $snapshot) {
                    HookManager::doAction(
                        'sirsoft-ecommerce.product.after_stock_sync',
                        $product,
                        $snapshot
                    );
                }
            }

            Log::info('SyncProductFromOptionListener: 상품 재고 동기화 완료', [
                'product_count' => count($productIds),
                'option_count' => $updatedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('SyncProductFromOptionListener: 상품 재고 동기화 실패', [
                'error' => $e->getMessage(),
                'option_ids' => $optionIds,
            ]);
        }
    }

    /**
     * 옵션 통합 일괄 수정 후 상품 재고 동기화
     *
     * bulkUpdate()의 개별 items 경로에서 재고가 변경된 경우,
     * productRepository->syncStockFromOptions()가 이미 DB를 갱신했으므로
     * 여기서는 스냅샷 대비 변경 감지 후 훅을 발행합니다.
     *
     * @param array $result 업데이트 결과
     * @param array $data 원본 요청 데이터
     * @param array $snapshots 옵션 스냅샷 (id => snapshot)
     * @return void
     */
    public function syncProductStockFromBulkUpdate(array $result, array $data, array $snapshots = []): void
    {
        // 재고 변경 여부 확인
        $bulkChanges = $data['bulk_changes'] ?? [];
        $items = $data['items'] ?? [];

        $hasStockChange = isset($bulkChanges['stock_quantity']);
        $hasItemStockChange = collect($items)->contains(fn ($item) => isset($item['stock_quantity']));

        if (! $hasStockChange && ! $hasItemStockChange) {
            return;
        }

        // 영향받은 상품 ID 추출
        $affectedProductIds = collect($data['product_ids'] ?? []);

        if (! empty($data['ids'])) {
            $idsFromMixed = collect($data['ids'])->map(fn ($id) => (int) explode('-', $id)[0]);
            $affectedProductIds = $affectedProductIds->merge($idsFromMixed);
        }

        $affectedProductIds = $affectedProductIds
            ->merge(collect($items)->pluck('product_id'))
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        if (empty($affectedProductIds)) {
            return;
        }

        try {
            // 현재 상품 상태 조회 (syncStockFromOptions이 이미 DB 갱신 완료)
            $products = $this->productRepository->findByIdsKeyed($affectedProductIds);

            // 옵션 스냅샷에서 변경 전 상품 재고 복원 → 비교 → 훅 발행
            foreach ($affectedProductIds as $productId) {
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }

                // 옵션 스냅샷에서 해당 상품의 변경 전 옵션 재고 합계 계산
                $oldTotalStock = 0;
                $hasSnapshot = false;
                foreach ($snapshots as $optionId => $snapshot) {
                    if (($snapshot['product_id'] ?? null) == $productId) {
                        $oldTotalStock += (int) ($snapshot['stock_quantity'] ?? 0);
                        $hasSnapshot = true;
                    }
                }

                if (! $hasSnapshot) {
                    continue;
                }

                // 현재 옵션에서 스냅샷에 없는 옵션의 재고도 합산
                $snapshotOptionIds = array_keys($snapshots);
                $otherOptionsStock = $this->productOptionRepository
                    ->sumStockByProductExcluding((int) $productId, $snapshotOptionIds);
                $oldTotalStock += $otherOptionsStock;

                $currentStock = (int) $product->stock_quantity;

                if ($oldTotalStock !== $currentStock) {
                    // 가상 스냅샷으로 훅 발행
                    $productSnapshot = $product->toArray();
                    $productSnapshot['stock_quantity'] = $oldTotalStock;

                    HookManager::doAction(
                        'sirsoft-ecommerce.product.after_stock_sync',
                        $product,
                        $productSnapshot
                    );
                }
            }
        } catch (\Exception $e) {
            Log::error('SyncProductFromOptionListener: 통합 일괄 수정 재고 동기화 실패', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
