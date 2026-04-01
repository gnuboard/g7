<?php

namespace Modules\Sirsoft\Ecommerce\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\ProductService;

/**
 * 옵션 변경 후 option_groups 자동 동기화 리스너
 *
 * 옵션 테이블의 변경 사항을 상품의 option_groups 컬럼에 자동 동기화합니다.
 * - 옵션 생성/수정/삭제 시 option_values를 기반으로 option_groups 역산
 */
class SyncOptionGroupsListener implements HookListenerInterface
{
    public function __construct(
        protected ProductService $productService
    ) {}

    /**
     * 구독할 훅 목록 반환
     */
    public static function getSubscribedHooks(): array
    {
        return [
            // 옵션 동기화 완료 후 (생성/수정/삭제 포함)
            'sirsoft-ecommerce.product.after_options_sync' => [
                'method' => 'syncOptionGroupsFromOptions',
                'priority' => 10,
            ],
            // 옵션 일괄 업데이트 후 (기존 훅 활용)
            'sirsoft-ecommerce.option.after_bulk_update' => [
                'method' => 'syncOptionGroupsFromBulkUpdate',
                'priority' => 10,
            ],
        ];
    }

    /**
     * 기본 훅 핸들러 (HookListenerInterface 필수 메서드)
     *
     * @param  mixed  ...$args  훅 인자
     */
    public function handle(...$args): void
    {
        // 개별 메서드에서 처리하므로 빈 구현
    }

    /**
     * 옵션 동기화 후 option_groups 재생성
     *
     * @param  Product  $product  상품 모델
     * @param  int  $created  생성된 옵션 수
     * @param  int  $updated  수정된 옵션 수
     * @param  int  $deleted  삭제된 옵션 수
     */
    public function syncOptionGroupsFromOptions(Product $product, int $created, int $updated, int $deleted): void
    {
        if ($created > 0 || $updated > 0 || $deleted > 0) {
            try {
                $this->productService->rebuildOptionGroups($product);
                Log::info('SyncOptionGroupsListener: option_groups 동기화 완료 (옵션 동기화)', [
                    'product_id' => $product->id,
                    'created' => $created,
                    'updated' => $updated,
                    'deleted' => $deleted,
                ]);
            } catch (\Exception $e) {
                Log::error('SyncOptionGroupsListener: option_groups 동기화 실패', [
                    'error' => $e->getMessage(),
                    'product_id' => $product->id,
                ]);
            }
        }
    }

    /**
     * 일괄 업데이트 후 option_groups 동기화
     *
     * 기존 훅 sirsoft-ecommerce.option.after_bulk_update 활용
     *
     * @param  array  $result  업데이트 결과 (options_updated)
     * @param  array  $data  업데이트 데이터 (product_ids/ids, bulk_changes, items)
     */
    public function syncOptionGroupsFromBulkUpdate(array $result, array $data): void
    {
        // option_values가 변경된 경우에만 동기화
        $bulkChanges = $data['bulk_changes'] ?? [];
        $items = $data['items'] ?? [];

        // bulk_changes에서 option_values 변경 여부 확인
        $hasOptionValuesChange = isset($bulkChanges['option_values']);

        // items에서 option_values 변경 여부 확인
        if (! $hasOptionValuesChange) {
            foreach ($items as $item) {
                if (isset($item['option_values'])) {
                    $hasOptionValuesChange = true;
                    break;
                }
            }
        }

        // option_values 변경이 없으면 동기화 불필요
        if (! $hasOptionValuesChange) {
            return;
        }

        // 상품 ID 추출
        $productIds = [];
        if (! empty($data['product_ids'])) {
            $productIds = $data['product_ids'];
        } elseif (! empty($data['ids'])) {
            $productIds = ProductOption::whereIn('id', $data['ids'])
                ->pluck('product_id')
                ->unique()
                ->toArray();
        }

        foreach ($productIds as $productId) {
            $product = Product::find($productId);
            if ($product) {
                try {
                    $this->productService->rebuildOptionGroups($product);
                    Log::info('SyncOptionGroupsListener: option_groups 동기화 완료 (일괄 업데이트)', [
                        'product_id' => $productId,
                    ]);
                } catch (\Exception $e) {
                    Log::error('SyncOptionGroupsListener: option_groups 동기화 실패', [
                        'error' => $e->getMessage(),
                        'product_id' => $productId,
                    ]);
                }
            }
        }
    }
}
