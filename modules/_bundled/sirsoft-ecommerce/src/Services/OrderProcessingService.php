<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;

/**
 * 주문 처리 서비스
 *
 * 임시 주문(TempOrder)을 실제 주문(Order)으로 변환합니다.
 * 주문 상태 흐름: pending_order → pending_payment → payment_complete → ...
 */
class OrderProcessingService
{
    public function __construct(
        protected OrderRepositoryInterface $orderRepository,
        protected TempOrderService $tempOrderService,
        protected OrderCalculationService $orderCalculationService,
        protected CurrencyConversionService $currencyConversionService,
        protected ProductRepositoryInterface $productRepository,
        protected ProductOptionRepositoryInterface $productOptionRepository,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected StockService $stockService,
        protected UserAddressService $userAddressService,
        protected CartRepositoryInterface $cartRepository
    ) {}

    /**
     * 임시 주문에서 실제 주문 생성
     *
     * @param TempOrder $tempOrder 임시 주문
     * @param array $ordererInfo 주문자 정보 (name, phone, email)
     * @param array $shippingInfo 배송지 정보
     * @param string $paymentMethod 결제 수단 (card, vbank, dbank 등)
     * @param float $expectedTotalAmount 프론트엔드 결제예정금액 (금액 검증용)
     * @param string|null $shippingMemo 배송 메모
     * @param string|null $depositorName 입금자명 (무통장입금 시)
     * @param array|null $dbankInfo 무통장 수동입금 정보 (dbank 결제 시)
     * @return Order 생성된 주문
     * @throws OrderAmountChangedException 재계산 금액 변동 시
     * @throws PaymentAmountMismatchException 프론트엔드 전달 금액 불일치 시
     * @throws \Exception 계산 검증 오류 시
     */
    public function createFromTempOrder(
        TempOrder $tempOrder,
        array $ordererInfo,
        array $shippingInfo,
        string $paymentMethod,
        float $expectedTotalAmount,
        ?string $shippingMemo = null,
        ?string $depositorName = null,
        ?array $dbankInfo = null
    ): Order {
        // 생성 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_create', $tempOrder, $ordererInfo, $shippingInfo, $paymentMethod);

        // 저장된 파라미터로 재계산 수행
        $calculationInput = $this->buildCalculationInputFromTempOrder($tempOrder);
        $calculationResult = $this->orderCalculationService->calculate($calculationInput);

        // 재계산 검증 오류 확인 (쿠폰 만료, 재고 변동 등)
        if ($calculationResult->hasValidationErrors()) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.order_calculation_validation_failed'));
        }

        // 재계산 금액과 저장된 금액 비교 → 변동 시 주문 차단
        $storedFinalAmount = $tempOrder->getFinalAmount();
        $recalculatedFinalAmount = $calculationResult->summary->finalAmount ?? 0;
        if ($storedFinalAmount !== $recalculatedFinalAmount) {
            Log::warning('주문 재계산 금액 차이로 주문 차단', [
                'temp_order_id' => $tempOrder->id,
                'stored_amount' => $storedFinalAmount,
                'recalculated_amount' => $recalculatedFinalAmount,
            ]);
            throw new OrderAmountChangedException($storedFinalAmount, $recalculatedFinalAmount);
        }

        // ⚠️ 프론트엔드 전달 금액과 서버 재계산 금액 검증
        $this->validateOrderAmount($calculationResult, $expectedTotalAmount);

        // 통화 스냅샷 생성
        $currencySnapshot = $this->buildCurrencySnapshot();

        // 초기 주문 상태 결정
        $initialStatus = $this->determineInitialStatus($paymentMethod);

        $order = DB::transaction(function () use (
            $tempOrder,
            $ordererInfo,
            $shippingInfo,
            $paymentMethod,
            $shippingMemo,
            $depositorName,
            $dbankInfo,
            $calculationResult,
            $initialStatus,
            $currencySnapshot
        ) {
            // 주문 생성
            $order = $this->createOrder($tempOrder, $calculationResult, $initialStatus, $currencySnapshot);

            // 주문 옵션 생성 (배송 정보 연결을 위해 옵션 ID 매핑 반환)
            $createdOptions = $this->createOrderOptions($order, $tempOrder, $calculationResult, $currencySnapshot);

            // 주문 주소 생성 (주문자 + 배송지)
            $this->createOrderAddresses($order, $ordererInfo, $shippingInfo, $shippingMemo);

            // 결제 정보 생성
            $this->createOrderPayment($order, $paymentMethod, $depositorName, $dbankInfo, $calculationResult, $currencySnapshot);

            // 배송 정보 생성 (주문 옵션과 연결)
            $this->createOrderShippings($order, $tempOrder, $calculationResult, $currencySnapshot, $createdOptions);

            // 쿠폰 사용 처리 (재계산 결과에서 적용된 쿠폰 ID 추출)
            $appliedCouponIds = $calculationResult->getAppliedCouponIds();
            if (! empty($appliedCouponIds)) {
                HookManager::doAction('sirsoft-ecommerce.coupon.use', $appliedCouponIds, $order);
            }

            // 마일리지 사용 처리 (TempOrder에 저장된 사용 마일리지)
            $usedPoints = $tempOrder->getUsedPoints();
            if ($usedPoints > 0) {
                HookManager::doAction('sirsoft-ecommerce.mileage.use', $usedPoints, $order);
            }

            // 재고 차감 + 장바구니 처리 (order_placed 타이밍: 트랜잭션 내부에서 실행)
            $timing = $this->settingsService->getStockDeductionTiming($paymentMethod);
            if ($timing === 'order_placed') {
                $this->stockService->deductStock($order->load('options'));
                $this->clearOrderedCartItems($order);
            }

            return $order;
        });

        // 생성 후 훅
        HookManager::doAction('sirsoft-ecommerce.order.after_create', $order);

        return $order;
    }

    /**
     * 초기 주문 상태 결정
     *
     * @param string $paymentMethod 결제 수단
     * @return OrderStatusEnum
     */
    protected function determineInitialStatus(string $paymentMethod): OrderStatusEnum
    {
        // 무통장입금(vbank, dbank)은 결제대기 상태로 시작
        if (in_array($paymentMethod, ['vbank', 'dbank'])) {
            return OrderStatusEnum::PENDING_PAYMENT;
        }

        // 그 외 (PG 결제 등)는 주문대기로 시작 후 결제 완료 시 상태 변경
        return OrderStatusEnum::PENDING_ORDER;
    }

    /**
     * 주문 금액 검증
     *
     * 프론트엔드 결제예정금액과 서버 재계산 금액을 비교합니다.
     *
     * @param OrderCalculationResult $calculationResult 계산 결과
     * @param float $expectedAmount 프론트엔드 결제예정금액
     * @return void
     * @throws PaymentAmountMismatchException 금액 불일치 시
     */
    protected function validateOrderAmount(OrderCalculationResult $calculationResult, float $expectedAmount): void
    {
        // finalAmount: 최종 지불금액 (마일리지 차감 후 실제 결제 금액)
        $actualAmount = $calculationResult->summary->finalAmount ?: ($calculationResult->summary->paymentAmount ?: 0);

        // 정확 일치 검증 (소수점 이하 2자리까지)
        $expectedRounded = round($expectedAmount, 2);
        $actualRounded = round($actualAmount, 2);

        if ($expectedRounded !== $actualRounded) {
            throw new PaymentAmountMismatchException($expectedAmount, $actualAmount, [
                'user_id' => Auth::id(),
                'calculation_summary' => [
                    'subtotal' => $calculationResult->summary->subtotal ?? 0,
                    'total_discount' => $calculationResult->summary->totalDiscount ?? 0,
                    'shipping' => $calculationResult->summary->totalShipping ?? 0,
                    'payment_amount' => $actualAmount,
                ],
                'timestamp' => now()->toIso8601String(),
            ]);
        }
    }

    /**
     * 통화 스냅샷 생성
     *
     * 주문 생성 시점의 통화 정보를 스냅샷으로 저장합니다.
     * 등록된 모든 통화의 환율 정보를 포함합니다.
     *
     * @return array 통화 스냅샷
     */
    protected function buildCurrencySnapshot(): array
    {
        $baseCurrency = $this->currencyConversionService->getDefaultCurrency();

        // 현재 요청의 통화 (기본값: 기본 통화)
        $currentCurrency = request()->header('X-Currency', $baseCurrency);

        // 등록된 모든 통화의 환율 정보 수집
        $currencies = $this->currencyConversionService->getCurrencySettings();
        $exchangeRates = [];
        $orderExchangeRate = 1.0;

        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $isDefault = $currency['is_default'] ?? false;
            $rate = $isDefault ? 1.0 : ($currency['exchange_rate'] ?? 0);
            $exchangeRates[$code] = [
                'rate' => $rate,
                'rounding_unit' => $currency['rounding_unit'] ?? ($isDefault ? '1' : '0.01'),
                'rounding_method' => $currency['rounding_method'] ?? 'round',
                'decimal_places' => $currency['decimal_places'] ?? ($isDefault ? 0 : 2),
            ];

            if ($code === $currentCurrency) {
                $orderExchangeRate = $rate;
            }
        }

        return [
            'base_currency' => $baseCurrency,
            'order_currency' => $currentCurrency,
            'exchange_rate' => $orderExchangeRate,
            'exchange_rates' => $exchangeRates,
            'snapshot_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * 주문 생성
     *
     * @param TempOrder $tempOrder 임시 주문
     * @param OrderCalculationResult $calculationResult 계산 결과
     * @param OrderStatusEnum $initialStatus 초기 상태
     * @param array $currencySnapshot 통화 스냅샷
     * @return Order
     */
    protected function createOrder(
        TempOrder $tempOrder,
        OrderCalculationResult $calculationResult,
        OrderStatusEnum $initialStatus,
        array $currencySnapshot
    ): Order {
        $summary = $calculationResult->summary;

        // 다중 통화 변환
        $mcAmounts = $this->buildOrderMultiCurrency($summary, $currencySnapshot);

        $orderData = [
            'user_id' => $tempOrder->user_id,
            'order_number' => $this->generateOrderNumber(),
            'order_status' => $initialStatus,
            'order_device' => $this->detectDevice(),
            'is_first_order' => $this->isFirstOrder($tempOrder->user_id),
            'ip_address' => request()->ip(),
            'currency' => $currencySnapshot['order_currency'] ?? 'KRW',
            'currency_snapshot' => $currencySnapshot,
            'subtotal_amount' => $summary->subtotal ?? 0,
            'total_discount_amount' => $summary->totalDiscount ?? 0,
            'total_product_coupon_discount_amount' => $summary->productCouponDiscount ?? 0,
            'total_order_coupon_discount_amount' => $summary->orderCouponDiscount ?? 0,
            'total_coupon_discount_amount' => ($summary->productCouponDiscount ?? 0) + ($summary->orderCouponDiscount ?? 0),
            'total_code_discount_amount' => $summary->codeDiscount ?? 0,
            'base_shipping_amount' => $summary->baseShippingTotal ?? 0,
            'extra_shipping_amount' => $summary->extraShippingTotal ?? 0,
            'shipping_discount_amount' => $summary->shippingDiscount ?? 0,
            'total_shipping_amount' => $summary->totalShipping ?? 0,
            'total_amount' => $summary->finalAmount ?? 0,
            'total_tax_amount' => $summary->taxableAmount ?? 0,
            'total_tax_free_amount' => $summary->taxFreeAmount ?? 0,
            'total_points_used_amount' => $summary->pointsUsed ?? 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
            'total_due_amount' => $summary->paymentAmount ?? 0,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'total_earned_points_amount' => $summary->pointsEarning ?? 0,
            'item_count' => count($calculationResult->items ?? []),
            'total_weight' => 0,
            'total_volume' => 0,
            'ordered_at' => Carbon::now(),
            'promotions_applied_snapshot' => $this->buildPromotionsAppliedSnapshot($calculationResult),
            'shipping_policy_applied_snapshot' => $this->buildShippingPolicyAppliedSnapshot($calculationResult),
            'order_meta' => $this->buildOrderMeta($tempOrder),
            // 다중 통화 필드
            'mc_subtotal_amount' => $mcAmounts['mc_subtotal_amount'],
            'mc_total_discount_amount' => $mcAmounts['mc_total_discount_amount'],
            'mc_total_product_coupon_discount_amount' => $mcAmounts['mc_total_product_coupon_discount_amount'],
            'mc_total_order_coupon_discount_amount' => $mcAmounts['mc_total_order_coupon_discount_amount'],
            'mc_total_coupon_discount_amount' => $mcAmounts['mc_total_coupon_discount_amount'],
            'mc_total_code_discount_amount' => $mcAmounts['mc_total_code_discount_amount'],
            'mc_base_shipping_amount' => $mcAmounts['mc_base_shipping_amount'],
            'mc_extra_shipping_amount' => $mcAmounts['mc_extra_shipping_amount'],
            'mc_shipping_discount_amount' => $mcAmounts['mc_shipping_discount_amount'],
            'mc_total_shipping_amount' => $mcAmounts['mc_total_shipping_amount'],
            'mc_total_points_used_amount' => $mcAmounts['mc_total_points_used_amount'],
            'mc_total_deposit_used_amount' => $mcAmounts['mc_total_deposit_used_amount'],
            'mc_total_tax_amount' => $mcAmounts['mc_total_tax_amount'],
            'mc_total_tax_free_amount' => $mcAmounts['mc_total_tax_free_amount'],
            'mc_total_amount' => $mcAmounts['mc_total_amount'],
            'mc_total_paid_amount' => $mcAmounts['mc_total_paid_amount'],
        ];

        // 훅을 통한 데이터 가공
        $orderData = HookManager::applyFilters('sirsoft-ecommerce.order.filter_create_data', $orderData, $tempOrder);

        return $this->orderRepository->create($orderData);
    }

    /**
     * 주문 다중 통화 데이터 생성
     *
     * 등록된 모든 통화로 변환하여 저장합니다.
     *
     * @param object $summary 계산 결과 요약
     * @param array $currencySnapshot 통화 스냅샷
     * @return array 다중 통화 데이터
     */
    protected function buildOrderMultiCurrency(object $summary, array $currencySnapshot): array
    {
        $amounts = [
            'subtotal' => $summary->subtotal ?? 0,
            'totalDiscount' => $summary->totalDiscount ?? 0,
            'productCouponDiscount' => $summary->productCouponDiscount ?? 0,
            'orderCouponDiscount' => $summary->orderCouponDiscount ?? 0,
            'couponDiscount' => ($summary->productCouponDiscount ?? 0) + ($summary->orderCouponDiscount ?? 0),
            'codeDiscount' => $summary->codeDiscount ?? 0,
            'baseShipping' => $summary->baseShippingTotal ?? 0,
            'extraShipping' => $summary->extraShippingTotal ?? 0,
            'shippingDiscount' => $summary->shippingDiscount ?? 0,
            'totalShipping' => $summary->totalShipping ?? 0,
            'pointsUsed' => $summary->pointsUsed ?? 0,
            'taxableAmount' => $summary->taxableAmount ?? 0,
            'taxFreeAmount' => $summary->taxFreeAmount ?? 0,
            'finalAmount' => $summary->finalAmount ?? 0,
        ];

        $convertAmount = $this->buildAllCurrencyConverter($currencySnapshot);

        return [
            'mc_subtotal_amount' => $convertAmount($amounts['subtotal']),
            'mc_total_discount_amount' => $convertAmount($amounts['totalDiscount']),
            'mc_total_product_coupon_discount_amount' => $convertAmount($amounts['productCouponDiscount']),
            'mc_total_order_coupon_discount_amount' => $convertAmount($amounts['orderCouponDiscount']),
            'mc_total_coupon_discount_amount' => $convertAmount($amounts['couponDiscount']),
            'mc_total_code_discount_amount' => $convertAmount($amounts['codeDiscount']),
            'mc_base_shipping_amount' => $convertAmount($amounts['baseShipping']),
            'mc_extra_shipping_amount' => $convertAmount($amounts['extraShipping']),
            'mc_shipping_discount_amount' => $convertAmount($amounts['shippingDiscount']),
            'mc_total_shipping_amount' => $convertAmount($amounts['totalShipping']),
            'mc_total_points_used_amount' => $convertAmount($amounts['pointsUsed']),
            'mc_total_deposit_used_amount' => $convertAmount(0),
            'mc_total_tax_amount' => $convertAmount($amounts['taxableAmount']),
            'mc_total_tax_free_amount' => $convertAmount($amounts['taxFreeAmount']),
            'mc_total_amount' => $convertAmount($amounts['finalAmount']),
            'mc_total_paid_amount' => $convertAmount(0),
        ];
    }

    /**
     * 주문 옵션 생성
     *
     * @param Order $order 주문
     * @param TempOrder $tempOrder 임시 주문
     * @param OrderCalculationResult $calculationResult 계산 결과
     * @param array $currencySnapshot 통화 스냅샷
     * @return void
     */
    /**
     * 주문 옵션 생성
     *
     * @param Order $order 주문
     * @param TempOrder $tempOrder 임시 주문
     * @param OrderCalculationResult $calculationResult 계산 결과
     * @param array $currencySnapshot 통화 스냅샷
     * @return array<int, \Modules\Sirsoft\Ecommerce\Models\OrderOption> productOptionId => OrderOption 매핑
     */
    protected function createOrderOptions(
        Order $order,
        TempOrder $tempOrder,
        OrderCalculationResult $calculationResult,
        array $currencySnapshot
    ): array {
        $createdOptions = [];

        foreach ($calculationResult->items as $item) {
            // 상품/옵션 스냅샷 생성
            $product = $this->productRepository->find($item->productId);
            $productOption = $this->productOptionRepository->findById($item->productOptionId);

            $productSnapshot = $product ? $product->toSnapshotArray() : [];
            $optionSnapshot = $productOption ? $productOption->toSnapshotArray() : [];

            // 다중 통화 변환
            $mcAmounts = $this->buildOptionMultiCurrency($item, $currencySnapshot);

            // product_name: JSON 형식 (다국어 지원)
            $productName = $product ? $product->name : ($item->productName ?? '');
            $productNameJson = is_array($productName) ? $productName : ['ko' => $productName, 'en' => $productName];

            // 적용 프로모션 스냅샷 (toArray()로 snake_case 변환 필수)
            $appliedPromotions = $item->appliedPromotions?->toArray() ?? [];

            $orderOption = $order->options()->create([
                'product_id' => $item->productId,
                'product_option_id' => $item->productOptionId,
                'option_status' => OrderStatusEnum::PENDING_ORDER,
                'sku' => $productOption->sku ?? null,
                'product_name' => $productNameJson,
                'product_option_name' => $productOption?->option_name ?? [],
                'option_name' => $productOption?->option_name ?? [],
                'option_value' => $this->buildOptionValueSummary($productOption),
                'quantity' => $item->quantity,
                'unit_weight' => $productOption->weight ?? 0,
                'unit_volume' => $productOption->volume ?? 0,
                'subtotal_weight' => ($productOption->weight ?? 0) * $item->quantity,
                'subtotal_volume' => ($productOption->volume ?? 0) * $item->quantity,
                'unit_price' => $item->unitPrice,
                'subtotal_price' => $item->subtotal,
                'subtotal_discount_amount' => $item->getTotalDiscount(),
                'product_coupon_discount_amount' => $item->productCouponDiscountAmount ?? 0,
                'order_coupon_discount_amount' => $item->orderCouponDiscountShare ?? 0,
                'coupon_discount_amount' => $item->productCouponDiscountAmount ?? 0,
                'code_discount_amount' => $item->codeDiscountAmount ?? 0,
                'subtotal_points_used_amount' => $item->pointsUsedShare ?? 0,
                'subtotal_deposit_used_amount' => $item->depositUsedShare ?? 0,
                'subtotal_paid_amount' => $item->finalAmount ?? 0,
                'subtotal_tax_amount' => $item->taxableAmount ?? 0,
                'subtotal_tax_free_amount' => $item->taxFreeAmount ?? 0,
                'subtotal_earned_points_amount' => $item->pointsEarning ?? 0,
                'product_snapshot' => $productSnapshot,
                'option_snapshot' => $optionSnapshot,
                'promotions_applied_snapshot' => $appliedPromotions,
                // 다중 통화 필드
                'mc_unit_price' => $mcAmounts['mc_unit_price'],
                'mc_subtotal_price' => $mcAmounts['mc_subtotal_price'],
                'mc_product_coupon_discount_amount' => $mcAmounts['mc_product_coupon_discount_amount'],
                'mc_order_coupon_discount_amount' => $mcAmounts['mc_order_coupon_discount_amount'],
                'mc_coupon_discount_amount' => $mcAmounts['mc_coupon_discount_amount'],
                'mc_code_discount_amount' => $mcAmounts['mc_code_discount_amount'],
                'mc_subtotal_points_used_amount' => $mcAmounts['mc_subtotal_points_used_amount'],
                'mc_subtotal_deposit_used_amount' => $mcAmounts['mc_subtotal_deposit_used_amount'],
                'mc_subtotal_tax_amount' => $mcAmounts['mc_subtotal_tax_amount'],
                'mc_subtotal_tax_free_amount' => $mcAmounts['mc_subtotal_tax_free_amount'],
                'mc_final_amount' => $mcAmounts['mc_final_amount'],
            ]);

            // productOptionId → OrderOption 매핑 저장
            $createdOptions[$item->productOptionId] = $orderOption;
        }

        return $createdOptions;
    }

    /**
     * 옵션값 요약 문자열 생성 (다국어)
     *
     * ProductOption의 option_values를 로케일별 요약 문자열로 변환합니다.
     * 예: {"ko": "색상: 빨강, 사이즈: L", "en": "Color: Red, Size: L"}
     *
     * @param \Modules\Sirsoft\Ecommerce\Models\ProductOption|null $productOption 상품 옵션
     * @return array 다국어 요약 문자열 배열
     */
    protected function buildOptionValueSummary(?\Modules\Sirsoft\Ecommerce\Models\ProductOption $productOption): array
    {
        if (! $productOption || empty($productOption->option_values)) {
            return [];
        }

        $values = $productOption->option_values;

        // 새 구조: [{"key": {"ko": "색상"}, "value": {"ko": "빨강"}}]
        if (isset($values[0]['key'])) {
            $locales = config('app.supported_locales', ['ko', 'en']);
            $result = [];

            foreach ($locales as $locale) {
                $parts = [];
                foreach ($values as $item) {
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
        foreach ($values as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return ['ko' => implode(', ', $parts)];
    }

    /**
     * 주문 옵션 다중 통화 데이터 생성
     *
     * 등록된 모든 통화로 변환하여 저장합니다.
     *
     * @param object $item 계산 결과 아이템
     * @param array $currencySnapshot 통화 스냅샷
     * @return array 다중 통화 데이터
     */
    protected function buildOptionMultiCurrency(object $item, array $currencySnapshot): array
    {
        $convertAmount = $this->buildAllCurrencyConverter($currencySnapshot);

        return [
            'mc_unit_price' => $convertAmount($item->unitPrice ?? 0),
            'mc_subtotal_price' => $convertAmount($item->subtotal ?? 0),
            'mc_product_coupon_discount_amount' => $convertAmount($item->productCouponDiscountAmount ?? 0),
            'mc_order_coupon_discount_amount' => $convertAmount($item->orderCouponDiscountShare ?? 0),
            'mc_coupon_discount_amount' => $convertAmount($item->productCouponDiscountAmount ?? 0),
            'mc_code_discount_amount' => $convertAmount($item->codeDiscountAmount ?? 0),
            'mc_subtotal_points_used_amount' => $convertAmount($item->pointsUsedShare ?? 0),
            'mc_subtotal_deposit_used_amount' => $convertAmount(0),
            'mc_subtotal_tax_amount' => $convertAmount($item->taxableAmount ?? 0),
            'mc_subtotal_tax_free_amount' => $convertAmount($item->taxFreeAmount ?? 0),
            'mc_final_amount' => $convertAmount($item->finalAmount ?? 0),
        ];
    }

    /**
     * 주문 주소 생성
     *
     * @param Order $order 주문
     * @param array $ordererInfo 주문자 정보
     * @param array $shippingInfo 배송지 정보
     * @param string|null $shippingMemo 배송 메모
     * @return void
     */
    protected function createOrderAddresses(
        Order $order,
        array $ordererInfo,
        array $shippingInfo,
        ?string $shippingMemo
    ): void {
        // 배송지 주소 생성
        $order->addresses()->create([
            'address_type' => 'shipping',
            'orderer_name' => $ordererInfo['name'] ?? '',
            'orderer_phone' => $ordererInfo['phone'] ?? '',
            'orderer_email' => $ordererInfo['email'] ?? '',
            'recipient_name' => $shippingInfo['recipient_name'] ?? '',
            'recipient_phone' => $shippingInfo['recipient_phone'] ?? $shippingInfo['phone'] ?? '',
            'recipient_country_code' => $shippingInfo['country_code'] ?? 'KR',
            'zipcode' => $shippingInfo['zipcode'] ?? $shippingInfo['zonecode'] ?? '',
            'address' => $shippingInfo['address'] ?? '',
            'address_detail' => $shippingInfo['address_detail'] ?? $shippingInfo['detail_address'] ?? '',
            'delivery_memo' => $shippingMemo,
        ]);
    }

    /**
     * 주문 결제 정보 생성
     *
     * @param Order $order 주문
     * @param string $paymentMethod 결제 수단
     * @param string|null $depositorName 입금자명
     * @param array|null $dbankInfo 무통장 수동입금 정보 (dbank 결제 시)
     * @param OrderCalculationResult $calculationResult 계산 결과
     * @param array $currencySnapshot 통화 스냅샷
     * @return void
     */
    protected function createOrderPayment(
        Order $order,
        string $paymentMethod,
        ?string $depositorName,
        ?array $dbankInfo,
        OrderCalculationResult $calculationResult,
        array $currencySnapshot
    ): void {
        $paymentAmount = $calculationResult->summary->paymentAmount ?? $calculationResult->summary->finalAmount ?? 0;

        // 다중 통화 변환
        $mcPaidAmount = $this->buildMultiCurrencyAmount(0, $currencySnapshot);
        $mcCancelledAmount = $this->buildMultiCurrencyAmount(0, $currencySnapshot);

        $paymentData = [
            'payment_method' => $paymentMethod,
            'payment_status' => PaymentStatusEnum::READY,
            'pg_provider' => $this->determinePgProvider($paymentMethod),
            'merchant_order_id' => $order->order_number.'_'.time(),
            'paid_amount_local' => 0,
            'paid_amount_base' => $paymentAmount,
            'currency' => $currencySnapshot['order_currency'] ?? 'KRW',
            'currency_snapshot' => $currencySnapshot,
            'mc_paid_amount' => $mcPaidAmount,
            'mc_cancelled_amount' => $mcCancelledAmount,
        ];

        // 무통장입금 (PG 가상계좌) 정보
        if ($paymentMethod === 'vbank') {
            $paymentData['vbank_holder'] = $depositorName;
            $paymentData['vbank_due_at'] = Carbon::now()->addDays(
                module_setting('sirsoft-ecommerce', 'order_settings.vbank_due_days', 3)
            );
        }

        // 무통장입금 (수동 입금) 정보
        if ($paymentMethod === 'dbank' && $dbankInfo) {
            $bankCode = $dbankInfo['bank_code'] ?? null;

            // bank_name이 없으면 설정에서 은행코드 기반으로 조회
            $bankName = $dbankInfo['bank_name'] ?? null;
            if (! $bankName && $bankCode) {
                $orderSettings = module_setting('sirsoft-ecommerce', 'order_settings');
                $banks = collect($orderSettings['banks'] ?? []);
                $bank = $banks->firstWhere('code', $bankCode);
                $bankName = $bank ? ($bank['name'][app()->getLocale()] ?? $bank['name']['ko'] ?? $bankCode) : $bankCode;
            }

            $paymentData['dbank_code'] = $bankCode;
            $paymentData['dbank_name'] = $bankName;
            $paymentData['dbank_account'] = $dbankInfo['account_number'] ?? null;
            $paymentData['dbank_holder'] = $dbankInfo['account_holder'] ?? null;
            $paymentData['depositor_name'] = $depositorName ?? $dbankInfo['depositor_name'] ?? null;
            $paymentData['deposit_due_at'] = Carbon::now()->addDays(
                $dbankInfo['due_days'] ?? module_setting('sirsoft-ecommerce', 'order_settings.dbank_due_days', 7)
            );
        }

        $order->payment()->create($paymentData);
    }

    /**
     * PG 제공자 결정
     *
     * @param string $paymentMethod 결제 수단
     * @return string PG 제공자
     */
    public function determinePgProvider(string $paymentMethod): string
    {
        $enum = PaymentMethodEnum::tryFrom($paymentMethod);

        // PG 불필요 결제수단
        if ($enum && ! $enum->needsPgProvider()) {
            return $paymentMethod === 'dbank' ? 'manual' : 'internal';
        }

        // 설정에서 조회: 개별 오버라이드 > 기본 PG
        $methodConfig = $this->settingsService->getPaymentMethodConfig($paymentMethod);
        $provider = $methodConfig['pg_provider']
            ?? $this->settingsService->getSetting('order_settings.default_pg_provider');

        return $provider ?? 'none';
    }

    /**
     * 단일 금액 다중 통화 변환
     *
     * 등록된 모든 통화로 변환하여 반환합니다.
     *
     * @param float $amount 금액
     * @param array $currencySnapshot 통화 스냅샷
     * @return array 다중 통화 데이터
     */
    protected function buildMultiCurrencyAmount(float $amount, array $currencySnapshot): array
    {
        $convertAmount = $this->buildAllCurrencyConverter($currencySnapshot);

        return $convertAmount($amount);
    }

    /**
     * 모든 등록 통화로 변환하는 클로저 생성
     *
     * CurrencyConversionService의 convertToMultiCurrency를 활용하여
     * 등록된 모든 통화 금액을 {통화코드: 금액} 형식으로 반환합니다.
     *
     * @param array $currencySnapshot 통화 스냅샷
     * @return \Closure(float): array 변환 클로저
     */
    protected function buildAllCurrencyConverter(array $currencySnapshot): \Closure
    {
        return function (float $amount) use ($currencySnapshot): array {
            return $this->currencyConversionService->convertToMultiCurrencyWithSnapshot(
                (int) $amount,
                $currencySnapshot
            );
        };
    }

    /**
     * 주문 배송 정보 생성
     *
     * @param Order $order 주문
     * @param TempOrder $tempOrder 임시 주문
     * @param OrderCalculationResult $calculationResult 계산 결과
     * @param array $currencySnapshot 통화 스냅샷
     * @param array $createdOptions productOptionId => OrderOption 매핑
     * @return void
     */
    protected function createOrderShippings(
        Order $order,
        TempOrder $tempOrder,
        OrderCalculationResult $calculationResult,
        array $currencySnapshot,
        array $createdOptions = []
    ): void {
        foreach ($calculationResult->items as $item) {
            // 해당 아이템의 OrderOption 찾기
            $orderOption = $createdOptions[$item->productOptionId] ?? null;

            if ($orderOption === null) {
                continue; // 주문 옵션이 없으면 배송 정보 생성 불가
            }

            // 배송 정책이 있는 경우에만 배송 정보 생성
            $shippingPolicy = $item->appliedShippingPolicy;
            $totalShippingFee = $shippingPolicy->totalShippingAmount ?? 0;

            // 배송 유형 결정 (국내/해외)
            $shippingType = $this->determineShippingType($shippingPolicy, $tempOrder);

            $order->shippings()->create([
                'order_option_id' => $orderOption->id,
                'shipping_policy_id' => $shippingPolicy->policyId ?? null,
                'shipping_status' => ShippingStatusEnum::PENDING->value,
                'shipping_type' => $shippingType,
                'base_shipping_amount' => $shippingPolicy->shippingAmount ?? 0,
                'extra_shipping_amount' => $shippingPolicy->extraShippingAmount ?? 0,
                'total_shipping_amount' => $totalShippingFee,
                'shipping_discount_amount' => $shippingPolicy->shippingDiscountAmount ?? 0,
                'is_remote_area' => ($shippingPolicy->extraShippingAmount ?? 0) > 0,
                'delivery_policy_snapshot' => $shippingPolicy->policySnapshot ?? null,
                'currency_snapshot' => $currencySnapshot,
                'mc_base_shipping_amount' => $this->buildMultiCurrencyAmount($shippingPolicy->shippingAmount ?? 0, $currencySnapshot),
                'mc_extra_shipping_amount' => $this->buildMultiCurrencyAmount($shippingPolicy->extraShippingAmount ?? 0, $currencySnapshot),
                'mc_total_shipping_amount' => $this->buildMultiCurrencyAmount($totalShippingFee, $currencySnapshot),
                'mc_shipping_discount_amount' => $this->buildMultiCurrencyAmount($shippingPolicy->shippingDiscountAmount ?? 0, $currencySnapshot),
                'mc_return_shipping_amount' => $this->buildMultiCurrencyAmount(0, $currencySnapshot),
            ]);
        }
    }

    /**
     * 배송 유형 결정
     *
     * @param object|null $shippingPolicy 배송 정책
     * @param TempOrder $tempOrder 임시 주문
     * @return string
     */
    protected function determineShippingType(?object $shippingPolicy, TempOrder $tempOrder): string
    {
        // 배송 정책에 type이 있으면 사용
        if ($shippingPolicy && ! empty($shippingPolicy->type)) {
            $validCodes = ShippingType::where('is_active', true)->pluck('code')->toArray();
            if (in_array($shippingPolicy->type, $validCodes)) {
                return $shippingPolicy->type;
            }
        }

        // 배송지 국가로 판단 (임시 주문에서)
        $shippingCountry = $tempOrder->shipping_address['country_code'] ?? 'KR';

        if ($shippingCountry !== 'KR') {
            return ShippingType::where('category', 'international')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->value('code') ?? 'international_standard';
        }

        return ShippingType::where('category', 'domestic')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->value('code') ?? 'parcel';
    }

    /**
     * 주문번호 생성
     *
     * SequenceService를 사용하여 타임스탬프 기반 주문번호를 생성합니다.
     * 형식: 20260208-1435226549 (Ymd-His + 밀리초3자리 + 랜덤1자리)
     * DB 트랜잭션 + FOR UPDATE 락으로 동시성 제어됩니다.
     *
     * @return string 주문번호
     */
    protected function generateOrderNumber(): string
    {
        return $this->sequenceService->generateCode(SequenceType::ORDER);
    }

    /**
     * 디바이스 타입 감지
     *
     * @return DeviceTypeEnum
     */
    protected function detectDevice(): DeviceTypeEnum
    {
        $userAgent = request()->userAgent();

        if (preg_match('/mobile|android|iphone|ipad/i', $userAgent)) {
            return DeviceTypeEnum::MOBILE;
        }

        return DeviceTypeEnum::PC;
    }

    /**
     * 첫 주문 여부 확인
     *
     * @param int|null $userId 회원 ID
     * @return bool
     */
    protected function isFirstOrder(?int $userId): bool
    {
        if ($userId === null) {
            return true;
        }

        return ! $this->orderRepository->hasOrderByUser($userId);
    }

    /**
     * 임시 주문에서 계산 입력 DTO를 구성합니다.
     *
     * TempOrderService::getTempOrderWithCalculation()의 패턴을 따릅니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @return CalculationInput
     */
    protected function buildCalculationInputFromTempOrder(TempOrder $tempOrder): CalculationInput
    {
        // 저장된 아이템으로 CalculationItem 배열 생성
        $items = $tempOrder->items ?? [];
        $calculationItems = array_map(
            fn (array $item) => CalculationItem::fromArray($item),
            $items
        );

        // 저장된 배송 주소
        $shippingAddress = null;
        if ($tempOrder->getShippingAddress() !== null) {
            $shippingAddress = ShippingAddress::fromArray($tempOrder->getShippingAddress());
        }

        // 저장된 프로모션 정보에서 쿠폰 ID 배열 구성
        $promotions = $tempOrder->getPromotions();
        $couponIssueIds = array_filter([
            $promotions['order_coupon_issue_id'] ?? null,
            $promotions['shipping_coupon_issue_id'] ?? null,
        ]);

        return new CalculationInput(
            items: $calculationItems,
            couponIssueIds: $couponIssueIds,
            itemCoupons: $promotions['item_coupons'] ?? [],
            usePoints: $tempOrder->getUsedPoints(),
            shippingAddress: $shippingAddress,
        );
    }

    /**
     * 적용된 프로모션 스냅샷을 구성합니다.
     *
     * @param  OrderCalculationResult  $result  계산 결과
     * @return array
     */
    protected function buildPromotionsAppliedSnapshot(OrderCalculationResult $result): array
    {
        $snapshot = $result->promotions->toArray();

        // 플러그인이 스냅샷에 자체 할인 데이터를 추가할 수 있는 훅
        // 예: 유입할인 플러그인이 { "referral_discount": { "amount": 3000 } } 추가
        $snapshot = HookManager::applyFilters(
            'sirsoft-ecommerce.calculation.filter_promotions_snapshot',
            $snapshot,
            $result
        );

        return $snapshot;
    }

    /**
     * 적용된 배송정책 스냅샷을 구성합니다.
     *
     * @param  OrderCalculationResult  $result  계산 결과
     * @return array
     */
    protected function buildShippingPolicyAppliedSnapshot(OrderCalculationResult $result): array
    {
        $policies = [];
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy) {
                $policies[] = [
                    'product_option_id' => $item->productOptionId,
                    'policy' => $item->appliedShippingPolicy->toArray(),
                ];
            }
        }

        return $policies;
    }

    /**
     * 주문 메타데이터를 구성합니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @return array
     */
    protected function buildOrderMeta(TempOrder $tempOrder): array
    {
        $cartItems = array_filter(
            array_map(function ($item) {
                return isset($item['cart_id']) ? [
                    'cart_id' => $item['cart_id'],
                    'quantity' => $item['quantity'] ?? 0,
                ] : null;
            }, $tempOrder->items ?? [])
        );

        return [
            'temp_order_id' => $tempOrder->id,
            'calculation_input' => $tempOrder->calculation_input,
            'cart_items' => array_values($cartItems),
        ];
    }

    /**
     * 주문된 장바구니 아이템 처리 (삭제 또는 수량 차감)
     *
     * 장바구니 수량 > 주문 수량: 수량 차감
     * 장바구니 수량 <= 주문 수량: 삭제
     *
     * @param Order $order 주문
     * @return void
     */
    protected function clearOrderedCartItems(Order $order): void
    {
        $cartItems = $order->order_meta['cart_items'] ?? [];

        if (empty($cartItems)) {
            return;
        }

        $cartIds = array_column($cartItems, 'cart_id');
        $orderedQtyMap = [];
        foreach ($cartItems as $item) {
            $orderedQtyMap[$item['cart_id']] = $item['quantity'];
        }

        $existingCarts = $this->cartRepository->findByIds($cartIds);

        $deleteIds = [];
        foreach ($existingCarts as $cart) {
            $orderedQty = $orderedQtyMap[$cart->id] ?? 0;
            $remainingQty = $cart->quantity - $orderedQty;

            if ($remainingQty > 0) {
                $this->cartRepository->update($cart, ['quantity' => $remainingQty]);
            } else {
                $deleteIds[] = $cart->id;
            }
        }

        if (! empty($deleteIds)) {
            $this->cartRepository->deleteByIds($deleteIds);
        }
    }

    /**
     * 결제 완료 후 주문 상태 변경
     *
     * PG 결제의 경우 $pgAmount를 전달하면 금액 검증을 수행합니다.
     * 기존 무통장입금 등 $pgAmount 없이 호출하면 검증 없이 상태 전환됩니다.
     *
     * @param Order $order 주문
     * @param array $paymentData 결제 데이터
     * @param int|null $pgAmount PG사에서 전달받은 결제금액 (null이면 금액 검증 생략)
     * @return Order
     * @throws PaymentAmountMismatchException 금액 불일치 시
     */
    public function completePayment(Order $order, array $paymentData = [], ?int $pgAmount = null): Order
    {
        // PG 금액이 전달된 경우 금액 검증 (컴포넌트 합산 + PG 금액 일치)
        if ($pgAmount !== null) {
            $this->validatePaymentAmount($order, $pgAmount);
        }

        HookManager::doAction('sirsoft-ecommerce.order.before_payment_complete', $order, $paymentData);

        DB::transaction(function () use ($order, $paymentData) {
            $paidAmount = $order->total_due_amount;
            $currencySnapshot = $order->currency_snapshot ?? $this->buildCurrencySnapshot();

            // 다중 통화 결제 금액 계산
            $mcTotalPaidAmount = $this->buildMultiCurrencyAmount($paidAmount, $currencySnapshot);

            // 주문 상태 변경
            $order->update([
                'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'paid_at' => Carbon::now(),
                'total_paid_amount' => $paidAmount,
                'total_due_amount' => 0,
                'mc_total_paid_amount' => $mcTotalPaidAmount,
            ]);

            // 결제 정보 업데이트 (PG 응답 필드 확장)
            $order->payment()->update(array_filter([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_at' => Carbon::now(),
                'paid_amount_local' => $paidAmount,
                'mc_paid_amount' => $mcTotalPaidAmount,
                // 기본 PG 정보
                'transaction_id' => $paymentData['transaction_id'] ?? null,
                // 카드 결제 정보
                'card_approval_number' => $paymentData['card_approval_number'] ?? null,
                'card_number_masked' => $paymentData['card_number_masked'] ?? null,
                'card_name' => $paymentData['card_name'] ?? null,
                'card_installment_months' => $paymentData['card_installment_months'] ?? null,
                'is_interest_free' => $paymentData['is_interest_free'] ?? null,
                // 간편결제 PG 정보
                'embedded_pg_provider' => $paymentData['embedded_pg_provider'] ?? null,
                // 영수증/메타
                'receipt_url' => $paymentData['receipt_url'] ?? null,
                'payment_meta' => $paymentData['payment_meta'] ?? null,
                'payment_device' => $paymentData['payment_device'] ?? null,
            ], fn ($v) => $v !== null));

            // 마일리지 적립
            if ($order->total_earned_points_amount > 0) {
                HookManager::doAction('sirsoft-ecommerce.mileage.earn', $order->total_earned_points_amount, $order);
            }

            // 재고 차감 + 장바구니 처리 (payment_complete 타이밍: 트랜잭션 내부에서 실행)
            $paymentMethodId = $order->payment->payment_method->value;
            $timing = $this->settingsService->getStockDeductionTiming($paymentMethodId);
            if ($timing === 'payment_complete') {
                $this->stockService->deductStock($order->load('options'));
                $this->clearOrderedCartItems($order);
            }
        });

        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $order);

        // 주문 확인 알림 훅 (결제 완료 = 주문 확인 시점)
        HookManager::doAction('sirsoft-ecommerce.order.after_confirm', $order);

        // 임시주문 정리 (PG 결제 완료 시점에 삭제, 이미 삭제된 경우 no-op)
        if ($order->user_id) {
            $this->tempOrderService->deleteTempOrder($order->user_id, null);
        }

        // PG 결제 완료 시 배송지 자동 저장
        $orderMeta = $order->order_meta ?? [];
        if ($order->user_id && ($orderMeta['save_shipping_address'] ?? false)) {
            try {
                $shippingData = $orderMeta['shipping_info_for_save'] ?? [];
                $name = $this->userAddressService->generateUniqueName(
                    $order->user_id,
                    __('sirsoft-ecommerce::messages.address.auto_saved_label')
                );
                $this->userAddressService->createAddress([
                    'user_id' => $order->user_id,
                    'name' => $name,
                    'recipient_name' => $shippingData['recipient_name'] ?? '',
                    'recipient_phone' => $shippingData['recipient_phone'] ?? '',
                    'country_code' => $shippingData['country_code'] ?? 'KR',
                    'zipcode' => $shippingData['zipcode'] ?? $shippingData['intl_postal_code'] ?? '',
                    'address' => $shippingData['address'] ?? $shippingData['address_line_1'] ?? '',
                    'address_detail' => $shippingData['address_detail'] ?? $shippingData['address_line_2'] ?? '',
                    'region' => $shippingData['region'] ?? $shippingData['intl_state'] ?? '',
                    'city' => $shippingData['city'] ?? $shippingData['intl_city'] ?? '',
                ]);

                // 메타에서 배송지 저장 플래그 제거
                $order->update([
                    'order_meta' => array_diff_key($orderMeta, array_flip(['save_shipping_address', 'shipping_info_for_save'])),
                ]);
            } catch (\Exception $e) {
                Log::warning('Auto save shipping address failed on payment complete', [
                    'user_id' => $order->user_id,
                    'order_id' => $order->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $order->fresh();
    }

    /**
     * PG 결제 금액 검증 (2단계)
     *
     * 1단계: 주문 개별 금액 컴포넌트 합산 → total_amount 일치 확인 (DB 변조 감지)
     * 2단계: 주문 total_amount → PG 콜백 금액 일치 확인
     *
     * @param Order $order 주문
     * @param int $pgAmount PG사에서 전달받은 결제금액
     * @return void
     * @throws PaymentAmountMismatchException 금액 불일치 시
     */
    protected function validatePaymentAmount(Order $order, int $pgAmount): void
    {
        // 1단계: 컴포넌트 합산 검증 (DB 변조 감지)
        $calculatedTotal = round(
            $order->subtotal_amount
            - $order->total_product_coupon_discount_amount
            - $order->total_order_coupon_discount_amount
            - $order->total_code_discount_amount
            + $order->base_shipping_amount
            + $order->extra_shipping_amount
            - $order->shipping_discount_amount
            - $order->total_points_used_amount,
            2
        );
        $storedTotal = round($order->total_amount, 2);

        if ($calculatedTotal !== $storedTotal) {
            throw new PaymentAmountMismatchException(
                $storedTotal,
                $calculatedTotal,
                [
                    'stage' => 'component_verification',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]
            );
        }

        // 2단계: PG 금액 일치 검증
        if ((int) $storedTotal !== $pgAmount) {
            throw new PaymentAmountMismatchException(
                $pgAmount,
                (int) $storedTotal,
                [
                    'stage' => 'pg_amount_verification',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]
            );
        }
    }

    /**
     * PG 결제 실패 처리
     *
     * 결제 대기 상태의 주문을 취소 상태로 전환하고
     * 결제 실패 정보를 메타데이터에 기록합니다.
     *
     * @param Order $order 주문
     * @param string $errorCode 에러 코드
     * @param string $errorMessage 에러 메시지
     * @return Order
     */
    public function failPayment(Order $order, string $errorCode, string $errorMessage): Order
    {
        // 결제 전 상태인 경우에만 처리
        if (! $order->order_status->isBeforePayment()) {
            return $order;
        }

        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => array_merge($order->order_meta ?? [], [
                'payment_failure_code' => $errorCode,
                'payment_failure_message' => $errorMessage,
                'payment_failed_at' => Carbon::now()->toIso8601String(),
            ]),
        ]);

        HookManager::doAction('sirsoft-ecommerce.order.payment_failed', $order, $errorCode, $errorMessage);

        return $order->fresh();
    }

    /**
     * 결제 취소 이력 기록
     *
     * 유저가 PG 결제창을 닫았을 때 order_payments의 상태와 이력을 업데이트합니다.
     * - payment_status → 'cancelled'
     * - cancel_history에 취소 이력 추가 (PG사 응답 코드/메시지 포함)
     * 주문 상태(order_status)는 변경하지 않습니다 (pending_order 유지).
     *
     * @param Order $order 주문
     * @param string|null $cancelCode PG사 취소 코드 (예: USER_CANCEL)
     * @param string|null $cancelMessage PG사 취소 메시지
     * @return Order
     */
    public function recordPaymentCancellation(Order $order, ?string $cancelCode = null, ?string $cancelMessage = null): Order
    {
        $payment = $order->payment;

        if (! $payment) {
            return $order;
        }

        $cancelHistory = $payment->cancel_history ?? [];
        $cancelHistory[] = [
            'cancel_code' => $cancelCode ?? 'UNKNOWN',
            'cancel_message' => $cancelMessage,
            'cancelled_at' => Carbon::now()->toIso8601String(),
        ];

        $payment->update([
            'payment_status' => PaymentStatusEnum::CANCELLED->value,
            'cancelled_at' => Carbon::now(),
            'cancel_history' => $cancelHistory,
        ]);

        return $order->fresh();
    }

    /**
     * 주문번호로 주문 조회
     *
     * @param string $orderNumber 주문번호
     * @return Order|null
     */
    public function findByOrderNumber(string $orderNumber): ?Order
    {
        return $this->orderRepository->findByOrderNumber($orderNumber);
    }

    /**
     * 주문 취소
     *
     * OrderCancellationService로 위임합니다.
     *
     * @param  Order  $order  주문
     * @param  string|null  $reason  취소 사유
     * @param  int|null  $cancelledBy  취소 요청자 ID
     * @return Order
     *
     * @throws \Exception
     */
    public function cancelOrder(Order $order, ?string $reason = null, ?int $cancelledBy = null): Order
    {
        $cancellationService = app(OrderCancellationService::class);
        $result = $cancellationService->cancelOrder($order, $reason, null, $cancelledBy);

        return $result->order;
    }
}
