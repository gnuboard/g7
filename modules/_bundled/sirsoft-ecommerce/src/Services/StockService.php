<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 재고 관리 서비스
 *
 * 핵심 규칙: Product.stock_quantity = SUM(ProductOption.stock_quantity)
 */
class StockService
{
    public function __construct(
        protected ProductOptionRepositoryInterface $optionRepository,
        protected ProductRepositoryInterface $productRepository
    ) {}

    /**
     * 주문 옵션들의 재고 검증
     *
     * @param array $items [['product_option_id' => int, 'quantity' => int], ...]
     * @return bool 재고 충분 여부
     * @throws InsufficientStockException 재고 부족 시
     */
    public function validateStock(array $items): bool
    {
        foreach ($items as $item) {
            $option = $this->optionRepository->findById($item['product_option_id']);

            if (! $option) {
                throw new InsufficientStockException(
                    __('sirsoft-ecommerce::messages.stock.option_not_found', [
                        'option_id' => $item['product_option_id'],
                    ])
                );
            }

            if ($option->stock_quantity < $item['quantity']) {
                throw new InsufficientStockException(
                    __('sirsoft-ecommerce::messages.stock.insufficient', [
                        'product_name' => $option->product->getLocalizedName(),
                        'option_name' => $option->getLocalizedOptionName(),
                        'available' => $option->stock_quantity,
                        'requested' => $item['quantity'],
                    ])
                );
            }
        }

        return true;
    }

    /**
     * 주문에 대한 재고 차감
     *
     * @param Order $order 주문 모델 (options 관계 로드 필요)
     * @return void
     * @throws InsufficientStockException 재고 부족 시
     */
    public function deductStock(Order $order): void
    {
        \App\Extension\HookManager::doAction('sirsoft-ecommerce.stock.before_deduct', $order);

        DB::transaction(function () use ($order) {
            $productIds = [];

            foreach ($order->options as $orderOption) {
                // 이미 차감된 옵션 스킵 (멱등성 보장)
                if ($orderOption->is_stock_deducted) {
                    continue;
                }

                // 배타적 락으로 옵션 조회
                $option = $this->optionRepository->findWithLock($orderOption->product_option_id);

                if (! $option) {
                    throw new InsufficientStockException(
                        __('sirsoft-ecommerce::messages.stock.option_not_found', [
                            'option_id' => $orderOption->product_option_id,
                        ])
                    );
                }

                if ($option->stock_quantity < $orderOption->quantity) {
                    throw new InsufficientStockException(
                        __('sirsoft-ecommerce::messages.stock.insufficient', [
                            'product_name' => $option->product->getLocalizedName(),
                            'option_name' => $option->getLocalizedOptionName(),
                            'available' => $option->stock_quantity,
                            'requested' => $orderOption->quantity,
                        ])
                    );
                }

                // 재고 차감
                $this->optionRepository->decrementStock($option->id, $orderOption->quantity);
                $orderOption->update(['is_stock_deducted' => true]);
                $productIds[] = $option->product_id;
            }

            // 상품 재고 동기화 (중복 제거 후)
            foreach (array_unique($productIds) as $productId) {
                $this->productRepository->syncStockFromOptions($productId);
            }
        });

        \App\Extension\HookManager::doAction('sirsoft-ecommerce.stock.after_deduct', $order);
    }

    /**
     * 주문 취소/환불에 대한 재고 복원
     *
     * @param Order $order 주문 모델 (options 관계 로드 필요)
     * @return void
     */
    public function restoreStock(Order $order): void
    {
        \App\Extension\HookManager::doAction('sirsoft-ecommerce.stock.before_restore', $order);

        DB::transaction(function () use ($order) {
            $productIds = [];

            foreach ($order->options as $orderOption) {
                // 차감되지 않은 옵션은 복원 스킵
                if (! $orderOption->is_stock_deducted) {
                    continue;
                }

                // 재고 복원
                $this->optionRepository->incrementStock(
                    $orderOption->product_option_id,
                    $orderOption->quantity
                );
                $orderOption->update(['is_stock_deducted' => false]);

                // product_id 수집
                $option = $this->optionRepository->findById($orderOption->product_option_id);
                if ($option) {
                    $productIds[] = $option->product_id;
                }
            }

            // 상품 재고 동기화 (중복 제거 후)
            foreach (array_unique($productIds) as $productId) {
                $this->productRepository->syncStockFromOptions($productId);
            }
        });

        \App\Extension\HookManager::doAction('sirsoft-ecommerce.stock.after_restore', $order);
    }

    /**
     * 단일 옵션 재고 차감
     *
     * @param int $productOptionId 상품 옵션 ID
     * @param int $quantity 차감 수량
     * @return bool 성공 여부
     * @throws InsufficientStockException 재고 부족 시
     */
    public function deductOptionStock(int $productOptionId, int $quantity): bool
    {
        return DB::transaction(function () use ($productOptionId, $quantity) {
            $option = $this->optionRepository->findWithLock($productOptionId);

            if (! $option) {
                throw new InsufficientStockException(
                    __('sirsoft-ecommerce::messages.stock.option_not_found', [
                        'option_id' => $productOptionId,
                    ])
                );
            }

            if ($option->stock_quantity < $quantity) {
                throw new InsufficientStockException(
                    __('sirsoft-ecommerce::messages.stock.insufficient', [
                        'product_name' => $option->product->getLocalizedName(),
                        'option_name' => $option->getLocalizedOptionName(),
                        'available' => $option->stock_quantity,
                        'requested' => $quantity,
                    ])
                );
            }

            $result = $this->optionRepository->decrementStock($productOptionId, $quantity);
            $this->productRepository->syncStockFromOptions($option->product_id);

            return $result;
        });
    }

    /**
     * 단일 옵션 재고 복원
     *
     * @param int $productOptionId 상품 옵션 ID
     * @param int $quantity 복원 수량
     * @return bool 성공 여부
     */
    public function restoreOptionStock(int $productOptionId, int $quantity): bool
    {
        return DB::transaction(function () use ($productOptionId, $quantity) {
            $result = $this->optionRepository->incrementStock($productOptionId, $quantity);

            $option = $this->optionRepository->findById($productOptionId);
            if ($option) {
                $this->productRepository->syncStockFromOptions($option->product_id);
            }

            return $result;
        });
    }
}
