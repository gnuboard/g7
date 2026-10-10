<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 재고 관리 서비스
 *
 * 핵심 규칙: Product.stock_quantity = SUM(ProductOption.stock_quantity)
 */
class StockService
{
    /**
     * @param  ProductOptionRepositoryInterface  $optionRepository  상품 옵션 저장소 (재고 잠금·증감)
     * @param  ProductRepositoryInterface  $productRepository  상품 저장소 (옵션 재고 합 동기화)
     * @param  OrderOptionRepositoryInterface  $orderOptionRepository  주문 옵션 저장소 (차감 플래그 정리)
     */
    public function __construct(
        protected ProductOptionRepositoryInterface $optionRepository,
        protected ProductRepositoryInterface $productRepository,
        protected OrderOptionRepositoryInterface $orderOptionRepository
    ) {}

    /**
     * 주문 옵션들의 재고 검증
     *
     * @param  array  $items  [['product_option_id' => int, 'quantity' => int], ...]
     * @return bool 재고 충분 여부
     *
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
     * @param  Order  $order  주문 모델 (options 관계 로드 필요)
     * @return void
     *
     * @throws InsufficientStockException 재고 부족 시
     */
    public function deductStock(Order $order): void
    {
        $this->deductStockForOptions($order, $order->options);
    }

    /**
     * 지정한 주문 옵션들만 재고를 차감합니다. (수동 결제 처리 — 활성 행만 대상으로 할 때, Q13)
     *
     * `deductStock()` 은 주문의 모든 행을 돌아 취소된 행까지 차감한다. 관리자가 결제 전 주문을
     * 결제완료로 올릴 때는 살아 있는 행만 빼야 하므로 대상 행을 받는다. 훅은 `deductStock()` 과 같은
     * 인수(주문)로 발화한다.
     *
     * @param  Order  $order  주문
     * @param  iterable<OrderOption>  $options  차감할 주문 옵션
     * @return void
     *
     * @throws InsufficientStockException 재고 부족 시
     */
    public function deductStockForOptions(Order $order, iterable $options): void
    {
        HookManager::doAction('sirsoft-ecommerce.stock.before_deduct', $order);

        DB::transaction(function () use ($options) {
            $productIds = [];

            foreach ($options as $orderOption) {
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

        HookManager::doAction('sirsoft-ecommerce.stock.after_deduct', $order);
    }

    /**
     * 주문 취소/환불에 대한 재고 복원
     *
     * @param  Order  $order  주문 모델 (options 관계 로드 필요)
     * @return void
     */
    public function restoreStock(Order $order): void
    {
        HookManager::doAction('sirsoft-ecommerce.stock.before_restore', $order);

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

        HookManager::doAction('sirsoft-ecommerce.stock.after_restore', $order);
    }

    /**
     * 단일 옵션 재고 차감
     *
     * @param  int  $productOptionId  상품 옵션 ID
     * @param  int  $quantity  차감 수량
     * @return bool 성공 여부
     *
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
     * @param  int  $productOptionId  상품 옵션 ID
     * @param  int  $quantity  복원 수량
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

    /**
     * 주문 취소 시 단일 옵션 재고 복원 + is_stock_deducted 플래그 정리
     *
     * **취소 전용이다.** 클레임(교환·반품) 완료의 재고 복원은 형제 `restoreOptionStockForClaim()` 이
     * 담당한다 — 아래 CANCELLED 형제 행 플래그 정리를 클레임 완료가 함께 수행하면, 재고를 복원하지
     * 않고 취소된 행까지 "복원됨" 으로 바뀌어 그 취소를 되살릴 때 재고가 이중 차감된다. 이 정리의
     * 대상 범위를 넓히기 전에 그 메서드의 docblock 을 먼저 읽을 것.
     *
     * 재고 리스너(StockRestoreListener)를 제거하고 Service 단일 경로로 복원을 일원화하면서,
     * 복원 후 플래그 리셋 주체가 사라지므로 Service 경로가 직접 플래그를 정리한다.
     *
     * 부분취소 멱등성: changeStatusWithQuantity 가 취소 수량을 CANCELLED 상태의 별도
     * OrderOption 행으로 분할하므로, 해당 product_option_id 의 CANCELLED 행(아직
     * is_stock_deducted=true) 만 false 로 정리한다. 잔여 미취소 행은 그대로 둔다.
     *
     * @param  OrderOption  $orderOption  취소 대상 주문 옵션 (분할 전 원본 — product_option_id 기준)
     * @param  int  $cancelQuantity  복원할 취소 수량
     * @return bool 복원 성공 여부
     */
    public function restoreOptionStockForOrderOption(OrderOption $orderOption, int $cancelQuantity): bool
    {
        return DB::transaction(function () use ($orderOption, $cancelQuantity) {
            $result = $this->optionRepository->incrementStock(
                $orderOption->product_option_id,
                $cancelQuantity
            );

            // 취소(복원)된 재고를 담는 CANCELLED 상태 행의 차감 플래그를 정리한다.
            // 재활성(재차감) 시 deductStock 이 이 행을 재차감 대상으로 인식하게 한다.
            $this->orderOptionRepository->clearStockDeductedForCancelledOptions(
                $orderOption->order_id,
                $orderOption->product_option_id
            );

            $option = $this->optionRepository->findById($orderOption->product_option_id);
            if ($option) {
                $this->productRepository->syncStockFromOptions($option->product_id);
            }

            return $result;
        });
    }

    /**
     * 클레임(교환·반품) 완료 시 단일 옵션 재고 복원
     *
     * 형제 메서드 `restoreOptionStockForOrderOption()` 과 나뉘는 지점은 **CANCELLED 형제
     * 행의 플래그 정리 여부** 하나다. 그쪽은 취소 전용이라 같은 상품옵션의 CANCELLED 행을
     * 전부 `is_stock_deducted=false` 로 내리는데, 그 정리는 "이번에 복원된 재고를 담는 행"
     * 을 겨냥한 것이다.
     *
     * 클레임 완료가 그 정리를 함께 수행하면 **재고를 복원하지 않고 취소된 행**(`재고 복구
     * 안 함` 선택)의 플래그까지 내려간다. 그 행은 여전히 재고를 점유하고 있으므로,
     * 이후 그 취소를 되살리면 `redeductForReactivation()` 이 복원된 적 없는 재고를 다시
     * 차감해 재고가 실제보다 줄어든다 — 예외도 로그도 남지 않는다.
     *
     * 클레임 몫 행의 플래그는 호출부(클레임 서비스)가 자기 행에만 직접 내린다.
     *
     * @param  OrderOption  $orderOption  복원 대상 주문 옵션 (코스 상태로 전이된 행)
     * @param  int  $quantity  복원 수량
     * @return bool 복원 성공 여부
     */
    public function restoreOptionStockForClaim(OrderOption $orderOption, int $quantity): bool
    {
        return DB::transaction(function () use ($orderOption, $quantity) {
            $result = $this->optionRepository->incrementStock(
                $orderOption->product_option_id,
                $quantity
            );

            $option = $this->optionRepository->findById($orderOption->product_option_id);
            if ($option) {
                $this->productRepository->syncStockFromOptions($option->product_id);
            }

            return $result;
        });
    }

    /**
     * 취소 → 판매 상태 복원 시 재고 재차감
     *
     * 관리자가 취소된 주문을 다시 판매 상태(payment_complete~confirmed)로 되돌리면,
     * 취소 시 복원되었던 재고(is_stock_deducted=false 인 CANCELLED 옵션)를 재차감한다.
     * stock_restore_on_cancel 설정과 무관하게 "복원된 재고를 되돌리는" 역연산이며,
     * 복원되지 않은(여전히 is_stock_deducted=true) 옵션은 deductStock 의 멱등 가드로 자동 스킵된다.
     *
     * 재고 부족 시 InsufficientStockException 을 던져 호출부의 트랜잭션 롤백(상태 복원 차단)을 유도한다.
     *
     * @param  Order  $order  재활성 대상 주문 (options 관계 로드 필요)
     * @return void
     *
     * @throws InsufficientStockException 재고 부족 시
     */
    public function redeductForReactivation(Order $order): void
    {
        // deductStock 이 is_stock_deducted=false 옵션만 quantity 만큼 재차감 (멱등 가드 재사용).
        // 재고 부족 시 InsufficientStockException 전파 → 호출부 트랜잭션 롤백.
        $this->deductStock($order);
    }

    /**
     * 단일 주문 옵션 단위의 취소 → 판매 상태 복원 재차감
     *
     * 관리자가 주문 상세의 옵션(라인) 단위 상태 에디터로 취소된 옵션을 판매 상태로
     * 되돌리는 경로(OrderOptionService::changeStatusWithQuantity)를 위한 재차감이다.
     * 주문 전체를 순회하는 redeductForReactivation 과 달리 지정한 OrderOption 1건만 재차감한다.
     *
     * 이미 차감된(is_stock_deducted=true) 옵션은 멱등 가드로 스킵하므로, 복원되지 않은
     * (설정 OFF 등으로 여전히 차감 상태인) 옵션을 되살려도 이중 차감되지 않는다.
     *
     * 재고 부족 시 InsufficientStockException 을 던져 호출부의 트랜잭션 롤백(상태 복원 차단)을 유도한다.
     *
     * @param  OrderOption  $orderOption  재차감 대상 주문 옵션 (취소로 복원되어 is_stock_deducted=false 인 행)
     * @return void
     *
     * @throws InsufficientStockException 재고 부족 시
     */
    public function redeductOrderOptionForReactivation(OrderOption $orderOption): void
    {
        // 이미 차감된 옵션은 멱등 가드로 스킵 (이중 차감 방지)
        if ($orderOption->is_stock_deducted) {
            return;
        }

        DB::transaction(function () use ($orderOption) {
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

            // 재고 재차감 + 플래그 복귀
            $this->optionRepository->decrementStock($option->id, $orderOption->quantity);
            $orderOption->update(['is_stock_deducted' => true]);

            // 상품 재고 동기화
            $this->productRepository->syncStockFromOptions($option->product_id);
        });
    }
}
