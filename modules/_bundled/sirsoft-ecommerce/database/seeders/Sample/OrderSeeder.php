<?php

namespace Modules\Sirsoft\Ecommerce\Database\Seeders\Sample;

use App\Models\User;
use App\Traits\HasSeederCounts;
use Illuminate\Database\Seeder;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueRecordStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\SequenceService;

/**
 * 주문 더미 데이터 시더
 */
class OrderSeeder extends Seeder
{
    use HasSeederCounts;

    /**
     * 주문 생성 중 소진된 쿠폰 기록 [['user_id' => int, 'coupon_id' => int], ...]
     *
     * @var array<int, array{user_id: int, coupon_id: int}>
     */
    private array $usedCouponRecords = [];

    /**
     * 생성할 기본 주문 수
     */
    private const ORDER_COUNT = 100;

    /**
     * 상태별 최소 주문 수
     */
    private const MIN_PER_STATUS = 5;

    /**
     * 비회원 주문 비율 (%)
     */
    private const GUEST_ORDER_PERCENTAGE = 20;

    /**
     * 쿠폰 적용 주문 비율 (%)
     */
    private const COUPON_ORDER_PERCENTAGE = 40;

    /**
     * 주문 상태별 비율 (합계 100)
     */
    private array $statusDistribution = [
        'pending_order' => 3,
        'pending_payment' => 7,
        'payment_complete' => 20,
        'shipping_hold' => 2,
        'preparing' => 15,
        'shipping_ready' => 5,
        'shipping' => 13,
        'delivered' => 20,
        'confirmed' => 10,
        'cancelled' => 5,
    ];

    /**
     * 쿠폰 우선 배정 상태 (이 상태들을 먼저 생성하여 쿠폰 확보)
     */
    private const COUPON_PRIORITY_STATUSES = [
        'payment_complete', 'preparing', 'shipping_ready', 'shipping', 'delivered', 'confirmed',
    ];

    /**
     * 시더 실행
     */
    public function run(): void
    {
        $this->command->info('주문 더미 데이터 생성을 시작합니다.');

        $this->deleteExistingOrders();
        $this->createOrders();
        $this->replenishAvailableCoupons();

        $count = Order::count();
        $this->command->info("주문 더미 데이터 {$count}건이 성공적으로 생성되었습니다.");
    }

    /**
     * 기존 주문 삭제
     */
    private function deleteExistingOrders(): void
    {
        $deletedCount = Order::withTrashed()->count();

        if ($deletedCount > 0) {
            // 쿠폰 발급 상태 복원 (주문 삭제 전에 실행 — order_id FK가 ON DELETE SET NULL이므로)
            // used 상태인 모든 쿠폰을 available로 복원 (고아 상태 포함)
            $resetCount = CouponIssue::where('status', CouponIssueRecordStatus::USED)
                ->update([
                    'status' => CouponIssueRecordStatus::AVAILABLE,
                    'used_at' => null,
                    'order_id' => null,
                    'discount_amount' => null,
                ]);

            // 관련 데이터 삭제 (역순으로)
            OrderShipping::query()->delete();
            OrderPayment::query()->delete();
            OrderAddress::query()->delete();
            OrderOption::query()->delete();
            Order::withTrashed()->forceDelete();

            $this->command->warn("기존 주문 관련 데이터 {$deletedCount}건을 삭제했습니다. (쿠폰 {$resetCount}건 복원)");
        }
    }

    /**
     * 주문 생성
     */
    private function createOrders(): void
    {
        // 사용자 목록 가져오기 (없으면 생성)
        $users = User::take(10)->get();
        if ($users->isEmpty()) {
            $this->command->line('  - 사용자가 없어 10명 생성합니다.');
            $users = User::factory()->count(10)->create();
        }

        // 상품 옵션 목록 가져오기 (없으면 경고)
        $productOptions = ProductOption::with('product')->take(100)->get();
        if ($productOptions->isEmpty()) {
            $this->command->warn('상품 옵션이 없습니다. ProductSeeder를 먼저 실행하는 것을 권장합니다.');
            $this->command->warn('상품 없이 주문을 생성합니다.');
        }

        // 상태별 주문 수 계산
        $orderCount = $this->getSeederCount('orders', self::ORDER_COUNT);
        $statusCounts = $this->calculateStatusCounts($orderCount);

        $createdCount = 0;
        $progressBar = $this->command->getOutput()->createProgressBar($orderCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');

        // 사용자별 사용 가능한 쿠폰 발급 목록 캐시
        $availableCouponIssuesByUser = $this->loadAvailableCouponIssuesByUser();
        $couponCount = 0;

        $guestCount = 0;

        // 쿠폰 우선 배정: 쿠폰이 필요한 상태를 먼저 생성하여 쿠폰 재고 확보
        $priorityStatuses = [];
        $normalStatuses = [];
        foreach ($statusCounts as $status => $count) {
            if (in_array($status, self::COUPON_PRIORITY_STATUSES)) {
                $priorityStatuses[$status] = $count;
            } else {
                $normalStatuses[$status] = $count;
            }
        }
        $orderedStatusCounts = array_merge($priorityStatuses, $normalStatuses);

        foreach ($orderedStatusCounts as $status => $count) {
            for ($i = 0; $i < $count; $i++) {
                // 비회원 주문 비율 적용 (쿠폰 우선 상태는 비회원 비율 절반으로)
                $guestChance = in_array($status, self::COUPON_PRIORITY_STATUSES)
                    ? (int) (self::GUEST_ORDER_PERCENTAGE / 2)
                    : self::GUEST_ORDER_PERCENTAGE;
                $isGuest = rand(1, 100) <= $guestChance;
                $user = $isGuest ? null : $users->random();

                // 쿠폰 적용 여부 결정 (회원 주문 + 취소 아닌 경우만)
                // 쿠폰 우선 상태는 적용 비율 80%로 상향
                $couponChance = in_array($status, self::COUPON_PRIORITY_STATUSES)
                    ? 80
                    : self::COUPON_ORDER_PERCENTAGE;
                $applyCoupon = ! $isGuest
                    && $status !== 'cancelled'
                    && rand(1, 100) <= $couponChance
                    && isset($availableCouponIssuesByUser[$user?->id]);

                $this->createOrder(
                    $user,
                    OrderStatusEnum::from($status),
                    $productOptions,
                    $applyCoupon ? $availableCouponIssuesByUser[$user->id] : null,
                );
                if ($applyCoupon) {
                    $couponCount++;
                }
                if ($isGuest) {
                    $guestCount++;
                }
                $createdCount++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->newLine();
        $this->command->info("  - 회원 주문: ".($createdCount - $guestCount)."건, 비회원 주문: {$guestCount}건, 쿠폰 적용: {$couponCount}건");
    }

    /**
     * 상태별 주문 수 계산
     *
     * @param  int  $orderCount  총 주문 수
     * @return array<string, int>
     */
    private function calculateStatusCounts(int $orderCount): array
    {
        $counts = [];
        $total = 0;

        foreach ($this->statusDistribution as $status => $percentage) {
            $count = max(self::MIN_PER_STATUS, (int) round($orderCount * $percentage / 100));
            $counts[$status] = $count;
            $total += $count;
        }

        // 최소 보장으로 총 수가 늘어났으면 orderCount 자체를 확장
        // (비율 기반 상태에서 줄이지 않고, 늘어난 총합을 그대로 사용)
        if ($total > $orderCount) {
            return $counts;
        }

        // 반올림으로 인한 오차 보정 (delivered에 추가 — 가장 비율 높은 상태)
        if ($total < $orderCount) {
            $counts['delivered'] += $orderCount - $total;
        }

        return $counts;
    }

    /**
     * 단일 주문 생성
     *
     * @param  User|null  $user  사용자 (null이면 비회원 주문)
     * @param  OrderStatusEnum  $status  주문 상태
     * @param  \Illuminate\Support\Collection  $productOptions  상품 옵션 목록
     * @param  \Illuminate\Support\Collection|null  $userCouponIssues  사용자의 사용 가능 쿠폰 발급 목록
     */
    private function createOrder(
        ?User $user,
        OrderStatusEnum $status,
        $productOptions,
        $userCouponIssues = null,
    ): void {
        // 주문 날짜 (상태에 따라 다르게 설정)
        $orderedAt = $this->getOrderedAtByStatus($status);

        // 주문 기본 정보 생성
        $order = $this->createOrderByStatus($user, $status, $orderedAt);

        // 주문 옵션 생성 (1~3개 랜덤)
        $optionCount = rand(1, 3);
        $orderOptions = $this->createOrderOptions($order, $productOptions, $optionCount, $status);

        // 쿠폰 적용 (쿠폰 발급 목록이 있는 경우)
        $pendingShippingCoupons = [];
        if ($userCouponIssues !== null && $orderOptions->isNotEmpty()) {
            $pendingShippingCoupons = $this->applyCouponsToOrderOptions($order, $orderOptions, $userCouponIssues);
        }

        // 배송지 주소 생성
        $this->createOrderAddress($order);

        // 배송 정보 생성 (결제 완료 이상 상태)
        $hasShipping = ! $status->isBeforeShipping() || $status === OrderStatusEnum::PREPARING;
        if ($hasShipping) {
            $this->createOrderShippings($order, $orderOptions, $status);
        }

        // 배송비 쿠폰 적용 (배송 레코드 생성 후)
        if (! empty($pendingShippingCoupons) && $hasShipping) {
            $this->applyShippingCouponsToShippings($order, $pendingShippingCoupons);
        }

        // 주문 금액 재계산 (배송 생성 + 배송비 쿠폰 적용 후)
        $this->recalculateOrderAmount($order, $orderOptions);

        // 결제 정보 생성 (결제 대기 이상 상태)
        if (! $status->isBeforePayment()) {
            $this->createOrderPayment($order, $status);
        }
    }

    /**
     * 상태에 따른 주문일 반환
     *
     * @param  OrderStatusEnum  $status
     * @return \Carbon\Carbon
     */
    private function getOrderedAtByStatus(OrderStatusEnum $status): \Carbon\Carbon
    {
        return match ($status) {
            OrderStatusEnum::PENDING_ORDER, OrderStatusEnum::PENDING_PAYMENT => now()->subHours(rand(1, 48)),
            OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::SHIPPING_HOLD, OrderStatusEnum::PREPARING => now()->subDays(rand(1, 3)),
            OrderStatusEnum::SHIPPING_READY, OrderStatusEnum::SHIPPING => now()->subDays(rand(2, 5)),
            OrderStatusEnum::DELIVERED => now()->subDays(rand(5, 10)),
            OrderStatusEnum::CONFIRMED => now()->subDays(rand(10, 30)),
            OrderStatusEnum::CANCELLED => now()->subDays(rand(1, 14)),
            default => now(),
        };
    }

    /**
     * 상태에 따라 주문 생성
     *
     * @param  User|null  $user  사용자 (null이면 비회원 주문)
     * @param  OrderStatusEnum  $status
     * @param  \Carbon\Carbon  $orderedAt
     * @return Order
     */
    private function createOrderByStatus(
        ?User $user,
        OrderStatusEnum $status,
        $orderedAt,
    ): Order {
        $factory = $user
            ? OrderFactory::new()->forUser($user)
            : OrderFactory::new()->forGuest();

        // 상태별 Factory 적용
        $factory = match ($status) {
            OrderStatusEnum::PENDING_ORDER, OrderStatusEnum::PENDING_PAYMENT => $factory->pendingPayment(),
            OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::SHIPPING_HOLD, OrderStatusEnum::PREPARING, OrderStatusEnum::SHIPPING_READY => $factory->paid(),
            OrderStatusEnum::SHIPPING => $factory->shipping(),
            OrderStatusEnum::DELIVERED => $factory->delivered(),
            OrderStatusEnum::CONFIRMED => $factory->confirmed(),
            OrderStatusEnum::CANCELLED => $factory->cancelled(),
        };

        // 모바일 주문 30% 확률
        if (rand(1, 100) <= 30) {
            $factory = $factory->mobile();
        }

        // 시퀀스 서비스로 주문번호 생성 (프로덕션과 동일한 로직)
        $orderNumber = app(SequenceService::class)->generateCode(SequenceType::ORDER);

        return $factory->create([
            'order_number' => $orderNumber,
            'order_status' => $status,
            'ordered_at' => $orderedAt,
            'currency_snapshot' => $this->buildCurrencySnapshot(),
        ]);
    }

    /**
     * 주문 옵션 생성
     *
     * @param  Order  $order
     * @param  \Illuminate\Support\Collection  $productOptions
     * @param  int  $count
     * @param  OrderStatusEnum  $orderStatus
     * @return \Illuminate\Support\Collection
     */
    private function createOrderOptions(
        Order $order,
        $productOptions,
        int $count,
        OrderStatusEnum $orderStatus
    ): \Illuminate\Support\Collection {
        $orderOptions = collect();

        // 실제 상품 옵션이 있으면 사용
        if ($productOptions->isNotEmpty()) {
            $selectedOptions = $productOptions->random(min($count, $productOptions->count()));

            foreach ($selectedOptions as $productOption) {
                $quantity = rand(1, 3);
                $unitPrice = $productOption->product->selling_price + ($productOption->price_adjustment ?? 0);
                $subtotalPrice = $quantity * $unitPrice;

                // 할인은 쿠폰/코드 미적용 시 0 (출처 없는 유령 할인 방지)
                $discountAmount = 0;

                $optionStatus = $this->getOrderOptionStatusByOrderStatus($orderStatus);

                // 다국어 원본 직접 저장
                $optionNameI18n = $productOption->option_name ?? [];
                $optionValueI18n = $this->buildMultilingualOptionValue($productOption->option_values);

                $product = $productOption->product;
                $productNameJson = is_array($product->name) ? $product->name : ['ko' => $product->getLocalizedName(), 'en' => $product->getLocalizedName()];
                $finalAmount = $subtotalPrice - $discountAmount;

                $orderOption = OrderOption::create([
                    'order_id' => $order->id,
                    'product_id' => $productOption->product_id,
                    'product_option_id' => $productOption->id,
                    'option_status' => $optionStatus,
                    'source_type' => 'order',
                    'sku' => $productOption->option_code,
                    'product_name' => $productNameJson,
                    'product_option_name' => $optionNameI18n,
                    'option_name' => $optionNameI18n,
                    'option_value' => $optionValueI18n,
                    'quantity' => $quantity,
                    'unit_weight' => $product->weight ?? 0.5,
                    'unit_volume' => 0.01,
                    'subtotal_weight' => ($product->weight ?? 0.5) * $quantity,
                    'subtotal_volume' => 0.01 * $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal_price' => $subtotalPrice,
                    'subtotal_discount_amount' => $discountAmount,
                    'product_coupon_discount_amount' => 0,
                    'order_coupon_discount_amount' => 0,
                    'coupon_discount_amount' => 0,
                    'code_discount_amount' => 0,
                    'subtotal_points_used_amount' => 0,
                    'subtotal_deposit_used_amount' => 0,
                    'subtotal_paid_amount' => $finalAmount,
                    'subtotal_tax_amount' => round($finalAmount / 11, 2),
                    'subtotal_tax_free_amount' => 0,
                    'subtotal_earned_points_amount' => round($finalAmount * 0.01, 2),
                    'product_snapshot' => $product->toSnapshotArray(),
                    'option_snapshot' => $productOption->toSnapshotArray(),
                    'promotions_applied_snapshot' => null,
                    // 다중 통화 필드 (OrderProcessingService와 동일 형식)
                    'mc_unit_price' => $this->buildMultiCurrencyAmount($unitPrice),
                    'mc_subtotal_price' => $this->buildMultiCurrencyAmount($subtotalPrice),
                    'mc_product_coupon_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_order_coupon_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_coupon_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_code_discount_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_subtotal_points_used_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_subtotal_deposit_used_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_subtotal_tax_amount' => $this->buildMultiCurrencyAmount(round($finalAmount / 11, 2)),
                    'mc_subtotal_tax_free_amount' => $this->buildMultiCurrencyAmount(0),
                    'mc_final_amount' => $this->buildMultiCurrencyAmount($finalAmount),
                ]);

                $orderOptions->push($orderOption);
            }
        } else {
            // 상품 없이 더미 주문 옵션 생성
            for ($i = 0; $i < $count; $i++) {
                $optionStatus = $this->getOrderOptionStatusByOrderStatus($orderStatus);

                $orderOption = OrderOptionFactory::new()
                    ->forOrder($order)
                    ->create([
                        'option_status' => $optionStatus,
                        'product_id' => null,
                        'product_option_id' => null,
                    ]);

                $orderOptions->push($orderOption);
            }
        }

        return $orderOptions;
    }

    /**
     * 주문 상태에 따른 주문 옵션 상태 반환
     *
     * OrderStatusEnum으로 통일되었으므로 동일한 값을 반환합니다.
     *
     * @param  OrderStatusEnum  $orderStatus
     * @return OrderStatusEnum
     */
    private function getOrderOptionStatusByOrderStatus(OrderStatusEnum $orderStatus): OrderStatusEnum
    {
        return $orderStatus;
    }

    /**
     * 주문 금액 재계산
     *
     * @param  Order  $order
     * @param  \Illuminate\Support\Collection  $orderOptions
     */
    private function recalculateOrderAmount(Order $order, $orderOptions): void
    {
        // 옵션 모델을 DB에서 다시 조회 (쿠폰 적용 후 업데이트된 값 반영)
        $freshOptions = OrderOption::where('order_id', $order->id)->get();

        $subtotalAmount = $freshOptions->sum('subtotal_price');
        $discountAmount = $freshOptions->sum('subtotal_discount_amount');
        $productCouponDiscount = $freshOptions->sum('product_coupon_discount_amount');
        $orderCouponDiscount = $freshOptions->sum('order_coupon_discount_amount');
        $couponDiscount = $freshOptions->sum('coupon_discount_amount');
        $codeDiscount = $freshOptions->sum('code_discount_amount');

        // 배송 레코드에서 실제 배송비 합산 (배송 미생성 시 0)
        $shippings = OrderShipping::where('order_id', $order->id)->get();
        $baseShippingAmount = (float) $shippings->sum('base_shipping_amount');
        $shippingDiscountAmount = (float) $shippings->sum('shipping_discount_amount');
        $shippingAmount = (float) $shippings->sum('total_shipping_amount');
        $totalAmount = $subtotalAmount - $discountAmount + $shippingAmount;

        $taxAmount = round($totalAmount / 11, 2);

        $updateData = [
            'subtotal_amount' => $subtotalAmount,
            'total_discount_amount' => $discountAmount,
            'total_coupon_discount_amount' => $couponDiscount,
            'total_product_coupon_discount_amount' => $productCouponDiscount,
            'total_order_coupon_discount_amount' => $orderCouponDiscount,
            'total_code_discount_amount' => $codeDiscount,
            'base_shipping_amount' => $baseShippingAmount,
            'shipping_discount_amount' => $shippingDiscountAmount,
            'total_shipping_amount' => $shippingAmount,
            'total_amount' => $totalAmount,
            'total_tax_amount' => $taxAmount,
            'total_earned_points_amount' => round($totalAmount * 0.01, 2),
            'item_count' => $freshOptions->count(),
            'total_weight' => $freshOptions->sum('subtotal_weight'),
            'total_volume' => $freshOptions->sum('subtotal_volume'),
            // 다중 통화 필드 (OrderProcessingService::buildOrderMultiCurrency와 동일 형식)
            'mc_subtotal_amount' => $this->buildMultiCurrencyAmount($subtotalAmount),
            'mc_total_discount_amount' => $this->buildMultiCurrencyAmount($discountAmount),
            'mc_total_product_coupon_discount_amount' => $this->buildMultiCurrencyAmount($productCouponDiscount),
            'mc_total_order_coupon_discount_amount' => $this->buildMultiCurrencyAmount($orderCouponDiscount),
            'mc_total_coupon_discount_amount' => $this->buildMultiCurrencyAmount($couponDiscount),
            'mc_total_code_discount_amount' => $this->buildMultiCurrencyAmount($codeDiscount),
            'mc_base_shipping_amount' => $this->buildMultiCurrencyAmount($baseShippingAmount),
            'mc_extra_shipping_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_shipping_discount_amount' => $this->buildMultiCurrencyAmount($shippingDiscountAmount),
            'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($shippingAmount),
            'mc_total_points_used_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_total_deposit_used_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_total_tax_amount' => $this->buildMultiCurrencyAmount($taxAmount),
            'mc_total_tax_free_amount' => $this->buildMultiCurrencyAmount(0),
            'mc_total_amount' => $this->buildMultiCurrencyAmount($totalAmount),
            'mc_total_paid_amount' => $this->buildMultiCurrencyAmount(0),
        ];

        // 결제 완료 상태면 결제 금액도 업데이트
        if (! $order->order_status->isBeforePayment()) {
            $updateData['total_paid_amount'] = $totalAmount;
            $updateData['mc_total_paid_amount'] = $this->buildMultiCurrencyAmount($totalAmount);
            $updateData['total_due_amount'] = 0;
        } else {
            $updateData['total_due_amount'] = $totalAmount;
        }

        // 취소 상태면 취소 금액 업데이트
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            $updateData['total_cancelled_amount'] = $totalAmount;
        }

        $order->update($updateData);
    }

    /**
     * 주문 주소 생성
     *
     * @param  Order  $order
     */
    private function createOrderAddress(Order $order): void
    {
        OrderAddressFactory::new()
            ->forOrder($order)
            ->shipping()
            ->create();
    }

    /**
     * 주문 결제 정보 생성
     *
     * @param  Order  $order
     * @param  OrderStatusEnum  $status
     */
    private function createOrderPayment(Order $order, OrderStatusEnum $status): void
    {
        $factory = OrderPaymentFactory::new()->forOrder($order);

        // 결제 방법 랜덤 선택 (카드 60%, 무통장입금 25%, 가상계좌 15%)
        $paymentMethod = $this->getRandomPaymentMethod();

        $factory = match ($paymentMethod) {
            'card' => $factory->card(),
            'dbank' => $factory->directBank(),
            'vbank' => $factory->virtualAccount(),
            default => $factory->card(),
        };

        // 상태에 따른 결제 상태
        if ($status === OrderStatusEnum::CANCELLED) {
            $factory = $factory->cancelled();
        } elseif (in_array($paymentMethod, ['vbank', 'dbank']) && $status === OrderStatusEnum::PENDING_PAYMENT) {
            $factory = $factory->pending();
        } else {
            $factory = $factory->completed();
        }

        $factory->create([
            'paid_amount_local' => $order->total_amount,
            'paid_amount_base' => $order->total_amount,
            'vat_amount' => $order->total_tax_amount,
        ]);
    }

    /**
     * 랜덤 결제 방법 반환
     *
     * @return string
     */
    private function getRandomPaymentMethod(): string
    {
        $rand = rand(1, 100);

        if ($rand <= 60) {
            return 'card';
        } elseif ($rand <= 85) {
            return 'dbank';
        }

        return 'vbank';
    }

    /**
     * CurrencyConversionService 인스턴스 캐시
     */
    private ?CurrencyConversionService $currencyConversionService = null;

    /**
     * CurrencyConversionService를 지연 로딩합니다.
     *
     * @return CurrencyConversionService
     */
    private function getCurrencyConversionService(): CurrencyConversionService
    {
        if ($this->currencyConversionService === null) {
            $this->currencyConversionService = app(CurrencyConversionService::class);
        }

        return $this->currencyConversionService;
    }

    /**
     * 다중 통화 금액 생성
     *
     * OrderProcessingService::buildAllCurrencyConverter와 동일한 형식으로
     * CurrencyConversionService를 사용하여 등록된 모든 통화로 변환합니다.
     *
     * @param  float  $amount  KRW 기준 금액
     * @return array 다중 통화 데이터 {통화코드: 금액}
     */
    private function buildMultiCurrencyAmount(float $amount): array
    {
        $multiCurrency = $this->getCurrencyConversionService()->convertToMultiCurrency((int) $amount);

        $result = [];
        foreach ($multiCurrency as $code => $data) {
            $result[$code] = $data['price'];
        }

        // 변환 결과가 없으면 기본 통화만 반환
        if (empty($result)) {
            $baseCurrency = $this->getCurrencyConversionService()->getDefaultCurrency();
            $result[$baseCurrency] = $amount;
        }

        return $result;
    }

    /**
     * 통화 스냅샷 생성
     *
     * OrderProcessingService::buildCurrencySnapshot과 동일한 형식으로
     * 주문 생성 시점의 통화 정보를 반환합니다.
     *
     * @return array 통화 스냅샷
     */
    private function buildCurrencySnapshot(): array
    {
        $service = $this->getCurrencyConversionService();
        $baseCurrency = $service->getDefaultCurrency();
        $currencies = $service->getCurrencySettings();

        $exchangeRates = [];
        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $rate = ($currency['is_default'] ?? false) ? 1.0 : ($currency['exchange_rate'] ?? 0);
            $exchangeRates[$code] = $rate;
        }

        return [
            'base_currency' => $baseCurrency,
            'order_currency' => $baseCurrency,
            'exchange_rate' => 1.0,
            'exchange_rates' => $exchangeRates,
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 옵션값 요약 문자열 생성 (다국어)
     *
     * ProductOption의 option_values를 로케일별 요약 문자열로 변환합니다.
     * 예: {"ko": "색상: 빨강, 사이즈: L", "en": "Color: Red, Size: L"}
     *
     * @param  array|null  $optionValues  ProductOption의 option_values
     * @return array 다국어 요약 문자열 배열
     */
    private function buildMultilingualOptionValue(?array $optionValues): array
    {
        if (empty($optionValues)) {
            return [];
        }

        // 새 구조: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}]
        if (isset($optionValues[0]['key'])) {
            $locales = config('app.supported_locales', ['ko', 'en']);
            $result = [];

            foreach ($locales as $locale) {
                $parts = [];
                foreach ($optionValues as $item) {
                    $key = $item['key'] ?? [];
                    $value = $item['value'] ?? [];

                    $localizedKey = is_array($key) ? ($key[$locale] ?? $key['ko'] ?? array_values($key)[0] ?? '') : $key;
                    $localizedValue = is_array($value) ? ($value[$locale] ?? $value['ko'] ?? array_values($value)[0] ?? '') : $value;

                    if ($localizedKey !== '' && $localizedValue !== '') {
                        $parts[] = $localizedKey.': '.$localizedValue;
                    }
                }
                $result[$locale] = implode(', ', $parts);
            }

            return $result;
        }

        // 기존 구조: {"색상": "빨강"} (하위 호환성) - ko로만 반환
        $parts = [];
        foreach ($optionValues as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return ['ko' => implode(', ', $parts)];
    }

    /**
     * 주문 배송 정보 생성
     *
     * @param  Order  $order
     * @param  \Illuminate\Support\Collection  $orderOptions
     * @param  OrderStatusEnum  $status
     */
    private function createOrderShippings(Order $order, $orderOptions, OrderStatusEnum $status): void
    {
        $shippingPolicies = ShippingPolicy::with('countrySettings')->get();
        // 해외 배송(API) 정책 제외 — 시더에서 외부 API 호출 불가
        $domesticPolicies = $shippingPolicies->filter(function ($policy) {
            return $policy->countrySettings->contains(fn ($cs) => $cs->country_code === 'KR' && $cs->is_active);
        });

        $packageNumber = 'PKG-'.str_pad($order->id, 8, '0', STR_PAD_LEFT);
        $currencySnapshot = $order->currency_snapshot;

        foreach ($orderOptions as $orderOption) {
            $factory = OrderShippingFactory::new()
                ->forOrder($order)
                ->forOrderOption($orderOption);

            // 상태에 따른 배송 상태
            $factory = match ($status) {
                OrderStatusEnum::PREPARING, OrderStatusEnum::SHIPPING_HOLD => $factory->preparing(),
                OrderStatusEnum::SHIPPING_READY, OrderStatusEnum::SHIPPING => $factory->inTransit(),
                OrderStatusEnum::DELIVERED, OrderStatusEnum::CONFIRMED => $factory->delivered(),
                default => $factory->preparing(),
            };

            // 배송정책 기반 배송비 계산
            $policy = $domesticPolicies->isNotEmpty() ? $domesticPolicies->random() : null;
            $countrySetting = $policy?->countrySettings
                ->where('country_code', 'KR')
                ->where('is_active', true)
                ->first();

            $shippingAmount = $countrySetting
                ? $this->calculateSeederShippingFee($countrySetting, $orderOption)
                : 0;

            $extraAttributes = [
                'base_shipping_amount' => $shippingAmount,
                'total_shipping_amount' => $shippingAmount,
                // 필수 보완: 다중 통화
                'currency_snapshot' => $currencySnapshot,
                'mc_base_shipping_amount' => $this->buildMultiCurrencyAmount($shippingAmount),
                'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($shippingAmount),
                'mc_return_shipping_amount' => $this->buildMultiCurrencyAmount(0),
                // 선택적 보완: 묶음배송 번호
                'package_number' => $packageNumber,
            ];

            // 배송정책 연결 + 배송정책 스냅샷
            if ($policy) {
                $extraAttributes['shipping_policy_id'] = $policy->id;
                $extraAttributes['delivery_policy_snapshot'] = $this->buildDeliveryPolicySnapshot($policy, $shippingAmount);
            }

            // 논리적 보완: 구매확정 상태 → confirmed_at 설정
            if ($status === OrderStatusEnum::CONFIRMED) {
                $extraAttributes['confirmed_at'] = now()->subDays(rand(1, 3));
            }

            $factory->create($extraAttributes);
        }
    }

    /**
     * 시더용 배송비 계산 (실제 OrderCalculationService 로직 간소화)
     *
     * charge_policy 기반으로 배송비를 산출합니다.
     * 실제 주문 시 calculateCountryShippingFee()와 동일한 분기 로직을 적용합니다.
     *
     * @param  \Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting  $countrySetting
     * @param  OrderOption  $orderOption
     * @return int 배송비 (원)
     */
    private function calculateSeederShippingFee($countrySetting, OrderOption $orderOption): int
    {
        $subtotal = (int) $orderOption->subtotal_price;
        $quantity = (int) $orderOption->quantity;
        $weight = (float) ($orderOption->subtotal_weight ?? $quantity * 0.5);
        $volume = (float) ($orderOption->subtotal_volume ?? $quantity * 1000);
        $baseFee = (int) $countrySetting->base_fee;
        $ranges = $countrySetting->ranges;

        // 부피무게 계산
        $volumeWeightDivisor = $ranges['volume_weight_divisor'] ?? 6000;
        $volumeWeight = $volumeWeightDivisor > 0 ? $volume / $volumeWeightDivisor : 0.0;
        $chargeableWeight = max($weight, $volumeWeight);

        return match ($countrySetting->charge_policy) {
            ChargePolicyEnum::FREE => 0,
            ChargePolicyEnum::FIXED => $baseFee,
            ChargePolicyEnum::CONDITIONAL_FREE => $subtotal >= (int) $countrySetting->free_threshold ? 0 : $baseFee,
            ChargePolicyEnum::RANGE_AMOUNT => $this->calculateSeederRangeFee($ranges, $subtotal),
            ChargePolicyEnum::RANGE_QUANTITY => $this->calculateSeederRangeFee($ranges, $quantity),
            ChargePolicyEnum::RANGE_WEIGHT => $this->calculateSeederRangeFee($ranges, (int) ($weight * 1000)),
            ChargePolicyEnum::RANGE_VOLUME => $this->calculateSeederRangeFee($ranges, (int) $volume),
            ChargePolicyEnum::RANGE_VOLUME_WEIGHT => $this->calculateSeederRangeFee($ranges, (int) ($chargeableWeight * 1000)),
            ChargePolicyEnum::PER_QUANTITY => (int) (ceil($quantity / max($ranges['unit_value'] ?? 1, 1)) * $baseFee),
            ChargePolicyEnum::PER_WEIGHT => (int) (ceil($weight / max($ranges['unit_value'] ?? 0.5, 0.01)) * $baseFee),
            ChargePolicyEnum::PER_VOLUME => (int) (ceil($volume / max($ranges['unit_value'] ?? 1000, 1)) * $baseFee),
            ChargePolicyEnum::PER_VOLUME_WEIGHT => (int) (ceil($chargeableWeight / max($ranges['unit_value'] ?? 0.5, 0.01)) * $baseFee),
            ChargePolicyEnum::PER_AMOUNT => (int) (ceil($subtotal / max($ranges['unit_value'] ?? 10000, 1)) * $baseFee),
            ChargePolicyEnum::API => $baseFee, // API는 시더에서 base_fee 사용
            default => $baseFee,
        };
    }

    /**
     * 구간별 배송비 계산 (시더용)
     *
     * @param  array|null  $ranges  구간 설정
     * @param  int  $value  비교 값
     * @return int 배송비
     */
    private function calculateSeederRangeFee(?array $ranges, int $value): int
    {
        if (empty($ranges) || empty($ranges['tiers'])) {
            return 0;
        }

        foreach ($ranges['tiers'] as $tier) {
            $min = $tier['min'] ?? 0;
            $max = $tier['max'] ?? PHP_INT_MAX;

            if ($value >= $min && ($max === null || $value < $max)) {
                return (int) ($tier['fee'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 사용자별 사용 가능한 쿠폰 발급 목록을 로드합니다.
     *
     * CouponSeeder에서 생성한 available 상태의 발급 내역을
     * 쿠폰 정보와 함께 사용자별로 그룹화하여 반환합니다.
     *
     * @return array<int, \Illuminate\Support\Collection> [user_id => Collection<CouponIssue>]
     */
    private function loadAvailableCouponIssuesByUser(): array
    {
        $issues = CouponIssue::with('coupon')
            ->where('status', CouponIssueRecordStatus::AVAILABLE)
            ->whereHas('coupon', fn ($q) => $q->whereNull('deleted_at'))
            ->get();

        if ($issues->isEmpty()) {
            $this->command->line('  - 사용 가능한 쿠폰 발급 내역이 없습니다. 쿠폰 미적용 주문만 생성합니다.');

            return [];
        }

        // Collection 그대로 유지 (모델 인스턴스 보존)
        $grouped = $issues->groupBy('user_id')->all();
        $this->command->line("  - 쿠폰 발급 내역 {$issues->count()}건 로드 (".count($grouped)."명 사용자)");

        return $grouped;
    }

    /**
     * 주문 옵션에 쿠폰 할인을 적용합니다.
     *
     * 사용자의 사용 가능한 쿠폰 중 1~2개를 선택하여 적용합니다.
     * - 상품금액 쿠폰 (product_amount): 각 옵션에 균등 분배
     * - 주문금액 쿠폰 (order_amount): 각 옵션에 금액 비율로 배분
     * - 배송비 쿠폰 (shipping_fee): 배송 레코드 생성 후 적용하기 위해 반환
     * 적용 후 CouponIssue 상태를 'used'로 업데이트합니다.
     *
     * @param  Order  $order  주문
     * @param  \Illuminate\Support\Collection  $orderOptions  주문 옵션 목록
     * @param  \Illuminate\Support\Collection  $userCouponIssues  사용자의 쿠폰 발급 목록
     * @return array 배송비 쿠폰 목록 (배송 레코드 생성 후 적용 필요)
     */
    private function applyCouponsToOrderOptions(Order $order, $orderOptions, $userCouponIssues): array
    {
        // Collection으로 변환 (array로 전달된 경우)
        $issues = collect($userCouponIssues);

        // 사용 가능한 쿠폰 필터링 (available 상태, 만료되지 않은 것)
        $available = $issues->filter(function ($issue) {
            if (! ($issue instanceof CouponIssue)) {
                return false;
            }

            return $issue->status === CouponIssueRecordStatus::AVAILABLE
                && $issue->coupon
                && ($issue->expired_at === null || $issue->expired_at->isFuture());
        });

        if ($available->isEmpty()) {
            return [];
        }

        $orderSubtotal = $orderOptions->sum('subtotal_price');

        // 1~2개 쿠폰 랜덤 선택 (중복불가 쿠폰은 1개만)
        $selected = $this->selectCouponsForOrder($available, $orderSubtotal, $orderOptions);

        if (empty($selected)) {
            return [];
        }

        // 배송비 쿠폰과 상품/주문 쿠폰 분리
        $shippingCoupons = [];
        $optionCoupons = [];
        foreach ($selected as $issueData) {
            if ($issueData['coupon']->target_type === CouponTargetType::SHIPPING_FEE) {
                $shippingCoupons[] = $issueData;
            } else {
                $optionCoupons[] = $issueData;
            }
        }

        // 쿠폰별 할인금액 계산 및 옵션에 배분
        $promotionSnapshot = ['coupons' => [], 'discount_codes' => [], 'events' => []];

        foreach ($optionCoupons as $issueData) {
            /** @var CouponIssue $issueModel */
            $issueModel = $issueData['issue'];
            $coupon = $issueData['coupon'];
            $discountAmount = $issueData['discount'];

            // 옵션별 할인 배분
            $this->distributeDiscountToOptions($orderOptions, $coupon, $discountAmount, $orderSubtotal);

            // CouponIssue 상태 업데이트 (메모리 상 모델도 갱신하여 중복 선택 방지)
            $issueModel->status = CouponIssueRecordStatus::USED;
            $issueModel->used_at = $order->ordered_at ?? now();
            $issueModel->order_id = $order->id;
            $issueModel->discount_amount = $discountAmount;
            $issueModel->save();

            // 소진 기록 추가 (보충용)
            $this->usedCouponRecords[] = ['user_id' => $issueModel->user_id, 'coupon_id' => $coupon->id];

            // 프로모션 스냅샷 빌드
            $promotionSnapshot['coupons'][] = $this->buildCouponSnapshotEntry($coupon, $issueModel, $discountAmount, $orderOptions);
        }

        // 배송비 쿠폰도 CouponIssue 사용 처리 + 스냅샷 추가
        foreach ($shippingCoupons as $issueData) {
            /** @var CouponIssue $issueModel */
            $issueModel = $issueData['issue'];
            $coupon = $issueData['coupon'];
            $discountAmount = $issueData['discount'];

            $issueModel->status = CouponIssueRecordStatus::USED;
            $issueModel->used_at = $order->ordered_at ?? now();
            $issueModel->order_id = $order->id;
            $issueModel->discount_amount = $discountAmount;
            $issueModel->save();

            // 소진 기록 추가 (보충용)
            $this->usedCouponRecords[] = ['user_id' => $issueModel->user_id, 'coupon_id' => $coupon->id];

            $promotionSnapshot['coupons'][] = $this->buildCouponSnapshotEntry($coupon, $issueModel, $discountAmount, $orderOptions);
        }

        // 모든 옵션에 promotions_applied_snapshot 업데이트
        if (! empty($promotionSnapshot['coupons'])) {
            foreach ($orderOptions as $opt) {
                $opt->update(['promotions_applied_snapshot' => $promotionSnapshot]);
            }
        }

        // 주문 레벨 promotions_applied_snapshot 저장
        // OrderAdjustmentService.buildRecalcInput()이 쿠폰 재적용에 필요
        $allSelected = array_merge($optionCoupons, $shippingCoupons);
        if (! empty($allSelected)) {
            $orderSnapshot = $this->buildOrderPromotionsSnapshot($allSelected, $orderOptions);
            $order->update(['promotions_applied_snapshot' => $orderSnapshot]);
        }

        return $shippingCoupons;
    }

    /**
     * 쿠폰 스냅샷 항목을 생성합니다.
     *
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  CouponIssue  $issueModel  쿠폰 발급 모델
     * @param  int  $discountAmount  할인금액
     * @param  \Illuminate\Support\Collection  $orderOptions  주문 옵션 목록
     * @return array
     */
    private function buildCouponSnapshotEntry(Coupon $coupon, CouponIssue $issueModel, int $discountAmount, $orderOptions): array
    {
        $couponName = is_array($coupon->name)
            ? ($coupon->name['ko'] ?? $coupon->name['en'] ?? '')
            : (string) $coupon->name;

        return [
            'coupon_id' => $coupon->id,
            'coupon_issue_id' => $issueModel->id,
            'name' => $couponName,
            'target_type' => $coupon->target_type->value,
            'target_scope' => $coupon->target_scope,
            'discount_type' => $coupon->discount_type->value,
            'discount_value' => (float) $coupon->discount_value,
            'total_discount' => $discountAmount,
            'total_discount_formatted' => number_format($discountAmount).'원',
            'applied_items' => $orderOptions->map(fn ($opt) => [
                'product_option_id' => $opt->product_option_id,
                'discount_amount' => (int) $opt->product_coupon_discount_amount + (int) $opt->order_coupon_discount_amount,
                'discount_amount_formatted' => number_format((int) $opt->product_coupon_discount_amount + (int) $opt->order_coupon_discount_amount).'원',
            ])->toArray(),
            'is_exclusive' => ! $coupon->is_combinable,
            'min_order_amount' => (int) $coupon->min_order_amount,
            'max_discount_amount' => (int) ($coupon->discount_max_amount ?? 0),
        ];
    }

    /**
     * 주문에 적용할 쿠폰을 선택합니다.
     *
     * 최소 주문금액 조건을 만족하는 쿠폰 중 1~2개를 선택합니다.
     * 중복불가(is_combinable=false) 쿠폰은 단독 적용됩니다.
     *
     * @param  \Illuminate\Support\Collection  $available  사용 가능한 쿠폰 발급 목록
     * @param  float  $orderSubtotal  주문 소계
     * @return array [{issue, coupon, discount}]
     */
    private function selectCouponsForOrder($available, float $orderSubtotal, $orderOptions = null): array
    {
        $candidates = [];

        foreach ($available as $issueModel) {
            if (! ($issueModel instanceof CouponIssue) || ! $issueModel->coupon) {
                continue;
            }

            $coupon = $issueModel->coupon;

            // 최소 주문금액 조건
            if ($orderSubtotal < (float) $coupon->min_order_amount) {
                continue;
            }

            // 상품금액 정액 쿠폰: 적용 대상 옵션의 총 수량만큼 할인
            $totalQuantity = 1;
            if ($orderOptions
                && $coupon->target_type === CouponTargetType::PRODUCT_AMOUNT
                && $coupon->discount_type === CouponDiscountType::FIXED
            ) {
                $totalQuantity = $this->getTargetQuantity($coupon, $orderOptions);
            }

            $discount = $this->calculateCouponDiscount($coupon, $orderSubtotal, $totalQuantity);
            if ($discount <= 0) {
                continue;
            }

            $candidates[] = [
                'issue' => $issueModel,
                'coupon' => $coupon,
                'discount' => $discount,
            ];
        }

        if (empty($candidates)) {
            return [];
        }

        // 중복불가 쿠폰이 있으면 단독 적용
        $exclusives = array_values(array_filter($candidates, fn ($c) => ! $c['coupon']->is_combinable));
        if (! empty($exclusives) && rand(1, 100) <= 30) {
            return [$exclusives[array_rand($exclusives)]];
        }

        // 중복 가능 쿠폰 중 1~2개 선택
        $combinables = array_values(array_filter($candidates, fn ($c) => $c['coupon']->is_combinable));
        if (empty($combinables)) {
            // 중복 가능 쿠폰이 없으면 중복불가 쿠폰 1개
            return [$exclusives[array_rand($exclusives)]];
        }

        $combinables = array_values($combinables);
        shuffle($combinables);
        $pickCount = min(rand(1, 2), count($combinables));

        // 같은 target_type 쿠폰 중복 방지
        $picked = [];
        $usedTypes = [];
        foreach ($combinables as $c) {
            if (count($picked) >= $pickCount) {
                break;
            }
            if (in_array($c['coupon']->target_type, $usedTypes)) {
                continue;
            }
            $picked[] = $c;
            $usedTypes[] = $c['coupon']->target_type;
        }

        return $picked;
    }

    /**
     * 쿠폰의 할인금액을 계산합니다.
     *
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  float  $orderSubtotal  주문 소계
     * @return int 할인금액
     */
    private function calculateCouponDiscount(Coupon $coupon, float $orderSubtotal, int $quantity = 1): int
    {
        if ($coupon->discount_type === CouponDiscountType::FIXED) {
            // 정액 할인: 수량만큼 할인 (상품금액 쿠폰에서 수량 전달)
            $discount = (int) $coupon->discount_value * $quantity;
        } else {
            // 정률 할인
            $discount = (int) floor($orderSubtotal * (float) $coupon->discount_value / 100);

            // 최대 할인금액 제한
            if ($coupon->discount_max_amount && $discount > (int) $coupon->discount_max_amount) {
                $discount = (int) $coupon->discount_max_amount;
            }
        }

        // 할인금액이 주문금액을 초과하지 않도록
        return min($discount, (int) $orderSubtotal);
    }

    /**
     * 할인금액을 주문 옵션에 배분합니다.
     *
     * - 상품금액 쿠폰: product_coupon_discount_amount에 배분
     * - 주문금액 쿠폰: order_coupon_discount_amount에 금액 비율로 배분
     *
     * @param  \Illuminate\Support\Collection  $orderOptions  주문 옵션 목록
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  int  $totalDiscount  총 할인금액
     * @param  float  $orderSubtotal  주문 소계
     */
    private function distributeDiscountToOptions($orderOptions, Coupon $coupon, int $totalDiscount, float $orderSubtotal): void
    {
        $isProductCoupon = $coupon->target_type === CouponTargetType::PRODUCT_AMOUNT;
        $discountField = $isProductCoupon ? 'product_coupon_discount_amount' : 'order_coupon_discount_amount';

        $remaining = $totalDiscount;
        $optionCount = $orderOptions->count();

        foreach ($orderOptions as $index => $opt) {
            // 마지막 옵션에 나머지 배분 (반올림 오차 방지)
            if ($index === $optionCount - 1) {
                $share = $remaining;
            } else {
                // 금액 비율로 배분
                $ratio = $orderSubtotal > 0 ? (float) $opt->subtotal_price / $orderSubtotal : (1 / $optionCount);
                $share = (int) floor($totalDiscount * $ratio);
            }

            $remaining -= $share;
            $currentFieldValue = (int) $opt->$discountField;
            $currentCouponTotal = (int) $opt->coupon_discount_amount;
            $newFieldValue = $currentFieldValue + $share;
            $newCouponTotal = $currentCouponTotal + $share;
            $newSubtotalDiscount = (int) $opt->subtotal_discount_amount + $share;
            $newFinalAmount = (int) $opt->subtotal_price - $newSubtotalDiscount;

            $opt->update([
                $discountField => $newFieldValue,
                'coupon_discount_amount' => $newCouponTotal,
                'subtotal_discount_amount' => $newSubtotalDiscount,
                'subtotal_paid_amount' => $newFinalAmount,
                'subtotal_tax_amount' => round($newFinalAmount / 11, 2),
                'subtotal_earned_points_amount' => round($newFinalAmount * 0.01, 2),
                // 다중 통화
                'mc_'.($isProductCoupon ? 'product_coupon' : 'order_coupon').'_discount_amount' => $this->buildMultiCurrencyAmount($newFieldValue),
                'mc_coupon_discount_amount' => $this->buildMultiCurrencyAmount($newCouponTotal),
                'mc_subtotal_tax_amount' => $this->buildMultiCurrencyAmount(round($newFinalAmount / 11, 2)),
                'mc_final_amount' => $this->buildMultiCurrencyAmount($newFinalAmount),
            ]);
        }
    }

    /**
     * 쿠폰 적용 대상 옵션의 총 수량을 계산합니다.
     *
     * target_scope에 따라:
     * - ALL: 모든 옵션의 수량 합계
     * - PRODUCTS: 쿠폰에 포함된 상품의 옵션 수량 합계
     * - CATEGORIES: 쿠폰에 포함된 카테고리에 속한 상품의 옵션 수량 합계
     *
     * @param  Coupon  $coupon  쿠폰 모델
     * @param  \Illuminate\Support\Collection  $orderOptions  주문 옵션 목록
     * @return int 적용 대상 총 수량
     */
    private function getTargetQuantity(Coupon $coupon, $orderOptions): int
    {
        if ($coupon->target_scope === CouponTargetScope::ALL || $coupon->target_scope === null) {
            return $orderOptions->sum('quantity');
        }

        if ($coupon->target_scope === CouponTargetScope::PRODUCTS) {
            $includedProductIds = $coupon->includedProducts()->pluck('product_id')->toArray();
            if (empty($includedProductIds)) {
                return $orderOptions->sum('quantity');
            }

            return $orderOptions
                ->filter(fn ($opt) => in_array($opt->product_id, $includedProductIds))
                ->sum('quantity');
        }

        if ($coupon->target_scope === CouponTargetScope::CATEGORIES) {
            $includedCategoryIds = $coupon->includedCategories()->pluck('category_id')->toArray();
            if (empty($includedCategoryIds)) {
                return $orderOptions->sum('quantity');
            }

            return $orderOptions
                ->filter(function ($opt) use ($includedCategoryIds) {
                    $product = Product::find($opt->product_id);
                    if (! $product) {
                        return false;
                    }
                    $productCategoryIds = $product->categories()->pluck('id')->toArray();

                    return ! empty(array_intersect($productCategoryIds, $includedCategoryIds));
                })
                ->sum('quantity');
        }

        return $orderOptions->sum('quantity');
    }

    /**
     * 주문 레벨 promotions_applied_snapshot을 생성합니다.
     *
     * OrderAdjustmentService.buildRecalcInput()이 부분취소 시 쿠폰을 재적용하기 위해
     * coupon_issue_ids, product_promotions, order_promotions 구조가 필요합니다.
     *
     * @param  array  $selectedCoupons  적용된 쿠폰 목록 [{issue, coupon, discount}]
     * @param  \Illuminate\Support\Collection  $orderOptions  주문 옵션 목록
     * @return array 주문 레벨 스냅샷
     */
    private function buildOrderPromotionsSnapshot(array $selectedCoupons, $orderOptions): array
    {
        $couponIssueIds = [];
        $productPromotionCoupons = [];
        $orderPromotionCoupons = [];

        foreach ($selectedCoupons as $issueData) {
            /** @var CouponIssue $issueModel */
            $issueModel = $issueData['issue'];
            $coupon = $issueData['coupon'];
            $discountAmount = $issueData['discount'];

            $couponIssueIds[] = $issueModel->id;

            $couponName = is_array($coupon->name)
                ? ($coupon->name['ko'] ?? $coupon->name['en'] ?? '')
                : (string) $coupon->name;

            $entry = [
                'coupon_issue_id' => $issueModel->id,
                'coupon_id' => $coupon->id,
                'name' => $couponName,
                'discount_type' => $coupon->discount_type->value,
                'discount_value' => (float) $coupon->discount_value,
                'min_order_amount' => (int) $coupon->min_order_amount,
                'max_discount_amount' => (int) ($coupon->discount_max_amount ?? 0),
                'target_type' => $coupon->target_type->value,
                'target_scope' => $coupon->target_scope,
                'is_exclusive' => ! $coupon->is_combinable,
                'total_discount' => $discountAmount,
                'applied_items' => $orderOptions->map(fn ($opt) => [
                    'product_option_id' => $opt->product_option_id,
                    'discount_amount' => (int) $opt->product_coupon_discount_amount + (int) $opt->order_coupon_discount_amount,
                ])->toArray(),
            ];

            // target_type에 따라 product_promotions 또는 order_promotions에 분류
            if ($coupon->target_type === CouponTargetType::PRODUCT_AMOUNT) {
                $productPromotionCoupons[] = $entry;
            } else {
                // order_amount, shipping_fee → order_promotions
                $orderPromotionCoupons[] = $entry;
            }
        }

        return [
            'coupon_issue_ids' => $couponIssueIds,
            'item_coupons' => [],
            'discount_code' => null,
            'product_promotions' => [
                'coupons' => $productPromotionCoupons,
            ],
            'order_promotions' => [
                'coupons' => $orderPromotionCoupons,
            ],
        ];
    }

    /**
     * 배송비 쿠폰을 배송 레코드에 적용합니다.
     *
     * 배송비 쿠폰의 할인금액을 배송 레코드의 shipping_discount_amount에 배분하고,
     * total_shipping_amount를 재계산합니다.
     *
     * @param  Order  $order  주문
     * @param  array  $shippingCoupons  배송비 쿠폰 목록 [{issue, coupon, discount}]
     */
    private function applyShippingCouponsToShippings(Order $order, array $shippingCoupons): void
    {
        $shippings = OrderShipping::where('order_id', $order->id)->get();
        if ($shippings->isEmpty()) {
            return;
        }

        foreach ($shippingCoupons as $issueData) {
            $coupon = $issueData['coupon'];
            $totalShippingAmount = $shippings->sum('total_shipping_amount');

            if ($totalShippingAmount <= 0) {
                continue;
            }

            // 배송비 쿠폰 할인: 실제 배송비를 초과하지 않도록 제한
            $discount = min($issueData['discount'], (int) $totalShippingAmount);

            // 배송 레코드별 배분 (배송비 비율 기준)
            $remaining = $discount;
            $shippingCount = $shippings->count();

            foreach ($shippings as $index => $shipping) {
                if ($index === $shippingCount - 1) {
                    $share = $remaining;
                } else {
                    $ratio = $totalShippingAmount > 0
                        ? (float) $shipping->total_shipping_amount / $totalShippingAmount
                        : (1 / $shippingCount);
                    $share = (int) floor($discount * $ratio);
                }

                $remaining -= $share;
                $currentDiscount = (int) $shipping->shipping_discount_amount;
                $newDiscount = $currentDiscount + $share;
                $newTotalShipping = max(0, (int) $shipping->base_shipping_amount - $newDiscount);

                $shipping->update([
                    'shipping_discount_amount' => $newDiscount,
                    'total_shipping_amount' => $newTotalShipping,
                    'mc_shipping_discount_amount' => $this->buildMultiCurrencyAmount($newDiscount),
                    'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($newTotalShipping),
                ]);
            }
        }
    }

    /**
     * 배송정책 스냅샷 생성
     *
     * AppliedShippingPolicy DTO의 toArray() 형식과 동일한 구조로
     * 주문 시점의 배송정책 정보를 스냅샷합니다.
     * 국가별 설정(countrySettings)에서 첫 번째 활성 설정을 기반으로 생성합니다.
     *
     * @param  ShippingPolicy  $policy  배송정책 모델
     * @param  float  $shippingAmount  배송비
     * @return array 배송정책 스냅샷
     */
    private function buildDeliveryPolicySnapshot(ShippingPolicy $policy, float $shippingAmount): array
    {
        $countrySetting = $policy->countrySettings()->where('is_active', true)->first();

        return [
            'policy_id' => $policy->id,
            'policy_name' => $policy->getLocalizedName(),
            'country_code' => $countrySetting?->country_code ?? 'KR',
            'charge_policy' => $countrySetting?->charge_policy?->value ?? '',
            'shipping_method' => $countrySetting?->shipping_method ?? '',
            'base_fee' => (float) ($countrySetting?->base_fee ?? 0),
            'free_threshold' => $countrySetting?->free_threshold ? (float) $countrySetting->free_threshold : null,
            'currency_code' => $countrySetting?->currency_code ?? 'KRW',
            'extra_fee_enabled' => (bool) ($countrySetting?->extra_fee_enabled ?? false),
            'shipping_amount' => $shippingAmount,
            'snapshot_at' => now()->toIso8601String(),
        ];
    }

    /**
     * 주문 생성 중 소진된 쿠폰을 보충합니다.
     *
     * 주문 생성 과정에서 기록된 소진 내역(usedCouponRecords)을 기반으로,
     * 해당 사용자-쿠폰 조합에 available 이슈를 1건씩 재발급합니다.
     */
    private function replenishAvailableCoupons(): void
    {
        if (empty($this->usedCouponRecords)) {
            return;
        }

        // 중복 제거 (동일 사용자-쿠폰 조합은 1건만 보충)
        $unique = collect($this->usedCouponRecords)
            ->unique(fn ($r) => $r['user_id'].'-'.$r['coupon_id']);

        $now = now();
        $replenished = 0;

        foreach ($unique as $record) {
            CouponIssue::create([
                'coupon_id' => $record['coupon_id'],
                'user_id' => $record['user_id'],
                'coupon_code' => null,
                'status' => CouponIssueRecordStatus::AVAILABLE,
                'issued_at' => $now,
                'expired_at' => null,
                'used_at' => null,
                'order_id' => null,
                'discount_amount' => null,
            ]);

            $replenished++;
        }

        $this->command->line("  - 주문서 테스트용 쿠폰 {$replenished}건 보충 발급 완료");
    }
}
