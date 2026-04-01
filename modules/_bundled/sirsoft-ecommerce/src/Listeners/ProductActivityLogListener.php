<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\ActivityLog\ChangeDetector;
use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Contracts\Extension\HookListenerInterface;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 활동 로그 리스너
 *
 * 상품의 생성, 수정, 삭제, 일괄 변경 시 활동 로그를 자동으로 기록합니다.
 * ActivityLog 표준 패턴(ResolvesActivityLogType + ChangeDetector) 사용.
 *
 * Monolog 기반 아키텍처:
 * Service → doAction → ProductActivityLogListener → Log::channel('activity') → ActivityLogHandler → DB
 */
class ProductActivityLogListener implements HookListenerInterface
{
    use ResolvesActivityLogType;

    /**
     * 구독할 훅 목록 반환
     *
     * @return array 훅 매핑 배열
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 상품 생성 후 로그
            'sirsoft-ecommerce.product.after_create' => [
                'method' => 'handleProductAfterCreate',
                'priority' => 20,
            ],
            // 수정 후 변경사항 로그
            'sirsoft-ecommerce.product.after_update' => [
                'method' => 'handleProductAfterUpdate',
                'priority' => 20,
            ],
            // 삭제 후 로그
            'sirsoft-ecommerce.product.after_delete' => [
                'method' => 'handleProductAfterDelete',
                'priority' => 20,
            ],

            // ─── Bulk 로그 기록 ───
            'sirsoft-ecommerce.product.after_bulk_update' => [
                'method' => 'handleProductAfterBulkUpdate',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.product.after_bulk_price_update' => [
                'method' => 'handleProductAfterBulkPriceUpdate',
                'priority' => 20,
            ],
            'sirsoft-ecommerce.product.after_bulk_stock_update' => [
                'method' => 'handleProductAfterBulkStockUpdate',
                'priority' => 20,
            ],

            // ─── 옵션 재고 동기화 후 상품 재고 변경 로그 ───
            'sirsoft-ecommerce.product.after_stock_sync' => [
                'method' => 'handleProductAfterStockSync',
                'priority' => 20,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param mixed ...$args 훅 인자
     * @return void
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리
    }

    // ═══════════════════════════════════════════
    // ProductService 핸들러
    // ═══════════════════════════════════════════

    /**
     * 상품 생성 후 로그 기록
     *
     * @param Product $product 생성된 상품
     */
    public function handleProductAfterCreate(Product $product): void
    {
        $this->logActivity('product.create', [
            'loggable' => $product,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_create',
            'description_params' => ['product_id' => $product->id],
            'properties' => [
                'product_code' => $product->product_code,
            ],
        ]);
    }

    /**
     * 상품 수정 후 로그 기록
     *
     * @param Product $product 수정된 상품
     * @param array|null $snapshot 수정 전 스냅샷 (Service에서 전달)
     */
    public function handleProductAfterUpdate(Product $product, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($product, $snapshot);

        $this->logActivity('product.update', [
            'loggable' => $product,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_update',
            'description_params' => ['product_id' => $product->id],
            'changes' => $changes,
        ]);
    }

    /**
     * 상품 삭제 후 로그 기록
     *
     * @param Product $product 삭제된 상품
     */
    public function handleProductAfterDelete(Product $product): void
    {
        $this->logActivity('product.delete', [
            'loggable' => $product,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_delete',
            'description_params' => ['product_id' => $product->id],
            'properties' => [
                'product_id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->getLocalizedName(),
            ],
        ]);
    }

    // ═══════════════════════════════════════════
    // Bulk 로그 기록 (after 훅, priority 20)
    // ═══════════════════════════════════════════

    /**
     * 상품 일괄 수정 후 per-item 로그 기록
     *
     * bulkUpdateStatus(): ($ids, $updatedCount, $snapshots)
     * bulkUpdate(): ($result, $data, $snapshots)
     *
     * @param mixed $idsOrResult ID 배열 또는 결과 배열
     * @param mixed $countOrData 업데이트 수 또는 원본 데이터
     * @param array $snapshots 변경 전 스냅샷 배열 (Service에서 전달)
     */
    public function handleProductAfterBulkUpdate(mixed $idsOrResult, mixed $countOrData = null, array $snapshots = []): void
    {
        // bulkUpdateStatus: ($ids, $updatedCount, $snapshots) — $ids는 순차 배열
        // bulkUpdate: ($result, $data, $snapshots) — $result는 연관 배열 (products_updated 키)
        if (is_array($idsOrResult) && isset($idsOrResult['products_updated'])) {
            $data = $countOrData;
            $ids = $data['ids'] ?? [];
        } else {
            $ids = $idsOrResult;
        }

        if (empty($ids)) {
            return;
        }

        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $snapshot = $snapshots[$productId] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($product, $snapshot) : null;

            $this->logActivity('product.bulk_update', [
                'loggable' => $product,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.product_bulk_update',
                'description_params' => ['count' => 1],
                'properties' => ['product_id' => $productId],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 상품 일괄 가격 변경 후 per-item 로그 기록
     *
     * @param array $ids 상품 ID 배열
     * @param int $updatedCount 변경된 수
     * @param array $snapshots 변경 전 스냅샷 배열 (Service에서 전달)
     */
    public function handleProductAfterBulkPriceUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $snapshot = $snapshots[$productId] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($product, $snapshot) : null;

            $this->logActivity('product.bulk_price_update', [
                'loggable' => $product,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.product_bulk_price_update',
                'description_params' => ['count' => 1],
                'properties' => ['product_id' => $productId],
                'changes' => $changes,
            ]);
        }
    }

    /**
     * 상품 일괄 재고 변경 후 per-item 로그 기록
     *
     * @param array $ids 상품 ID 배열
     * @param int $updatedCount 변경된 수
     * @param array $snapshots 변경 전 스냅샷 배열 (Service에서 전달)
     */
    public function handleProductAfterBulkStockUpdate(array $ids, int $updatedCount, array $snapshots = []): void
    {
        $products = Product::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $productId) {
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $snapshot = $snapshots[$productId] ?? null;
            $changes = $snapshot ? ChangeDetector::detect($product, $snapshot) : null;

            $this->logActivity('product.bulk_stock_update', [
                'loggable' => $product,
                'description_key' => 'sirsoft-ecommerce::activity_log.description.product_bulk_stock_update',
                'description_params' => ['count' => 1],
                'properties' => ['product_id' => $productId],
                'changes' => $changes,
            ]);
        }
    }

    // ═══════════════════════════════════════════
    // 옵션 재고 동기화 → 상품 재고 변경 로그
    // ═══════════════════════════════════════════

    /**
     * 옵션 재고 동기화로 인한 상품 재고 변경 로그 기록
     *
     * SyncProductFromOptionListener에서 재고 동기화 후 발행한 훅을 수신합니다.
     *
     * @param Product $product 재고가 변경된 상품
     * @param array|null $snapshot 변경 전 스냅샷
     * @return void
     */
    public function handleProductAfterStockSync(Product $product, ?array $snapshot = null): void
    {
        $changes = ChangeDetector::detect($product, $snapshot);

        if (! $changes) {
            return;
        }

        $this->logActivity('product.stock_sync', [
            'loggable' => $product,
            'description_key' => 'sirsoft-ecommerce::activity_log.description.product_stock_sync',
            'description_params' => ['product_id' => $product->id],
            'properties' => ['product_id' => $product->id],
            'changes' => $changes,
        ]);
    }

}
