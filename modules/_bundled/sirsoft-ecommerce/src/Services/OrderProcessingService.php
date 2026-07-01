<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Exceptions\UnsupportedPaymentCurrencyException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;

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
        protected CartRepositoryInterface $cartRepository,
        protected PurchaseEligibilityService $purchaseEligibilityService,
        protected UserMileageService $userMileageService,
        protected ShippingTypeRepositoryInterface $shippingTypeRepository,
        protected ShippingPolicyResolver $shippingPolicyResolver
    ) {}

    /**
     * 임시 주문에서 실제 주문 생성
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  array  $ordererInfo  주문자 정보 (name, phone, email)
     * @param  array  $shippingInfo  배송지 정보
     * @param  string  $paymentMethod  결제 수단 (card, vbank, dbank 등)
     * @param  float  $expectedTotalAmount  프론트엔드 결제예정금액 (금액 검증용)
     * @param  string|null  $shippingMemo  배송 메모
     * @param  string|null  $depositorName  입금자명 (무통장입금 시)
     * @param  array|null  $dbankInfo  무통장 수동입금 정보 (dbank 결제 시)
     * @param  string|null  $guestLookupPassword  비회원 조회 비밀번호 평문 (해시로 저장, 회원 주문은 null)
     * @return Order 생성된 주문
     *
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
        ?array $dbankInfo = null,
        ?string $guestLookupPassword = null
    ): Order {
        // 생성 전 훅
        HookManager::doAction('sirsoft-ecommerce.order.before_create', $tempOrder, $ordererInfo, $shippingInfo, $paymentMethod);

        // 구매 대상 제한 최종 차단 (회원/비회원 공통, 프론트 우회 불가 방어선)
        $this->validatePurchaseEligibility($tempOrder);

        // 배송국가 미지원 최종 차단 (D1 — 혼재 시 전체 차단, 프론트 우회 불가 방어선)
        $this->validateShippingCountrySupported($tempOrder, $shippingInfo['country_code'] ?? null);

        // 저장된 파라미터로 재계산 수행
        $calculationInput = $this->buildCalculationInputFromTempOrder($tempOrder);
        $calculationResult = $this->orderCalculationService->calculate($calculationInput);

        // 재계산 검증 오류 확인 (쿠폰 만료, 재고 변동 등)
        if ($calculationResult->hasValidationErrors()) {
            throw new OrderProcessingException(__('sirsoft-ecommerce::exceptions.order_calculation_validation_failed'));
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

        // 초기 주문 상태 결정.
        // 결제할 금액(finalAmount)이 0원이면 비현금 수단(마일리지/예치금 등)으로 전액 충당된 것이므로
        // PG/입금 절차 없이 즉시 결제완료. 판정 기준은 결제수단·충당 수단 종류와 무관한 "잔여 결제액 0".
        $finalAmount = (int) ($calculationResult->summary->finalAmount ?? 0);
        $isZeroPayable = $finalAmount <= 0;
        $initialStatus = $this->determineInitialStatus($paymentMethod, $finalAmount);

        // 실제 결제(잔여 결제액 > 0)가 필요한 주문은 결제 통화로 청구 가능한지 주문 생성 전에 검증한다.
        // 결제 통화의 환율이 미설정이거나 환산 결과가 0 이하면 PG/입금 청구가 불가하므로
        // 명확한 예외로 차단한다(주문 부산물 생성 방지). 전액 비현금 충당(0원)은 청구가 없어 제외.
        if (! $isZeroPayable) {
            $this->assertPaymentCurrencyChargeable($currencySnapshot, $finalAmount);
        }

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
            $currencySnapshot,
            $guestLookupPassword,
            $isZeroPayable
        ) {
            // 주문 생성
            $order = $this->createOrder($tempOrder, $calculationResult, $initialStatus, $currencySnapshot, $guestLookupPassword, $shippingInfo);

            // 주문 옵션 생성 (배송 정보 연결을 위해 옵션 ID 매핑 반환)
            $createdOptions = $this->createOrderOptions($order, $tempOrder, $calculationResult, $currencySnapshot);

            // 주문 주소 생성 (주문자 + 배송지)
            $this->createOrderAddresses($order, $ordererInfo, $shippingInfo, $shippingMemo);

            // 결제 정보 생성
            $this->createOrderPayment($order, $paymentMethod, $depositorName, $dbankInfo, $calculationResult, $currencySnapshot, $ordererInfo);

            // 배송 정보 생성 (주문 옵션과 연결)
            $this->createOrderShippings($order, $tempOrder, $calculationResult, $currencySnapshot, $createdOptions);

            // 쿠폰 사용 처리 (재계산 결과에서 적용된 쿠폰 ID 추출)
            $appliedCouponIds = $calculationResult->getAppliedCouponIds();
            if (! empty($appliedCouponIds)) {
                HookManager::doAction('sirsoft-ecommerce.coupon.use', $appliedCouponIds, $order);
            }

            // 마일리지 사용 처리 (TempOrder에 저장된 사용 마일리지)
            // 차감 시점은 결제수단별 설정(payment_methods.*.mileage_deduction_timing) 으로 분기한다:
            // order_placed(무통장 기본) 면 주문 생성 시 차감, payment_complete(카드 기본) 면 결제완료
            // (completePayment) 시점으로 미룬다 — PG 결제 미완료/실패 주문에서 고객 마일리지가 선차감되어
            // 영구 손실되는 것을 방지하고, 무통장은 입금 전 재사용을 차단한다.
            // 단, 결제액 0원(전액 마일리지/예치금) 주문은 PG/입금 콜백이 없어 이 트랜잭션에서 즉시
            // 결제완료 확정되므로 타이밍 설정과 무관하게 항상 생성 시점에 차감한다.
            $usedPoints = $tempOrder->getUsedPoints();
            $mileageTiming = $this->settingsService->getMileageDeductionTiming($paymentMethod);
            if ($usedPoints > 0 && ($isZeroPayable || $mileageTiming === 'order_placed')) {
                $this->deductMileageForOrder($order, $usedPoints);
            }

            // 재고 차감 + 장바구니 처리 (order_placed 타이밍: 트랜잭션 내부에서 실행)
            $timing = $this->settingsService->getStockDeductionTiming($paymentMethod);
            if ($timing === 'order_placed') {
                $this->stockService->deductStock($order->load('options'));
                $this->clearOrderedCartItems($order);
            }

            // 결제액 0원(전액 비현금 충당: 마일리지/예치금 등): PG/입금 콜백이 없으므로
            // 트랜잭션 내에서 즉시 결제완료 확정. 충당 차감(위 mileage.use 등)과 동일 트랜잭션이라 원자성 보장.
            if ($isZeroPayable) {
                $this->finalizeZeroAmountPayment($order);
            }

            return $order;
        });

        // 생성 후 훅
        HookManager::doAction('sirsoft-ecommerce.order.after_create', $order);

        // 관리자 신규주문 알림 발송 시점 분기 (order.after_admin_notify 전용 훅).
        // - 무통장/비-PG 주문: 주문 생성 시점에 발송(입금 전이라도 접수 알림이 정상).
        // - 카드(PG) 주문: 이 시점은 pending_order(결제 전)라 발송하지 않는다. 결제완료(completePayment)
        //   시점에 발화한다 — 결제 미완료/이탈 주문의 오발송 방지.
        // - 0원(전액 비현금) 주문: 아래 결제완료 훅 경로에서 함께 발화한다.
        if (! $isZeroPayable && ! $this->orderRequiresPgPayment($order)) {
            HookManager::doAction('sirsoft-ecommerce.order.after_admin_notify', $order);
        }

        // 결제액 0원 주문은 결제완료 훅을 발화해 적립/알림/구매확정 후속을 정상 연결한다.
        // (PG 결제는 별도 콜백에서 completePayment 가 동일 훅을 발화)
        if ($isZeroPayable) {
            $this->fireZeroAmountPaymentCompleteHooks($order);
        }

        return $order;
    }

    /**
     * 주문이 PG 결제창을 거쳐야 하는지 판정합니다 (관리자 알림 시점 분기용).
     *
     * 결제수단이 PG 공급자를 요구하고 잔여 결제액이 양수면 PG 결제 주문이다.
     * 컨트롤러의 requiresPg 판정과 동일 기준(결제수단·공급자·잔여액)을 따른다.
     *
     * @param  Order  $order  주문(payment 로드 필요)
     * @return bool PG 결제 필요 여부
     */
    protected function orderRequiresPgPayment(Order $order): bool
    {
        $methodValue = $order->payment?->payment_method;
        $methodValue = $methodValue instanceof \BackedEnum ? $methodValue->value : $methodValue;
        $paymentMethod = PaymentMethodEnum::tryFrom((string) $methodValue);

        if ($paymentMethod === null || ! $paymentMethod->needsPgProvider()) {
            return false;
        }

        $pgProvider = $this->determinePgProvider($paymentMethod->value);

        return ! in_array($pgProvider, ['manual', 'internal', 'none'], true)
            && (float) $order->total_due_amount > 0;
    }

    /**
     * 결제액 0원(전액 비현금 충당) 주문의 결제완료 DB 상태를 확정합니다 (트랜잭션 내부 호출).
     *
     * @param  Order  $order  대상 주문
     */
    protected function finalizeZeroAmountPayment(Order $order): void
    {
        $order->update([
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'paid_at' => Carbon::now(),
            'total_paid_amount' => 0,
            'total_due_amount' => 0,
        ]);

        // 옵션 상태도 결제완료로 동기화 (PG 콜백이 없는 0원 주문도 옵션이 PENDING_ORDER 에 갇히지 않도록)
        $this->syncOptionStatuses($order, OrderStatusEnum::PAYMENT_COMPLETE);

        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_at' => Carbon::now(),
            'paid_amount_local' => 0,
        ]);
    }

    /**
     * 전액 마일리지 결제 주문의 결제완료 후속 훅을 발화합니다 (트랜잭션 외부 호출).
     *
     * completePayment 의 훅 순서를 그대로 따라 적립(mileage.earn)·결제완료·구매확정 후속을 연결한다.
     *
     * @param  Order  $order  대상 주문
     */
    protected function fireZeroAmountPaymentCompleteHooks(Order $order): void
    {
        HookManager::doAction('sirsoft-ecommerce.order.before_payment_complete', $order, []);

        // 마일리지 적립 (계산된 적립 예정액이 있을 때만)
        if ($order->total_earned_points_amount > 0) {
            HookManager::doAction('sirsoft-ecommerce.mileage.earn', $order->total_earned_points_amount, $order);
        }

        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $order);
        HookManager::doAction('sirsoft-ecommerce.order.after_confirm', $order);

        // 0원 주문은 생성 시점에 발송하지 않으므로 결제완료(즉시 확정) 시점에 관리자 알림 발화
        HookManager::doAction('sirsoft-ecommerce.order.after_admin_notify', $order);
    }

    /**
     * 초기 주문 상태 결정
     *
     * @param  string  $paymentMethod  결제 수단
     * @param  int  $finalAmount  최종 결제액(마일리지 차감 후). 0 이면 전액 마일리지 결제.
     */
    protected function determineInitialStatus(string $paymentMethod, int $finalAmount = 0): OrderStatusEnum
    {
        // 전액 마일리지 결제(결제액 0원)는 PG/입금 절차가 없으므로 즉시 결제완료로 시작.
        // 결제수단 선택은 유지하되(주문서에 기록), 외부 결제 콜백을 기다리지 않는다.
        if ($finalAmount <= 0) {
            return OrderStatusEnum::PAYMENT_COMPLETE;
        }

        // 무통장입금(vbank, dbank)은 결제대기 상태로 시작
        if (in_array($paymentMethod, [PaymentMethodEnum::VBANK->value, PaymentMethodEnum::DBANK->value])) {
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
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  float  $expectedAmount  프론트엔드 결제예정금액
     *
     * @throws PaymentAmountMismatchException 금액 불일치 시
     */
    protected function validateOrderAmount(OrderCalculationResult $calculationResult, float $expectedAmount): void
    {
        // finalAmount: 최종 지불금액 (마일리지 차감 후 실제 결제 금액).
        // finalAmount 가 0 이면 "전액 마일리지 결제"라는 유효한 값이므로 paymentAmount 로 폴백하지 않는다.
        // (?: 는 0 을 falsy 로 보아 폴백 → 전액 마일리지 주문을 금액 불일치로 오차단하는 버그가 있었음)
        $summary = $calculationResult->summary;
        $actualAmount = $summary->finalAmount ?? ($summary->paymentAmount ?? 0);

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
     * 활성(결제·표시) 통화를 결정합니다. (§A2-b·§A3-b 공용 SSoT)
     *
     * 우선순위:
     *   1) 로그인 유저의 영속 통화(EcommerceUserProfile.preferred_currency) — §A3 에서 주입
     *   2) X-Currency 요청 헤더(비로그인/세션 표시)
     *   3) base(default_currency) 폴백
     *
     * 게스트 체크아웃(비로그인)은 유저 컨텍스트 부재 → 헤더/base 로 안전 폴백한다.
     *
     * @param  string  $baseCurrency  기본 통화(폴백)
     * @return string 결정된 통화 코드
     */
    protected function resolveActiveCurrency(string $baseCurrency): string
    {
        // 1순위: 로그인 유저의 영속 통화 (§A3 user-profile)
        $persisted = $this->resolvePersistedUserCurrency();
        if ($persisted !== null && $persisted !== '') {
            return $persisted;
        }

        // 2순위: X-Currency 헤더 (비로그인/세션 표시) → 3순위: base 폴백
        return request()->header('X-Currency', $baseCurrency) ?: $baseCurrency;
    }

    /**
     * 로그인 유저의 영속 통화를 반환합니다. (§A3-b)
     *
     * EcommerceUserProfile.preferred_currency 를 조회한다. 비로그인(게스트 체크아웃)이면
     * 유저 컨텍스트가 없어 null 을 반환해 헤더/base 폴백 경로를 유지한다(게스트 안전).
     *
     * @return string|null 영속 통화 코드(미설정/비로그인 시 null)
     */
    protected function resolvePersistedUserCurrency(): ?string
    {
        $userId = Auth::id();
        if ($userId === null) {
            return null; // 비로그인/게스트 — 헤더/base 폴백
        }

        return app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency((int) $userId);
    }

    /**
     * 결제 통화로 청구 가능한지 검증합니다 (PG/입금 청구 전 가드).
     *
     * 결제 통화(order_currency)가 base 통화와 다를 때, 주문 스냅샷 환율로 환산해
     * PG/입금이 청구할 수 있는 통화·금액인지 확인합니다. 환율이 미설정(0 이하)이거나
     * 환산 결과가 0 이하면 청구가 불가하므로 명확한 예외로 차단합니다. 결제 통화 결정
     * 자체는 정상이나 그 통화로 결제할 수 없는 상황을 주문 생성 전에 막습니다(서버 우회 방지).
     *
     * @param  array  $currencySnapshot  주문 시점 통화 스냅샷
     * @param  float|int  $baseFinalAmount  결제예정 base 통화 금액
     *
     * @throws UnsupportedPaymentCurrencyException 결제 통화로 청구할 수 없는 경우
     */
    protected function assertPaymentCurrencyChargeable(array $currencySnapshot, float|int $baseFinalAmount): void
    {
        $orderCurrency = $currencySnapshot['order_currency'] ?? ($currencySnapshot['base_currency'] ?? 'KRW');

        try {
            $charge = $this->currencyConversionService->resolveSnapshotPaymentCharge($baseFinalAmount, $currencySnapshot);
        } catch (\InvalidArgumentException $e) {
            // 환율 미설정/0 → 청구 불가
            throw new UnsupportedPaymentCurrencyException(
                $orderCurrency,
                __('sirsoft-ecommerce::exceptions.unsupported_payment_currency', ['currency' => $orderCurrency])
            );
        }

        if ($charge['minor_unit_amount'] <= 0) {
            // 환산 결과가 0 이하 → PG 최소 청구금액 미만(청구 불가)
            throw new UnsupportedPaymentCurrencyException(
                $orderCurrency,
                __('sirsoft-ecommerce::exceptions.unsupported_payment_currency', ['currency' => $orderCurrency])
            );
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

        // 결제 통화 = 유저 영속 통화 1순위 → X-Currency 헤더 → base (resolveActiveCurrency SSoT, §A2-b·§A3-b).
        // base 는 모든 금액 테이블의 SSoT(진실값)로 보존되고, 비-base 결제 통화는 base × 스냅샷 환율로 청구된다.
        $currentCurrency = $this->resolveActiveCurrency($baseCurrency);

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
                // 환산 분모(base_unit)를 통화별로 박제 → 공식 변경 후에도 주문 시점 환산 재현(환차손 0).
                'base_unit' => $this->currencyConversionService->getBaseUnit($code),
            ];

            if ($code === $currentCurrency) {
                $orderExchangeRate = $rate;
            }
        }

        return [
            'base_currency' => $baseCurrency,
            'order_currency' => $currentCurrency,
            'exchange_rate' => $orderExchangeRate,
            // 스냅샷 변환 분모 = 기본 통화의 base_unit (getSnapshotBaseUnit SSoT).
            'base_unit' => $this->currencyConversionService->getBaseUnit($baseCurrency),
            'exchange_rates' => $exchangeRates,
            'snapshot_at' => Carbon::now()->toIso8601String(),
        ];
    }

    /**
     * 주문 생성
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  OrderStatusEnum  $initialStatus  초기 상태
     * @param  array  $currencySnapshot  통화 스냅샷
     */
    protected function createOrder(
        TempOrder $tempOrder,
        OrderCalculationResult $calculationResult,
        OrderStatusEnum $initialStatus,
        array $currencySnapshot,
        ?string $guestLookupPassword = null,
        array $shippingInfo = []
    ): Order {
        $summary = $calculationResult->summary;

        // 다중 통화 변환
        $mcAmounts = $this->buildOrderMultiCurrency($summary, $currencySnapshot);

        $orderData = [
            'user_id' => $tempOrder->user_id,
            'order_number' => $this->generateOrderNumber(),
            // 비회원 조회 비밀번호는 해시로만 저장 (평문 미저장, 회원 주문은 null)
            'guest_lookup_password_hash' => $guestLookupPassword !== null
                ? Hash::make($guestLookupPassword)
                : null,
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
            // 결제예정금액 = 마일리지/예치금 차감 후 실결제액(finalAmount).
            // PG(KG 이니시스 등) 결제 요청 금액·무통장 입금 안내액의 SSoT 이므로 차감 전 paymentAmount 를 쓰면
            // 마일리지 사용분만큼 과다 청구된다. total_amount 와 동일한 차감 후 금액으로 둔다.
            'total_due_amount' => $summary->finalAmount ?? 0,
            'total_cancelled_amount' => 0,
            'total_refunded_amount' => 0,
            'total_refunded_points_amount' => 0,
            'total_earned_points_amount' => $summary->pointsEarning ?? 0,
            'item_count' => count($calculationResult->items ?? []),
            'total_weight' => 0,
            'total_volume' => 0,
            'ordered_at' => Carbon::now(),
            'promotions_applied_snapshot' => $this->buildPromotionsAppliedSnapshot($calculationResult),
            'shipping_policy_applied_snapshot' => $this->buildShippingPolicyAppliedSnapshot($calculationResult, $shippingInfo),
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
     * @param  object  $summary  계산 결과 요약
     * @param  array  $currencySnapshot  통화 스냅샷
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
     * @param  Order  $order  주문
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  array  $currencySnapshot  통화 스냅샷
     * @return void
     */
    /**
     * 주문 옵션 생성
     *
     * @param  Order  $order  주문
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  array  $currencySnapshot  통화 스냅샷
     * @return array<int, OrderOption> productOptionId => OrderOption 매핑
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
                'additional_options_total' => $item->additionalOptionsTotal ?? 0,
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
                'additional_options_snapshot' => $item->additionalOptionsSnapshot ?? [],
                'promotions_applied_snapshot' => $appliedPromotions,
                // 다중 통화 필드
                'mc_unit_price' => $mcAmounts['mc_unit_price'],
                'mc_additional_options_total' => $mcAmounts['mc_additional_options_total'] ?? null,
                'mc_subtotal_price' => $mcAmounts['mc_subtotal_price'],
                'mc_product_coupon_discount_amount' => $mcAmounts['mc_product_coupon_discount_amount'],
                'mc_order_coupon_discount_amount' => $mcAmounts['mc_order_coupon_discount_amount'],
                'mc_coupon_discount_amount' => $mcAmounts['mc_coupon_discount_amount'],
                'mc_code_discount_amount' => $mcAmounts['mc_code_discount_amount'],
                'mc_subtotal_points_used_amount' => $mcAmounts['mc_subtotal_points_used_amount'],
                'mc_subtotal_earned_points_amount' => $mcAmounts['mc_subtotal_earned_points_amount'],
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
     * @param  ProductOption|null  $productOption  상품 옵션
     * @return array 다국어 요약 문자열 배열
     */
    protected function buildOptionValueSummary(?ProductOption $productOption): array
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

                    $localizedKey = is_array($key) ? ($key[$locale] ?? $key[config('app.fallback_locale', 'ko')] ?? array_values($key)[0] ?? '') : $key;
                    $localizedValue = is_array($value) ? ($value[$locale] ?? $value[config('app.fallback_locale', 'ko')] ?? array_values($value)[0] ?? '') : $value;

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
     * @param  object  $item  계산 결과 아이템
     * @param  array  $currencySnapshot  통화 스냅샷
     * @return array 다중 통화 데이터
     */
    protected function buildOptionMultiCurrency(object $item, array $currencySnapshot): array
    {
        $convertAmount = $this->buildAllCurrencyConverter($currencySnapshot);

        return [
            'mc_unit_price' => $convertAmount($item->unitPrice ?? 0),
            'mc_additional_options_total' => $convertAmount($item->additionalOptionsTotal ?? 0),
            'mc_subtotal_price' => $convertAmount($item->subtotal ?? 0),
            'mc_product_coupon_discount_amount' => $convertAmount($item->productCouponDiscountAmount ?? 0),
            'mc_order_coupon_discount_amount' => $convertAmount($item->orderCouponDiscountShare ?? 0),
            'mc_coupon_discount_amount' => $convertAmount($item->productCouponDiscountAmount ?? 0),
            'mc_code_discount_amount' => $convertAmount($item->codeDiscountAmount ?? 0),
            'mc_subtotal_points_used_amount' => $convertAmount($item->pointsUsedShare ?? 0),
            'mc_subtotal_earned_points_amount' => $convertAmount($item->pointsEarning ?? 0),
            'mc_subtotal_deposit_used_amount' => $convertAmount(0),
            'mc_subtotal_tax_amount' => $convertAmount($item->taxableAmount ?? 0),
            'mc_subtotal_tax_free_amount' => $convertAmount($item->taxFreeAmount ?? 0),
            'mc_final_amount' => $convertAmount($item->finalAmount ?? 0),
        ];
    }

    /**
     * 주문 주소 생성
     *
     * @param  Order  $order  주문
     * @param  array  $ordererInfo  주문자 정보
     * @param  array  $shippingInfo  배송지 정보
     * @param  string|null  $shippingMemo  배송 메모
     */
    protected function createOrderAddresses(
        Order $order,
        array $ordererInfo,
        array $shippingInfo,
        ?string $shippingMemo
    ): void {
        $countryCode = strtoupper((string) ($shippingInfo['country_code'] ?? 'KR'));
        $isDomestic = $countryCode === '' || $countryCode === 'KR';

        // 배송지 주소 생성 (국내/해외 필드 모두 보존 — B2 해외 주소 유실 수정)
        $order->addresses()->create([
            'address_type' => 'shipping',
            'orderer_name' => $ordererInfo['name'] ?? '',
            'orderer_phone' => $ordererInfo['phone'] ?? '',
            'orderer_email' => $ordererInfo['email'] ?? '',
            // 주문 시점 화면 언어 스냅샷 — 비회원 알림(이메일) 발송 언어 결정용
            'orderer_locale' => app()->getLocale(),
            'recipient_name' => $shippingInfo['recipient_name'] ?? '',
            'recipient_phone' => $shippingInfo['recipient_phone'] ?? $shippingInfo['phone'] ?? '',
            'recipient_country_code' => $countryCode !== '' ? $countryCode : 'KR',
            // 국내(KR) 필드 — 다음우편번호/도로명 주소 (NOT NULL 컬럼이라 해외 주문 시 빈 문자열)
            'zipcode' => $shippingInfo['zipcode'] ?? $shippingInfo['zonecode'] ?? '',
            'address' => $shippingInfo['address'] ?? '',
            'address_detail' => $shippingInfo['address_detail'] ?? $shippingInfo['detail_address'] ?? '',
            // 해외 배송 필드 — 체크아웃 제출 키와 1:1 매핑 (CreateOrderRequest 검증 완료)
            'address_line_1' => $shippingInfo['address_line_1'] ?? null,
            'address_line_2' => $shippingInfo['address_line_2'] ?? null,
            'intl_city' => $shippingInfo['intl_city'] ?? null,
            'intl_state' => $shippingInfo['intl_state'] ?? null,
            'intl_postal_code' => $shippingInfo['intl_postal_code'] ?? null,
            'address_type_code' => $shippingInfo['address_type_code'] ?? null,
            'delivery_memo' => $shippingMemo,
            'delivery_memo_label' => DeliveryMemoPresetEnum::resolveLabel($shippingMemo),
        ]);
    }

    /**
     * 주문 결제 정보 생성
     *
     * @param  Order  $order  주문
     * @param  string  $paymentMethod  결제 수단
     * @param  string|null  $depositorName  입금자명
     * @param  array|null  $dbankInfo  무통장 수동입금 정보 (dbank 결제 시)
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  array  $currencySnapshot  통화 스냅샷
     * @param  array  $ordererInfo  주문자 정보 (name/email/phone — 결제 구매자 정보로 기록)
     */
    protected function createOrderPayment(
        Order $order,
        string $paymentMethod,
        ?string $depositorName,
        ?array $dbankInfo,
        OrderCalculationResult $calculationResult,
        array $currencySnapshot,
        array $ordererInfo = []
    ): void {
        $paymentAmount = $calculationResult->summary->paymentAmount ?? $calculationResult->summary->finalAmount ?? 0;
        // 결제액 0원(전액 비현금 충당: 마일리지/예치금 등) → 결제 레코드도 즉시 PAID, PG/현금 결제액 0.
        $isZeroPayable = (int) ($calculationResult->summary->finalAmount ?? 0) <= 0;

        // 다중 통화 변환
        $mcPaidAmount = $this->buildMultiCurrencyAmount(0, $currencySnapshot);
        $mcCancelledAmount = $this->buildMultiCurrencyAmount(0, $currencySnapshot);

        // 유입 요청 컨텍스트 — 결제 디바이스/User-Agent 산출.
        // 무통장(dbank)·0원 주문은 PG 콜백(completePayment)을 거치지 않으므로 생성 시점에 기록해야
        // 결제 디바이스/UA 가 영구 NULL 로 남지 않는다. PG 결제 수단은 콜백이 더 정확한 값으로 덮어쓴다.
        $userAgent = request()->userAgent() ?? '';

        // 부가세(VAT): 과세표준(taxableAmount)에 내재된 부가세 = 과세표준 / 11 (공급가액의 10%).
        $taxableAmount = (int) ($calculationResult->summary->taxableAmount ?? 0);
        $vatAmount = $taxableAmount > 0 ? (int) round($taxableAmount / 11) : 0;

        $paymentData = [
            'payment_method' => $paymentMethod,
            'payment_status' => $isZeroPayable ? PaymentStatusEnum::PAID : PaymentStatusEnum::READY,
            'pg_provider' => $this->determinePgProvider($paymentMethod),
            'merchant_order_id' => $order->order_number.'_'.time(),
            'paid_amount_local' => 0,
            'paid_amount_base' => $isZeroPayable ? 0 : $paymentAmount,
            'vat_amount' => $vatAmount,
            'currency' => $currencySnapshot['order_currency'] ?? 'KRW',
            'currency_snapshot' => $currencySnapshot,
            'mc_paid_amount' => $mcPaidAmount,
            'mc_cancelled_amount' => $mcCancelledAmount,
            // 결제 요청 시작 시점 = 주문 생성 시점 (전 결제수단 공통).
            'payment_started_at' => Carbon::now(),
            // 구매자 정보 (주문자 정보를 결제 레코드에도 기록 — 환불/대사 시 결제 단위 식별).
            'buyer_name' => $ordererInfo['name'] ?? null,
            'buyer_email' => $ordererInfo['email'] ?? null,
            'buyer_phone' => $ordererInfo['phone'] ?? null,
            // 결제명 (상품명 요약 — 주문 항목 기반).
            'payment_name' => $this->buildPaymentName($calculationResult),
            // 결제 디바이스/UA (생성 시 기록, PG 콜백이 있으면 더 정확히 덮어씀).
            'payment_device' => DeviceDetector::detectFromUserAgent($userAgent),
            'user_agent' => $userAgent !== '' ? $userAgent : null,
        ];

        // 결제액 0원 주문은 생성 시점에 결제완료이므로 결제 시각 기록
        if ($isZeroPayable) {
            $paymentData['paid_at'] = Carbon::now();
        }

        // 무통장입금 (PG 가상계좌) 정보
        if ($paymentMethod === PaymentMethodEnum::VBANK->value) {
            $paymentData['vbank_holder'] = $depositorName;
            // 입금기한 단일 SSoT: auto_cancel_days (결제수단 무관)
            $paymentData['vbank_due_at'] = Carbon::now()->addDays(
                module_setting('sirsoft-ecommerce', 'order_settings.auto_cancel_days', 3)
            );
        }

        // 무통장입금 (수동 입금) 정보
        if ($paymentMethod === PaymentMethodEnum::DBANK->value && $dbankInfo) {
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
            // 주문별 명시 due_days(클라이언트 입력) 우선, 미지정 시 단일 SSoT auto_cancel_days
            $paymentData['deposit_due_at'] = Carbon::now()->addDays(
                $dbankInfo['due_days'] ?? module_setting('sirsoft-ecommerce', 'order_settings.auto_cancel_days', 3)
            );
        }

        $order->payment()->create($paymentData);
    }

    /**
     * 결제명(상품명 요약)을 생성합니다.
     *
     * 주문 항목의 상품명을 기반으로 "첫 상품명 외 N건" 형태로 요약합니다.
     * PG 결제창·영수증·결제 내역에 표시되는 대표 명칭입니다.
     *
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @return string|null 결제명 (항목이 없으면 null)
     */
    protected function buildPaymentName(OrderCalculationResult $calculationResult): ?string
    {
        $names = [];
        foreach ($calculationResult->items as $item) {
            $name = $item->productName ?? null;
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }

        if (empty($names)) {
            return null;
        }

        $first = $names[0];
        $rest = count($names) - 1;

        return $rest > 0
            ? __('sirsoft-ecommerce::messages.orders.payment_name_summary', ['name' => $first, 'count' => $rest])
            : $first;
    }

    /**
     * PG 제공자 결정
     *
     * @param  string  $paymentMethod  결제 수단
     * @return string PG 제공자
     */
    public function determinePgProvider(string $paymentMethod): string
    {
        $enum = PaymentMethodEnum::tryFrom($paymentMethod);

        // PG 불필요 결제수단
        if ($enum && ! $enum->needsPgProvider()) {
            return $paymentMethod === PaymentMethodEnum::DBANK->value ? 'manual' : 'internal';
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
     * @param  float  $amount  금액
     * @param  array  $currencySnapshot  통화 스냅샷
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
     * @param  array  $currencySnapshot  통화 스냅샷
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
     * @param  Order  $order  주문
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  array  $currencySnapshot  통화 스냅샷
     * @param  array  $createdOptions  productOptionId => OrderOption 매핑
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
     * @param  object|null  $shippingPolicy  배송 정책
     * @param  TempOrder  $tempOrder  임시 주문
     */
    protected function determineShippingType(?object $shippingPolicy, TempOrder $tempOrder): string
    {
        // 배송 정책에 type이 있으면 사용
        if ($shippingPolicy && ! empty($shippingPolicy->type)) {
            $validCodes = $this->shippingTypeRepository->getActiveCodes();
            if (in_array($shippingPolicy->type, $validCodes)) {
                return $shippingPolicy->type;
            }
        }

        // 배송지 국가로 판단 (임시 주문에서)
        $shippingCountry = $tempOrder->shipping_address['country_code'] ?? 'KR';

        if ($shippingCountry !== 'KR') {
            return $this->shippingTypeRepository->getFirstActiveCodeByCategory('international') ?? 'international_standard';
        }

        return $this->shippingTypeRepository->getFirstActiveCodeByCategory('domestic') ?? 'parcel';
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
     * 주문 상품의 구매 대상 제한을 최종 검증합니다.
     *
     * 체크아웃 단계에서 사전 안내하더라도, 프론트 우회 가능성을 막기 위해
     * 주문 생성 직전 서버에서 다시 검사합니다. 회원/비회원 공통 적용하며,
     * 비회원은 코어 guest 역할로 판정합니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     *
     * @throws CartUnavailableException 구매 권한이 없는 상품이 있는 경우
     */
    protected function validatePurchaseEligibility(TempOrder $tempOrder): void
    {
        $items = $tempOrder->items ?? [];
        $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));

        if (empty($productIds)) {
            return;
        }

        $products = $this->productRepository->findByIdsKeyed($productIds);
        $userRoleIds = $this->purchaseEligibilityService->resolveRoleIds(Auth::user());

        $restricted = $this->purchaseEligibilityService->filterRestrictedProducts($products, $userRoleIds);

        if (empty($restricted)) {
            return;
        }

        // 차단 상품을 CartUnavailableException 의 unavailable_items 형식으로 구성
        $unavailableItems = [];
        foreach ($restricted as $product) {
            $unavailableItems[] = [
                'product_id' => $product->id,
                'name' => $product->getLocalizedName(),
                'reason' => 'restricted',
            ];
        }

        throw CartUnavailableException::fromItems($unavailableItems);
    }

    /**
     * 주문 상품이 선택된 배송국가로 배송 가능한지 최종 검증합니다. (D1)
     *
     * 각 상품의 배송정책(ShippingPolicy)에 해당 국가 설정(getCountrySetting)이 없으면
     * 그 국가로 배송 불가로 판정합니다. 미지원 상품이 1개라도 있으면 주문 전체를 차단합니다
     * (혼재 시 전체 차단, 부분주문 없음). 카트/체크아웃 표시(layer 1/2)를 우회한 직접 호출도
     * 막는 최종 방어선입니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  string|null  $countryCode  제출된 배송국가 코드 (없으면 KR)
     *
     * @throws CartUnavailableException 미지원 배송국가 상품이 1개 이상이면
     */
    protected function validateShippingCountrySupported(TempOrder $tempOrder, ?string $countryCode): void
    {
        $country = strtoupper((string) ($countryCode ?? 'KR'));
        if ($country === '') {
            $country = 'KR';
        }

        $items = $tempOrder->items ?? [];
        $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));

        if (empty($productIds)) {
            return;
        }

        $products = $this->productRepository->findByIdsKeyed($productIds);

        $unavailableItems = [];
        foreach ($products as $product) {
            // 상품에 정책이 없으면(shipping_policy_id=null) 기본 배송정책으로 폴백 판정한다.
            // 적용 가능한 정책이 전혀 없을 때만 국내(KR) 기본 배송으로 간주.
            if (! $this->shippingPolicyResolver->isShippableToCountry($product, $country)) {
                $unavailableItems[] = [
                    'product_id' => $product->id,
                    'name' => $product->getLocalizedName(),
                    'reason' => 'country_not_shippable',
                    'country_code' => $country,
                ];
            }
        }

        if (! empty($unavailableItems)) {
            throw CartUnavailableException::fromItems($unavailableItems);
        }
    }

    /**
     * 첫 주문 여부 확인
     *
     * 비회원 주문(user_id = null)은 회원 식별이 불가능해 첫 주문 혜택 판단 대상이
     * 아니므로 항상 false 로 처리합니다.
     *
     * @param  int|null  $userId  회원 ID (비회원은 null)
     */
    protected function isFirstOrder(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return ! $this->orderRepository->hasOrderByUser($userId);
    }

    /**
     * 임시 주문에서 계산 입력 DTO를 구성합니다.
     *
     * TempOrderService::getTempOrderWithCalculation()의 패턴을 따릅니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
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
            userId: $tempOrder->user_id,
        );
    }

    /**
     * 적용된 프로모션 스냅샷을 구성합니다.
     *
     * @param  OrderCalculationResult  $result  계산 결과
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
     */
    protected function buildShippingPolicyAppliedSnapshot(OrderCalculationResult $result, array $shippingInfo = []): array
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

        // 배송지(국가/우편번호) 스냅샷 보존 (B5 — 환불/취소 재계산 시 도서산간/국가별 정책 판단 복원).
        // OrderAdjustmentService::buildRecalcInput 가 'address' 키로 ShippingAddress 를 복원한다.
        $policies['address'] = [
            'country_code' => strtoupper((string) ($shippingInfo['country_code'] ?? 'KR')),
            'zipcode' => $shippingInfo['zipcode'] ?? $shippingInfo['intl_postal_code'] ?? null,
        ];

        return $policies;
    }

    /**
     * 주문 메타데이터를 구성합니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
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
     * @param  Order  $order  주문
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
     * @param  Order  $order  주문
     * @param  array  $paymentData  결제 데이터
     * @param  int|null  $pgAmount  PG사에서 전달받은 결제금액 (null이면 금액 검증 생략)
     * @return Order 결제 완료된 주문 (fresh)
     *
     * @throws PaymentAmountMismatchException 금액 불일치 시
     */
    public function completePayment(Order $order, array $paymentData = [], ?int $pgAmount = null): Order
    {
        // 이미 결제완료된 주문(예: 전액 마일리지 결제로 생성 시점에 PAID 확정)은 재처리하지 않는다.
        // 결제완료 훅 이중 발화(중복 적립 등)를 방어한다.
        if ($order->order_status === OrderStatusEnum::PAYMENT_COMPLETE) {
            return $order->fresh();
        }

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

            // 결제 통화(order_currency) 실청구 금액. base≠결제 통화일 때 paid_amount_local 은
            // base 값이 아니라 스냅샷 환율로 환산된 결제 통화 금액이어야 한다(PG 실청구·환불 단위 정합).
            $orderCurrency = $currencySnapshot['order_currency'] ?? ($currencySnapshot['base_currency'] ?? 'KRW');
            $paidAmountLocal = $mcTotalPaidAmount[$orderCurrency] ?? $paidAmount;

            // 주문 상태 변경
            $order->update([
                'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
                'paid_at' => Carbon::now(),
                'total_paid_amount' => $paidAmount,
                'total_due_amount' => 0,
                'mc_total_paid_amount' => $mcTotalPaidAmount,
            ]);

            // 주문 옵션 상태도 결제완료로 동기화 (취소/클레임 옵션 제외)
            // 옵션은 생성 시 PENDING_ORDER 로 시작하므로 결제완료 시 함께 전이해야
            // 주문상세 화면에 "주문대기"로 갇히지 않는다.
            $this->syncOptionStatuses($order, OrderStatusEnum::PAYMENT_COMPLETE);

            // 결제 정보 업데이트 (PG 응답 필드 확장)
            $order->payment()->update(array_filter([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_at' => Carbon::now(),
                // paid_amount_local = 결제 통화(order_currency) 환산 실청구액, paid_amount_base = base 진실값.
                'paid_amount_local' => $paidAmountLocal,
                'paid_amount_base' => $paidAmount,
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

            // 마일리지 사용 차감 (payment_complete 타이밍 — 결제가 실제 완료된 시점에 차감).
            // 생성 시 차감(order_placed/0원 주문)으로 이미 is_mileage_deducted=true 인 주문은 재차감하지 않는다.
            $usedPoints = (int) $order->total_points_used_amount;
            if ($usedPoints > 0 && ! $order->is_mileage_deducted) {
                $this->deductMileageForOrder($order, $usedPoints);
            }

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

        // 관리자 신규주문 알림 — 카드(PG) 주문은 주문 생성 시점이 아니라 결제완료 시점에 발송한다.
        // (무통장/비-PG/0원 주문은 각자 다른 시점에 이미 발화하므로 여기서 발화하지 않는다)
        HookManager::doAction('sirsoft-ecommerce.order.after_admin_notify', $order);

        // 임시주문 정리 (PG 결제 완료 시점에 삭제, 이미 삭제된 경우 no-op)
        if ($order->user_id) {
            // 회원: user_id 기준 삭제
            $this->tempOrderService->deleteTempOrder($order->user_id, null);
        } else {
            // 비회원: cart_key 가 없으므로 order_meta 에 보관된 temp_order_id 로 삭제
            $tempOrderId = $order->order_meta['temp_order_id'] ?? null;
            if ($tempOrderId !== null) {
                $this->tempOrderService->deleteTempOrderById((int) $tempOrderId);
            }
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
                // 국가별 명시 매핑 (B4 — 폴백 혼재 해외 필드 누락/오저장 방지)
                $this->userAddressService->createAddress(
                    $this->userAddressService->mapShippingInfoToAddressData($order->user_id, $name, $shippingData)
                );

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
     * 무통장 수동 입금확인 처리
     *
     * "입금 기록"과 "주문 결제완료 전이"를 분리해 처리한다:
     *  - 입금 기록(항상): 결제 레코드(payment)를 입금완료(PAID)로 정합화 — paid_at·입금액·입금자명.
     *    입금액은 결제예정금액과 정확히 일치해야 한다(불일치 시 422).
     *  - 결제완료 전이(선택): $markOrderComplete=true 이고 주문이 아직 미결제일 때만, 결제완료 SSoT
     *    (completePayment)에 위임해 option_status 동기화·마일리지·재고·결제완료 알림을 일괄 처리한다.
     *
     * 분리 배경: "이미 결제완료된 주문에 추가 금액(교환/반품 배송비 등)을 무통장으로 받아 수동 기록"
     * 하는 케이스에서는 마일리지/재고/알림 연쇄를 다시 태우면 안 된다. 또한 관리자가 주문 상태를
     * 먼저 결제완료로 바꿔 둔(order_status=payment_complete / payment=미입금) 불일치 주문도
     * 결제완료 전이 없이 payment 만 정합화해야 한다.
     *
     * 관리자 수동 입금확인(Admin\OrderController::confirmDeposit)과 추후 무통장 입금 자동처리
     * 플러그인(은행 입금통보 webhook)이 동일하게 이 공개 메서드를 호출한다.
     *
     * @param  Order  $order  주문 모델 (무통장)
     * @param  float  $amount  입금 금액 (결제예정금액과 정확 일치해야 함)
     * @param  string|null  $depositorName  입금자명
     * @param  bool  $markOrderComplete  true 면 주문을 결제완료로 전이(마일리지/재고/알림 포함). 기본 true(기존 동작 호환)
     * @return Order 입금확인 처리된 주문 (fresh)
     *
     * @throws PaymentAmountMismatchException 입금액이 결제예정금액과 불일치 시
     */
    public function confirmManualDeposit(
        Order $order,
        float $amount,
        ?string $depositorName = null,
        bool $markOrderComplete = true
    ): Order {
        // 훅: 무통장 입금 확인 전 (관리자 민감 작업 — IDV 정책 가드 지점).
        // EnforceIdentityPolicyListener 가 'sirsoft-ecommerce.payment.confirm_deposit' 정책이
        // 활성이고 grace 만료 시 IdentityVerificationRequiredException(428) 을 throw.
        HookManager::doAction('sirsoft-ecommerce.payment.before_confirm_deposit', $order, $amount, $depositorName);

        $order->loadMissing('payment');

        // 결제 레코드가 이미 입금완료(PAID)면 멱등 처리 — 추가 입금 기록은 본 메서드 범위 밖.
        if ($order->payment?->payment_status === PaymentStatusEnum::PAID) {
            return $order->fresh();
        }

        // 입금자명 기록 (선택 입력) — 결제완료 전이/입금 기록 양 경로 공통.
        // completePayment 는 입금자명을 모르므로 위임 전에 먼저 기록한다.
        if ($depositorName !== null && $depositorName !== '') {
            $order->payment?->update(['depositor_name' => $depositorName]);
        }

        // 결제완료 전이 동반: 주문이 아직 미결제일 때만 결제완료 SSoT(completePayment)에 위임.
        // completePayment 가 payment 도 PAID 로 정합화하므로 입금 기록을 중복하지 않는다.
        // order_status 가 이미 결제완료인 경우(불일치/추가입금)는 아래 입금 기록만 수행한다.
        if ($markOrderComplete && $order->order_status !== OrderStatusEnum::PAYMENT_COMPLETE) {
            return $this->completePayment($order, [], (int) round($amount));
        }

        // 입금 기록만 수행: 결제완료 전이(마일리지/재고/알림) 없이 payment 만 PAID 로 정합화.
        // 금액 검증은 동일하게 적용(결제예정금액 정확 일치). 입금자명은 위에서 이미 기록함.
        $this->recordDepositPayment($order, $amount);

        return $order->fresh();
    }

    /**
     * 결제 레코드(payment)만 입금완료(PAID)로 정합화합니다 — 주문 상태 전이 없이.
     *
     * 입금액이 결제예정금액과 정확히 일치하는지 검증한 뒤(불일치 시 422), 결제 레코드의
     * payment_status·paid_at·입금액을 갱신한다. 마일리지/재고/알림 등 결제완료 부수효과는
     * 발생시키지 않는다(그건 completePayment 의 책임). 입금자명은 호출부에서 기록한다.
     *
     * @param  Order  $order  주문
     * @param  float  $amount  입금 금액
     *
     * @throws PaymentAmountMismatchException 입금액이 결제예정금액과 불일치 시
     */
    protected function recordDepositPayment(Order $order, float $amount): void
    {
        $payment = $order->payment;
        if ($payment === null) {
            return;
        }

        // 결제예정금액 정확 일치 검증 (completePayment 와 동일 기준).
        // $amount 는 결제 통화(order_currency) 청구액(validatePayment 2단계 기준)이므로
        // paid_amount_local 에 그대로 들어가고, paid_amount_base 는 base 진실값으로 동결한다.
        $this->validatePaymentAmount($order, (int) round($amount));

        $payment->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_at' => Carbon::now(),
            'paid_amount_local' => (int) round($amount),
            'paid_amount_base' => (float) $order->total_due_amount,
        ]);
    }

    /**
     * PG 결제 금액 검증 (2단계)
     *
     * 검증 기준 = 결제예정금액(total_due_amount). 모든 PG 가 결제창에 청구하는 금액(buildPgPaymentData →
     * total_due_amount)·각 PG 플러그인의 사전 가드(signature/SignData)·본 최종 승인 검증이 동일 컬럼을
     * 기준으로 동작해야 마일리지/예치금 차감 후 실청구액 기준으로 일관 검증된다.
     *
     * 1단계: 주문 개별 금액 컴포넌트 재합산(마일리지 차감 포함) → total_due_amount 일치 확인 (DB 변조 감지)
     * 2단계: 주문 total_due_amount → PG 콜백 실청구액 일치 확인
     *
     * @param  Order  $order  주문
     * @param  int  $pgAmount  PG사에서 전달받은 결제금액
     *
     * @throws PaymentAmountMismatchException 금액 불일치 시
     */
    protected function validatePaymentAmount(Order $order, int $pgAmount): void
    {
        // 1단계: 컴포넌트 재합산 검증 (DB 변조 감지). 마일리지 사용분(total_points_used_amount)까지
        // 차감하므로 재합산 결과 = 차감 후 실청구액 = total_due_amount 여야 한다.
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
        $dueAmount = round($order->total_due_amount, 2);

        if ($calculatedTotal !== $dueAmount) {
            throw new PaymentAmountMismatchException(
                $dueAmount,
                $calculatedTotal,
                [
                    'stage' => 'component_verification',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ]
            );
        }

        // 2단계: PG 금액 일치 검증.
        // 비교 기준 = 결제 통화(order_currency) 청구액. PG 결제창에 청구한 금액
        // (buildPgPaymentData → resolveOrderPaymentChargeAmount)·각 PG 콜백 승인액과 동일 SSoT 다.
        // base(total_due_amount) 를 직접 비교하면 base≠결제 통화(예: base JPY, 결제 KRW)일 때
        // PG 청구액(환산 KRW)과 단위가 어긋나 "결제금액 불일치" 회귀가 발생한다.
        $expectedChargeAmount = $this->currencyConversionService->resolveOrderPaymentChargeAmount($order);

        if ($expectedChargeAmount !== $pgAmount) {
            throw new PaymentAmountMismatchException(
                $pgAmount,
                $expectedChargeAmount,
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
     * @param  Order  $order  주문
     * @param  string  $errorCode  에러 코드
     * @param  string  $errorMessage  에러 메시지
     * @return Order 결제 실패 처리된 주문 (fresh)
     */
    public function failPayment(Order $order, string $errorCode, string $errorMessage): Order
    {
        // 결제 전 상태인 경우에만 처리
        if (! $order->order_status->isBeforePayment()) {
            return $order;
        }

        // 결제가 이미 성공(payment_status=PAID)한 주문은 실패 처리하지 않는다.
        // 카드(PG) 주문은 승인 직전까지 order_status=PENDING_ORDER 이므로, PG 승인 콜백
        // (completePayment, payment 를 먼저 PAID 로 갱신)과 결제창 닫힘 보고(failPayment)가
        // 경쟁하면 order_status 만 보는 위 가드를 통과해 옵션을 CANCELLED 로 덮을 수 있다.
        // 이후 order_status 는 completePayment 가 결제완료로 덮지만 옵션은 syncExcludedValues
        // 보호로 되살아나지 못해 "주문=결제완료 / 옵션=취소" 모순이 고착된다.
        // payment_status 를 함께 확인해 결제 성공 주문의 실패 처리를 원천 차단한다.
        if ($order->payment?->isPaid()) {
            return $order;
        }

        DB::transaction(function () use ($order, $errorCode, $errorMessage) {
            // 마일리지 선차감분 복원 (order_placed 타이밍 등으로 결제 전 차감된 경우).
            // 정식 주문취소가 아닌 결제 실패 경로는 OrderCancellationService 를 거치지 않으므로
            // 여기서 직접 복원하지 않으면 차감된 마일리지가 영구 손실된다.
            // is_mileage_deducted 플래그 + 결제 전 상태 가드(위)가 이중 복원을 방지한다.
            if ($order->is_mileage_deducted && $order->user_id !== null) {
                $usedPoints = (float) $order->total_points_used_amount;
                if ($usedPoints > 0) {
                    $currency = $this->userMileageService->baseCurrencyForOrder($order);
                    $this->userMileageService->restoreForFailedPayment($order->user_id, $order->id, $usedPoints, $currency);
                }
                $order->is_mileage_deducted = false;
            }

            $order->update([
                'order_status' => OrderStatusEnum::CANCELLED,
                'is_mileage_deducted' => false,
                'order_meta' => array_merge($order->order_meta ?? [], [
                    'payment_failure_code' => $errorCode,
                    'payment_failure_message' => $errorMessage,
                    'payment_failed_at' => Carbon::now()->toIso8601String(),
                ]),
            ]);

            // 주문 옵션 상태도 취소로 동기화 (이미 취소/부분취소된 옵션은 syncExcludedValues 로 보존).
            // 이 동기화가 없으면 order_status=CANCELLED ↔ option_status=PENDING_ORDER 불일치가 잔존한다 (U19①).
            $this->syncOptionStatuses($order, OrderStatusEnum::CANCELLED);
        });

        HookManager::doAction('sirsoft-ecommerce.order.payment_failed', $order->fresh(), $errorCode, $errorMessage);

        return $order->fresh();
    }

    /**
     * 주문의 사용 마일리지를 차감하고 차감 플래그를 기록합니다 (트랜잭션 내부 호출).
     *
     * mileage.use 훅을 발화해 마일리지 리스너가 FIFO 차감을 수행하게 하고,
     * is_mileage_deducted 플래그를 true 로 기록한다. 이 플래그는 복원(취소/결제실패) 시
     * 실제 차감이 일어난 주문만 복원하도록 가드하는 기준이 된다.
     *
     * @param  Order  $order  주문
     * @param  int  $usedPoints  사용 마일리지
     */
    protected function deductMileageForOrder(Order $order, int $usedPoints): void
    {
        HookManager::doAction('sirsoft-ecommerce.mileage.use', $usedPoints, $order);

        $order->update(['is_mileage_deducted' => true]);
    }

    /**
     * 주문 옵션 상태를 주문 상태와 동기화합니다.
     *
     * 결제완료 등으로 주문 전체 상태가 전이될 때, 각 옵션의 option_status 도
     * 같은 상태로 따라가야 화면(주문상세·마이페이지)에 결제완료로 표시된다.
     * 옵션 생성 시 PENDING_ORDER(주문대기)로 시작하므로, 이 동기화가 없으면
     * 주문은 결제완료인데 옵션만 "주문대기"에 갇힌다.
     *
     * 취소/환불·클레임 등 별도 라이프사이클 옵션은 OrderStatusEnum SSoT 로 제외한다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderStatusEnum  $newStatus  동기화할 상태
     */
    protected function syncOptionStatuses(Order $order, OrderStatusEnum $newStatus): void
    {
        $order->options()
            ->whereNotIn('option_status', OrderStatusEnum::syncExcludedValues())
            ->update([
                'option_status' => $newStatus->value,
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * 결제 취소 이력 기록
     *
     * 유저가 PG 결제창을 닫았을 때 order_payments의 상태와 이력을 업데이트합니다.
     * - payment_status → 'cancelled'
     * - cancel_history에 취소 이력 추가 (PG사 응답 코드/메시지 포함)
     * 주문 상태(order_status)는 변경하지 않습니다 (pending_order 유지).
     *
     * @param  Order  $order  주문
     * @param  string|null  $cancelCode  PG사 취소 코드 (예: USER_CANCEL)
     * @param  string|null  $cancelMessage  PG사 취소 메시지
     * @return Order 갱신된 주문 (fresh)
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
     * @param  string  $orderNumber  주문번호
     * @return Order|null 주문 (없으면 null)
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
     * @return Order 취소 처리된 주문
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
