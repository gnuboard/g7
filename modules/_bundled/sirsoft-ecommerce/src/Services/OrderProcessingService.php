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
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidEffectsPlan;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidEffectsReport;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidOptions;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidTransition;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\ReactivationRestorationPlan;
use Modules\Sirsoft\Ecommerce\DTO\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Enums\CashReceiptIdentifierType;
use Modules\Sirsoft\Ecommerce\Enums\DeliveryMemoPresetEnum;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CartUnavailableException;
use Modules\Sirsoft\Ecommerce\Exceptions\InsufficientStockException;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderAmountChangedException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderMileageDeductionException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderProcessingException;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Exceptions\ReactivationCouponConflictException;
use Modules\Sirsoft\Ecommerce\Exceptions\UnsupportedPaymentCurrencyException;
use Modules\Sirsoft\Ecommerce\Helpers\DeviceDetector;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\TempOrder;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CartRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingTypeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Support\ConcurrencyErrors;
use Modules\Sirsoft\Ecommerce\Support\LocalizedText;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Support\VatCalculator;

/**
 * 주문 처리 서비스
 *
 * 임시 주문(TempOrder)을 실제 주문(Order)으로 변환합니다.
 * 주문 상태 흐름: pending_order → pending_payment → payment_complete → ...
 */
class OrderProcessingService
{
    /** 입금기한 기본값(일) — 설정이 비어 있거나 사용할 수 없는 값일 때 적용 */
    private const AUTO_CANCEL_DAYS_DEFAULT = 3;

    /** 입금기한 허용 하한(일) — 설정(limits)에 값이 없을 때의 fallback */
    private const AUTO_CANCEL_DAYS_MIN_FALLBACK = 1;

    /** 입금기한 허용 상한(일) — 설정(limits)에 값이 없을 때의 fallback */
    private const AUTO_CANCEL_DAYS_MAX_FALLBACK = 30;

    /** 재활성 복원 서비스 (늦은 해석 — reactivationService()) */
    private ?OrderReactivationService $reactivationService = null;

    /** 일괄 2단계가 같은 전이의 재활성 기록을 한 항목에 합치도록 주문·전이별 이력 위치를 기억한다 */
    private array $reclaimHistoryIndex = [];

    /**
     * 주문 생성·결제 완료·입금 확인이 함께 쓰는 협력자를 주입받습니다.
     *
     * @param  OrderRepositoryInterface  $orderRepository  주문 저장소
     * @param  TempOrderService  $tempOrderService  임시 주문(주문서) 서비스
     * @param  OrderCalculationService  $orderCalculationService  금액 계산 서비스
     * @param  CurrencyConversionService  $currencyConversionService  통화 환산 서비스
     * @param  ProductRepositoryInterface  $productRepository  상품 저장소
     * @param  ProductOptionRepositoryInterface  $productOptionRepository  상품 옵션 저장소
     * @param  SequenceService  $sequenceService  주문번호 채번 서비스
     * @param  EcommerceSettingsService  $settingsService  이커머스 환경설정 서비스
     * @param  StockService  $stockService  재고 서비스
     * @param  UserAddressService  $userAddressService  회원 주소록 서비스
     * @param  CartRepositoryInterface  $cartRepository  장바구니 저장소
     * @param  PurchaseEligibilityService  $purchaseEligibilityService  구매 자격 판정 서비스
     * @param  UserMileageService  $userMileageService  마일리지 서비스
     * @param  ShippingTypeRepositoryInterface  $shippingTypeRepository  배송 유형 저장소
     * @param  ShippingPolicyResolver  $shippingPolicyResolver  배송 정책 해석기
     */
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
     * @param  array|null  $cashReceiptInfo  현금영수증 신청 정보 (type/identifier_type/identifier, 미신청 시 null)
     * @param  array|null  $refundBankInfo  환불 계좌 정보 (bank_code/account_number/holder, 미입력 시 null)
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
        ?string $guestLookupPassword = null,
        ?array $cashReceiptInfo = null,
        ?array $refundBankInfo = null
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

        // 실제로 배송할 국가(제출값)가 임시 주문에 저장된 국가와 다르면 그 국가로 다시 계산한다.
        // 주문서 진입 때 시드된 배송국가·기본 배송지 채움은 미리보기(GET)로만 계산되고 임시 주문에
        // 저장되지 않아, 화면은 해외 배송비인데 재계산은 국내 배송비로 갈라졌다(해외 주소 직접 입력 주문 422).
        // 반대로 국내 금액을 보내면 해외 배송비를 덜 낸 주문이 통과했다. 위의 금액 변동 검사는 저장된 주소
        // 기준으로 먼저 끝내 두고(가격 변동 감지 유지), 여기서는 배송국가 차이만 맞춘다.
        $alignedTempOrder = $this->alignTempOrderShippingCountry($tempOrder, $shippingInfo);
        if ($alignedTempOrder !== null) {
            $tempOrder = $alignedTempOrder;
            $calculationInput = $this->buildCalculationInputFromTempOrder($tempOrder);
            $calculationResult = $this->orderCalculationService->calculate($calculationInput);

            if ($calculationResult->hasValidationErrors()) {
                throw new OrderProcessingException(__('sirsoft-ecommerce::exceptions.order_calculation_validation_failed'));
            }
        }

        // ⚠️ 프론트엔드 전달 금액과 서버 재계산 금액 검증
        $this->validateOrderAmount($calculationResult, $expectedTotalAmount);

        // 마일리지 사용 정책 최종 재검증 (임시주문 생성 이후의 설정 변경·임시주문 조작 차단).
        // 임시주문 단계에서 이미 통과한 값이라도 결제 확정 직전 한 번 더 판정한다.
        $this->validateMileageUsagePolicy($tempOrder, $calculationResult);

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

        // 0원 주문의 결제완료 전 스냅샷 — 트랜잭션 안에서 채워 밖의 훅이 읽는다(§7-1 D).
        $zeroAmountSnapshot = null;

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
            $isZeroPayable,
            $cashReceiptInfo,
            $refundBankInfo,
            &$zeroAmountSnapshot
        ) {
            // 주문 생성
            $order = $this->createOrder($tempOrder, $calculationResult, $initialStatus, $currencySnapshot, $guestLookupPassword, $shippingInfo, $paymentMethod);

            // 주문 옵션 생성 (배송 정보 연결을 위해 옵션 ID 매핑 반환)
            $createdOptions = $this->createOrderOptions($order, $tempOrder, $calculationResult, $currencySnapshot, $paymentMethod);

            // 주문 주소 생성 (주문자 + 배송지)
            $this->createOrderAddresses($order, $ordererInfo, $shippingInfo, $shippingMemo);

            // 결제 정보 생성
            $this->createOrderPayment($order, $paymentMethod, $depositorName, $dbankInfo, $calculationResult, $currencySnapshot, $ordererInfo, $cashReceiptInfo, $refundBankInfo);

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
                // 결제완료 전 스냅샷 — 아래 훅이 활동로그의 전/후 표를 세우는 근거다(§7-1 D).
                $zeroAmountSnapshot = $order->toArray();
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
            $this->fireZeroAmountPaymentCompleteHooks($order, $zeroAmountSnapshot ?? null);
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
        if (! $order->payment || ! $order->payment->needsPgProvider()) {
            return false;
        }

        $pgProvider = $this->determinePgProvider($order->payment->paymentMethodId());

        return ! in_array($pgProvider, ['manual', 'internal', 'none'], true)
            && (float) $order->total_due_amount > 0;
    }

    /**
     * 이 주문의 결제가 **PG 를 거쳐** 완료됐는지 판정합니다 (결제완료 시점 기준).
     *
     * `orderRequiresPgPayment()` 와 묻는 것이 다르다 — 그쪽은 주문 «생성» 시점에 설정을 읽어
     * 「이 주문이 PG 를 타야 하는가」를 정한다. 결제완료 시점에는 결제행에 **실제로 무엇을
     * 탔는지**가 이미 기록돼 있고(무통장은 `manual`), 그 뒤 설정이 바뀌어도 사실은 변하지
     * 않는다. 설정을 다시 읽으면 PG 플러그인을 끈 사이트에서 카드 주문의 관리자 알림이
     * 조용히 사라진다.
     *
     * 금액은 보지 않는다 — 이 메서드는 결제완료 «뒤»에 불리고 그때 `total_due_amount` 는 이미
     * 0 이다. 결제액 0원 주문은 애초에 이 경로(completePayment)로 오지 않고
     * `fireZeroAmountPaymentCompleteHooks` 가 따로 처리한다.
     *
     * @param  Order  $order  주문
     * @return bool PG 경유 결제면 true
     */
    protected function paymentWentThroughPg(Order $order): bool
    {
        $provider = $order->payment?->pg_provider;

        return $provider !== null
            && $provider !== ''
            && ! in_array($provider, ['manual', 'internal', 'none'], true);
    }

    /**
     * 결제액 0원(전액 비현금 충당) 주문의 결제완료 DB 상태를 확정합니다 (트랜잭션 내부 호출).
     *
     * @param  Order  $order  대상 주문
     * @return void 반환값 없음
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
     * @param  array|null  $snapshot  변경 전 주문 속성 스냅샷 (활동로그의 전/후 표가 읽는다)
     * @return void 반환값 없음
     */
    protected function fireZeroAmountPaymentCompleteHooks(Order $order, ?array $snapshot = null): void
    {
        HookManager::doAction('sirsoft-ecommerce.order.before_payment_complete', $order, []);

        // 마일리지 적립 (계산된 적립 예정액이 있을 때만)
        if ($order->total_earned_points_amount > 0) {
            HookManager::doAction('sirsoft-ecommerce.mileage.earn', $order->total_earned_points_amount, $order);
        }

        // 변경 전 스냅샷 — 활동 로그 화면은 `changes` 에 실린 항목만 "변경 전 → 변경 후" 표로
        // 그린다(§7-1 D). 줄은 남는데 표가 서지 않으면 운영자는 이 사건으로 **무엇이 바뀌었는지**
        // 를 로그에서 읽을 수 없다. 인수 추가는 하위호환이다 — 기존 3인수 구독자는 초과 인수를
        // 그대로 무시한다(PHP 는 유저 함수에 인수가 더 넘어와도 오류를 내지 않는다).
        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $order, $snapshot);
        HookManager::doAction('sirsoft-ecommerce.order.after_confirm', $order);

        // 0원 주문은 생성 시점에 발송하지 않으므로 결제완료(즉시 확정) 시점에 관리자 알림 발화
        HookManager::doAction('sirsoft-ecommerce.order.after_admin_notify', $order);
    }

    /**
     * 초기 주문 상태 결정
     *
     * @param  string  $paymentMethod  결제 수단
     * @param  int  $finalAmount  최종 결제액(마일리지 차감 후). 0 이면 전액 마일리지 결제.
     * @return OrderStatusEnum 반환값
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
     * @return void 반환값 없음
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
     * 해석 결과가 현재 등록된 통화가 아니면 base 로 폴백한다 (공개 #91). 관리자가 통화를
     * 삭제해도 유저의 영속 선호 통화와 클라이언트 헤더에는 그 코드가 남아 있는데, 그대로
     * 채택하면 스냅샷 환율에 코드가 없어 체크아웃이 차단된다. 저장된 선호 값 자체는 지우지
     * 않는다 — 그 통화가 다시 등록되면 자동으로 복원되는 것이 올바른 동작이다.
     *
     * 기본 통화(default_currency)를 폴백으로 쓴다. 체크아웃 표시와 주문 생성 검증의 결제수단 통화 제한(#639 D17)이
     * 주문 생성과 같은 통화로 판정하도록 공개한다.
     *
     * @return string 결정된 통화 코드
     */
    public function resolvePaymentCurrency(): string
    {
        return $this->resolveActiveCurrency($this->currencyConversionService->getDefaultCurrency());
    }

    /**
     * 결제 통화를 결정합니다 (기본 통화를 인자로 받음).
     *
     * @param  string  $baseCurrency  기본 통화(폴백)
     * @return string 결정된 통화 코드
     */
    protected function resolveActiveCurrency(string $baseCurrency): string
    {
        // 1순위: 로그인 유저의 영속 통화 (§A3 user-profile)
        $persisted = $this->resolvePersistedUserCurrency();

        // 2순위: X-Currency 헤더 (비로그인/세션 표시) → 3순위: base 폴백
        $resolved = ($persisted !== null && $persisted !== '')
            ? $persisted
            : (request()->header('X-Currency', $baseCurrency) ?: $baseCurrency);

        return $this->currencyConversionService->isSupportedCurrency($resolved)
            ? $resolved
            : $baseCurrency;
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
     * @return void 반환값 없음
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
     * @param  string|null  $guestLookupPassword  비회원 조회 비밀번호
     * @param  array  $shippingInfo  배송 정보
     * @param  string|null  $paymentMethod  결제수단 (현금성 금액 산정용)
     * @return Order 생성된 주문
     */
    protected function createOrder(
        TempOrder $tempOrder,
        OrderCalculationResult $calculationResult,
        OrderStatusEnum $initialStatus,
        array $currencySnapshot,
        ?string $guestLookupPassword = null,
        array $shippingInfo = [],
        ?string $paymentMethod = null
    ): Order {
        $summary = $calculationResult->summary;
        $cashEquivalent = $this->resolveCashEquivalentAmount($paymentMethod, (int) ($summary->finalAmount ?? 0));

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
            // 부가세는 주문 생성 시점에 기록한다. 기존에는 부분취소 재계산에서만 갱신되어
            // 부분취소를 겪은 주문만 값이 정상화되고 나머지는 영구 0 이었다.
            'total_vat_amount' => $this->resolveVatAmount($calculationResult),
            'total_points_used_amount' => $summary->pointsUsed ?? 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
            // 결제예정금액 = 마일리지/예치금 차감 후 실결제액(finalAmount).
            // PG(KG 이니시스 등) 결제 요청 금액·무통장 입금 안내액의 SSoT 이므로 차감 전 paymentAmount 를 쓰면
            // 마일리지 사용분만큼 과다 청구된다. total_amount 와 동일한 차감 후 금액으로 둔다.
            'total_due_amount' => $summary->finalAmount ?? 0,
            // 현금성 금액 = 현금영수증 발급 대상액. 무통장(dbank)만 실입금액 전액이 현금이며,
            // 마일리지 사용분은 현금이 아니므로 차감 후 금액(finalAmount)을 쓴다.
            // 가상계좌는 PG 가 자동 발급하므로 0, 카드/계좌이체/휴대폰도 0.
            'total_cash_equivalent_amount' => $cashEquivalent,
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
            // 주문 시점 마일리지 사용 정책 고정 — 이후 관리자가 한도를 바꿔도 이 주문의 판정 근거는 불변
            'mileage_policy_snapshot' => $this->userMileageService->buildUsagePolicySnapshot(
                (int) ($summary->paymentAmount ?? 0),
                (int) ($summary->pointsUsed ?? 0)
            ),
            'order_meta' => $this->buildOrderMeta($tempOrder) + $this->shippingFallbackMeta($calculationResult),
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
            'mc_total_cash_equivalent_amount' => $this->buildAllCurrencyConverter($currencySnapshot)($cashEquivalent),
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
     * 결제수단별 현금성 금액을 산정합니다.
     *
     * 현금성 금액 = 현금영수증 발급 대상이 되는 실제 현금 입금액.
     *
     * | 결제수단          | 현금성 금액                                  |
     * |------------------|--------------------------------------------|
     * | dbank (무통장)    | 실입금액 전액 (마일리지 차감 후 finalAmount)  |
     * | vbank (가상계좌)  | 0 — PG 가 자동 발급                          |
     * | card/bank/phone  | 0                                           |
     * | point/deposit    | 0 (현금이 아님)                              |
     *
     * 산정 규칙은 PaymentMethodEnum::resolveCashEquivalentAmount() 가 SSoT 다 —
     * 취소 재계산(OrderAdjustmentService)도 같은 규칙을 써야 부분환불 후 재발급액이 맞는다.
     *
     * @param  string|null  $paymentMethod  결제수단 식별자
     * @param  int  $finalAmount  마일리지/예치금 차감 후 실결제액
     * @return int 현금성 금액
     */
    protected function resolveCashEquivalentAmount(?string $paymentMethod, int $finalAmount): int
    {
        // 카탈로그 선언이 있으면 그것이 먼저다(P16). 확장 간편결제는 enum case 가 없어
        // 아래 규칙이 구조적으로 0 을 돌려주고, 현금으로 결제되는 수단이어도 현금영수증
        // 동기화가 언제나 no-op 였다 — 오류도 로그도 남지 않는다.
        if ($paymentMethod !== null) {
            $declared = app(PaymentMethodResolver::class)->declaresCashEquivalent($paymentMethod);

            if ($declared !== null) {
                return $declared ? max(0, $finalAmount) : 0;
            }
        }

        return PaymentMethodEnum::tryFrom((string) $paymentMethod)
            ?->resolveCashEquivalentAmount($finalAmount)
            ?? 0;
    }

    /**
     * 주문 옵션 생성
     *
     * @param  Order  $order  주문
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult  $calculationResult  계산 결과
     * @param  array  $currencySnapshot  통화 스냅샷
     * @param  string|null  $paymentMethod  결제수단 (현금영수증 동기화 판정에 쓴다)
     * @return array<int, OrderOption> productOptionId => OrderOption 매핑
     */
    protected function createOrderOptions(
        Order $order,
        TempOrder $tempOrder,
        OrderCalculationResult $calculationResult,
        array $currencySnapshot,
        ?string $paymentMethod = null
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
                // 옵션별 현금성 안분액. 옵션 finalAmount 합 = 주문 finalAmount 이므로
                // 안분 잔차 없이 합계가 그대로 보존된다.
                'subtotal_cash_equivalent_amount' => $this->resolveCashEquivalentAmount(
                    $paymentMethod,
                    (int) ($item->finalAmount ?? 0),
                ),
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
                'mc_subtotal_cash_equivalent_amount' => $this->buildAllCurrencyConverter($currencySnapshot)(
                    $this->resolveCashEquivalentAmount($paymentMethod, (int) ($item->finalAmount ?? 0)),
                ),
            ]);

            // productOptionId → OrderOption 매핑 저장
            $createdOptions[$item->productOptionId] = $orderOption;
        }

        // 주문 합계 무게/부피는 옵션 소계의 합이다.
        // 주문 생성 시점에는 옵션이 없어 0 으로 두었으므로 여기서 실값으로 채운다
        // (배송사 연동·운임 정산이 이 값을 읽는다).
        $order->update([
            'total_weight' => array_sum(array_map(
                fn (OrderOption $option) => (float) ($option->subtotal_weight ?? 0),
                $createdOptions
            )),
            'total_volume' => array_sum(array_map(
                fn (OrderOption $option) => (float) ($option->subtotal_volume ?? 0),
                $createdOptions
            )),
        ]);

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

                    $localizedKey = is_array($key) ? LocalizedText::pick($key, $locale) : $key;
                    $localizedValue = is_array($value) ? LocalizedText::pick($value, $locale) : $value;

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
     * @return void 반환값 없음
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
     * @param  array|null  $cashReceiptInfo  현금영수증 신청 정보 (type/identifier_type/identifier, 미신청 시 null)
     * @param  array|null  $refundBankInfo  환불 계좌 정보 (bank_code/account_number/holder, 미입력 시 null)
     * @return void 반환값 없음
     */
    protected function createOrderPayment(
        Order $order,
        string $paymentMethod,
        ?string $depositorName,
        ?array $dbankInfo,
        OrderCalculationResult $calculationResult,
        array $currencySnapshot,
        array $ordererInfo = [],
        ?array $cashReceiptInfo = null,
        ?array $refundBankInfo = null
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

        $vatAmount = $this->resolveVatAmount($calculationResult);

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
            $paymentData['vbank_due_at'] = Carbon::now()->addDays($this->resolveAutoCancelDays());
        }

        // 무통장입금 (수동 입금) 정보
        if ($paymentMethod === PaymentMethodEnum::DBANK->value && $dbankInfo) {
            $bankCode = $dbankInfo['bank_code'] ?? null;

            // bank_name이 없으면 설정에서 은행코드 기반으로 조회
            $bankName = $dbankInfo['bank_name'] ?? null;
            if (! $bankName && $bankCode) {
                $bankName = $this->resolveBankName($bankCode);
            }

            $paymentData['dbank_code'] = $bankCode;
            $paymentData['dbank_name'] = $bankName;
            $paymentData['dbank_account'] = $dbankInfo['account_number'] ?? null;
            $paymentData['dbank_holder'] = $dbankInfo['account_holder'] ?? null;
            $paymentData['depositor_name'] = $depositorName ?? $dbankInfo['depositor_name'] ?? null;
            // 입금기한 단일 SSoT: auto_cancel_days (VBANK 와 동일 기준).
            // 클라이언트가 보낸 due_days 는 무시한다 — 기한은 서버 정책이며, 이를 받아들이면
            // 미입금 자동취소 스케줄러와 안내 기한이 어긋난다.
            $paymentData['deposit_due_at'] = Carbon::now()->addDays($this->resolveAutoCancelDays());
        }

        // 현금영수증 신청 정보 — 발급은 입금완료 시점에 리스너가 수행하고, 여기서는 신청 내역만 보관한다.
        // 원본 식별번호는 암호화 컬럼에만 두고 평문 컬럼에는 마스킹본을 저장한다(응답·로그 노출 방지).
        // 구매확정 시점에 PurgeCashReceiptIdentifierListener 가 암호문을 폐기한다.
        if ($cashReceiptInfo !== null) {
            $identifier = $cashReceiptInfo['identifier'];

            $paymentData['is_cash_receipt_requested'] = true;
            $paymentData['cash_receipt_type'] = $cashReceiptInfo['type']->value;
            $paymentData['cash_receipt_identifier_type'] = $cashReceiptInfo['identifier_type'];
            $paymentData['cash_receipt_identifier'] = CashReceiptIdentifierType::mask($identifier);
            $paymentData['cash_receipt_identifier_encrypted'] = $identifier;
        }

        // 환불 계좌 — 무통장은 관리자 수동 이체 대상 계좌, 가상계좌는 PG 환불 API 의 refundReceiveAccount.
        // 미입력 시 관리자 취소 모달에서 다시 받는다.
        if ($refundBankInfo !== null) {
            $paymentData['refund_bank_code'] = $refundBankInfo['bank_code'];
            $paymentData['refund_bank_name'] = $this->resolveBankName($refundBankInfo['bank_code']);
            $paymentData['refund_bank_account'] = $refundBankInfo['account_number'];
            $paymentData['refund_bank_holder'] = $refundBankInfo['holder'];
        }

        $order->payment()->create($paymentData);
    }

    /**
     * 입금기한(일)을 정수로 해석합니다.
     *
     * 설정값은 관리자 화면(HTML number 입력)을 거치면서 문자열로 저장될 수 있고,
     * Carbon 의 날짜 연산은 strict 타입 경계라 문자열을 받으면 TypeError 를 던진다.
     * 저장 타입과 무관하게 정수를 보장하고, 허용 범위를 벗어난 값은 안전한 값으로 보정한다.
     *
     * @return int 입금기한 일수 (허용 범위로 클램프된 값)
     */
    private function resolveAutoCancelDays(): int
    {
        $fallback = self::AUTO_CANCEL_DAYS_DEFAULT;

        $raw = module_setting('sirsoft-ecommerce', 'order_settings.auto_cancel_days', $fallback);
        $days = is_numeric($raw) ? (int) $raw : $fallback;

        // 0 이하(빈 문자열/미설정/오염값)는 즉시 만료를 뜻하므로 기본값으로 되돌린다.
        if ($days <= 0) {
            $days = $fallback;
        }

        // 허용 범위는 검증 규칙(StoreEcommerceSettingsRequest)과 같은 limits 설정을 SSoT 로 쓴다.
        // 여기서 다시 좁히는 것이 아니라, 검증을 거치지 않고 영속된 값(구버전 데이터·직접 편집)만
        // 안전 범위로 되돌린다 — 주문 생성 자체를 실패시키지 않기 위함.
        $min = max(
            self::AUTO_CANCEL_DAYS_MIN_FALLBACK,
            (int) config('sirsoft-ecommerce.limits.auto_cancel_days_min', self::AUTO_CANCEL_DAYS_MIN_FALLBACK)
        );
        $max = max(
            $min,
            (int) config('sirsoft-ecommerce.limits.auto_cancel_days_max', self::AUTO_CANCEL_DAYS_MAX_FALLBACK)
        );

        return max($min, min($max, $days));
    }

    /**
     * 은행코드로 은행명을 조회합니다.
     *
     * 조회 규칙의 SSoT 는 EcommerceSettingsService 다 — 무통장 입금 계좌와 환불 계좌가
     * 같은 은행 목록을 공유하므로 규칙을 복제하지 않고 위임한다.
     *
     * @param  string  $bankCode  은행코드
     * @return string 은행명 (미등록 코드는 코드 그대로)
     */
    protected function resolveBankName(string $bankCode): string
    {
        return $this->settingsService->resolveBankName($bankCode);
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
     * @return void 반환값 없음
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
     * @return string 결과 문자열
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
     *
     * @return DeviceTypeEnum 반환값
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * @return bool 판정 결과
     */
    protected function isFirstOrder(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return ! $this->orderRepository->hasOrderByUser($userId);
    }

    /**
     * 주문/결제 레코드에 기록할 부가세를 결정합니다.
     *
     * 계산 단계에서 옵션별 세율로 산출한 합계를 그대로 사용합니다 — 여기서 다시 단일 세율로
     * 계산하면 세율이 섞인 주문에서 주문과 결제 값이 어긋납니다.
     *
     * 다만 부가세 합산 도입 이전에 만들어진 임시주문이 도입 이후에 결제되면 합계가 비어 있어
     * 부가세가 0 으로 굳습니다. 이 경우에 한해 과세표준에 기본 세율을 적용해 채웁니다
     * (기존 주문 백필과 동일한 기준).
     *
     * @param  OrderCalculationResult  $calculationResult  주문 계산 결과
     * @return int 부가세 금액
     */
    protected function resolveVatAmount(OrderCalculationResult $calculationResult): int
    {
        $summary = $calculationResult->summary;
        $vatAmount = (int) ($summary->vatAmount ?? 0);

        if ($vatAmount > 0) {
            return $vatAmount;
        }

        $taxableAmount = (int) ($summary->taxableAmount ?? 0);

        return $taxableAmount > 0
            ? VatCalculator::fromTaxableAmount($taxableAmount, VatCalculator::DEFAULT_RATE)
            : 0;
    }

    /**
     * 마일리지 사용 정책을 주문 확정 직전에 재검증합니다.
     *
     * 임시주문 단계 검증 이후에 관리자가 한도 설정을 바꾸거나 임시주문이 조작된 경우를
     * 차단하는 2차 방어선입니다. 판정 기준 금액은 마일리지 차감 전 결제금액입니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  OrderCalculationResult  $calculationResult  재계산 결과
     * @return void 반환값 없음
     *
     * @throws MileageValidationException 정책 위반 시
     */
    protected function validateMileageUsagePolicy(TempOrder $tempOrder, OrderCalculationResult $calculationResult): void
    {
        $userId = $tempOrder->user_id;
        $usePoints = (int) $tempOrder->getUsedPoints();

        // 비회원은 마일리지 사용 불가 (임시주문 단계에서 이미 0)
        if ($userId === null || $usePoints <= 0) {
            return;
        }

        $this->userMileageService->validateUsage(
            $userId,
            $usePoints,
            (int) ($calculationResult->summary->paymentAmount ?? 0)
        );
    }

    /**
     * 제출한 배송국가가 임시 주문에 저장된 배송국가와 다르면 임시 주문을 제출값으로 다시 계산해 저장합니다.
     *
     * 배송비는 배송국가로 정해지므로 주문 금액도 실제 배송할 국가 기준이어야 한다. 쿠폰·마일리지는
     * 저장된 값을 그대로 쓴다(TempOrderService::updateTempOrder 가 미전송 항목을 유지).
     * 우편번호(도서산간)는 주문서의 우편번호 검색이 임시 주문에 바로 저장하므로 비교하지 않는다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @param  array  $shippingInfo  주문 요청의 배송지 입력값
     * @return TempOrder|null 다시 저장한 임시 주문, 배송국가가 같으면 null
     */
    protected function alignTempOrderShippingCountry(TempOrder $tempOrder, array $shippingInfo): ?TempOrder
    {
        $submittedCountry = strtoupper(trim((string) ($shippingInfo['country_code'] ?? ''))) ?: 'KR';
        $stored = $tempOrder->getShippingAddress() ?? [];
        $storedCountry = strtoupper(trim((string) ($stored['country_code'] ?? ''))) ?: 'KR';

        if ($submittedCountry === $storedCountry) {
            return null;
        }

        $submittedZipcode = trim((string) ($shippingInfo['zipcode'] ?? $shippingInfo['intl_postal_code'] ?? '')) ?: null;

        return $this->tempOrderService->updateTempOrder(
            userId: $tempOrder->user_id,
            cartKey: $tempOrder->cart_key,
            usePoints: null,
            shippingAddress: new ShippingAddress(countryCode: $submittedCountry, zipcode: $submittedZipcode),
        );
    }

    /**
     * 임시 주문에서 계산 입력 DTO를 구성합니다.
     *
     * TempOrderService::getTempOrderWithCalculation()의 패턴을 따릅니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @return CalculationInput 반환값
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
     * @return array 결과 배열
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
     * @param  array  $shippingInfo  주문서가 보낸 배송지 정보 (국가·우편번호가 정책 선택에 쓰인다)
     * @return array 결과 배열
     */
    protected function buildShippingPolicyAppliedSnapshot(OrderCalculationResult $result, array $shippingInfo = []): array
    {
        $items = [];
        foreach ($result->items as $item) {
            if ($item->appliedShippingPolicy) {
                $items[] = [
                    'product_option_id' => $item->productOptionId,
                    'policy' => $item->appliedShippingPolicy->toArray(),
                ];
            }
        }

        // 배송지(국가/우편번호) 스냅샷 보존 (B5 — 환불/취소 재계산 시 도서산간/국가별 정책 판단 복원).
        //
        // 항목 목록과 배송지 메타는 **별도 키**로 분리한다. 종전에는 `$policies[]` 리스트에
        // `$policies['address']` 를 덧붙여 한 배열에 섞었고, 그 결과 PHP 배열이
        // non-sequential 이 되어 `json_encode` 가 객체로 직렬화했다. 서버는 `is_int($key)` 로
        // 버텼지만 배열을 전제하는 프론트에서 `.find is not a function` 이 나며 마이페이지·
        // 비회원 주문 상세의 배송정책 블록이 조용히 사라졌다.
        return ShippingPolicySnapshot::make($items, [
            'country_code' => strtoupper((string) ($shippingInfo['country_code'] ?? 'KR')),
            'zipcode' => $shippingInfo['zipcode'] ?? $shippingInfo['intl_postal_code'] ?? null,
        ]);
    }

    /**
     * 주문 메타데이터를 구성합니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @return array 결과 배열
     */
    protected function buildOrderMeta(TempOrder $tempOrder): array
    {
        return $this->buildOrderMetaBase($tempOrder);
    }

    /**
     * 외부 배송비 API 폴백 사실을 주문 메타로 만듭니다. (B9)
     *
     * 폴백은 주문을 막지 않는 설계지만, 실제 배송비보다 적게 받았을 수 있는 주문을 운영자가 찾을 수 있게 남긴다.
     *
     * @param  OrderCalculationResult  $calculationResult  주문 계산 결과
     * @return array<string, mixed> 메타 (폴백이 없으면 빈 배열)
     */
    protected function shippingFallbackMeta(OrderCalculationResult $calculationResult): array
    {
        $fallbacks = $calculationResult->metadata['shipping_fee_fallback'] ?? [];

        if (! is_array($fallbacks) || $fallbacks === []) {
            return [];
        }

        return [
            'shipping_fee_fallback' => true,
            'shipping_fee_fallback_policies' => array_values($fallbacks),
        ];
    }

    /**
     * 주문 메타의 기본 항목을 만듭니다.
     *
     * @param  TempOrder  $tempOrder  임시 주문
     * @return array<string, mixed> 메타
     */
    protected function buildOrderMetaBase(TempOrder $tempOrder): array
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
     * @return void 반환값 없음
     */
    protected function clearOrderedCartItems(Order $order): void
    {
        $orderMeta = $order->order_meta ?? [];
        $cartItems = $orderMeta['cart_items'] ?? [];

        // 주문당 한 번만 정리한다 — 가상계좌는 계좌 발급 때 정리하고 입금 완료 때 다시 불리므로, 두 번째 호출이
        // 같은 상품을 더 담아 둔 수량까지 깎지 않게 한다.
        if (empty($cartItems) || ! empty($orderMeta['cart_cleared_at'])) {
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

        $this->orderRepository->update($order, [
            'order_meta' => array_merge($orderMeta, ['cart_cleared_at' => Carbon::now()->toIso8601String()]),
        ]);
    }

    /**
     * 가상계좌 계좌 발급이 끝난 주문의 후속 처리 — 주문한 장바구니 항목을 정리합니다.
     *
     * 가상계좌는 재고 차감이 입금 완료 시점(payment_complete)이라 장바구니도 입금 때까지 남았다. 고객에게는
     * 계좌를 받은 순간 주문이 끝난 것이므로 이 시점에 정리한다. 재고는 종전대로 입금 완료 때 차감한다.
     * 결제사 플러그인이 발급 정보를 저장한 직후 부른다. 같은 주문에 두 번 불러도 한 번만 정리한다.
     * 정리가 실패해도 발급 처리(결제 흐름)는 끊지 않는다 — 장바구니는 입금 완료 때 다시 정리된다.
     *
     * @param  Order  $order  계좌가 발급된 주문
     * @return void 반환값 없음
     */
    public function onVbankAccountIssued(Order $order): void
    {
        try {
            $this->clearOrderedCartItems($order);
        } catch (\Exception $e) {
            // 교착은 삼키지 않는다 — 바깥 트랜잭션이 이미 되돌려진 뒤 이후 쓰기가 autocommit 으로 남는다(C9).
            ConcurrencyErrors::rethrowIfConcurrencyError($e);
            Log::warning('Cart cleanup failed on vbank issue', [
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
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

        // 취소된 주문은 결제완료로 전이하지 않는다 (P4 — 마지막 방어선).
        //
        // 가상계좌 입금통보는 고객이 취소 뒤에 입금해도 도착한다. 막지 않으면 `CANCELLED`
        // 주문이 `PAYMENT_COMPLETE` 로 되살아나 재고·마일리지가 재차감되고, 이미 복원된
        // 쿠폰이 다시 소진된다. 입금된 돈은 환불 절차로 돌려주어야 하는 별개의 사건이므로
        // 여기서 예외를 던지지 않고 조용히 되돌린다 — PG 콜백에 500 을 주면 재시도 폭주가 난다.
        if ($order->order_status === OrderStatusEnum::CANCELLED) {
            Log::warning('취소된 주문에 결제완료 시도 — 무시', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return $order->fresh();
        }

        // PG 금액이 전달된 경우 금액 검증 (컴포넌트 합산 + PG 금액 일치)
        if ($pgAmount !== null) {
            $this->validatePaymentAmount($order, $pgAmount);
        }

        $beforeSnapshot = $order->toArray();

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
            $paymentMethodId = $order->payment->paymentMethodId();
            $timing = $this->settingsService->getStockDeductionTiming($paymentMethodId);
            if ($timing === 'payment_complete') {
                $this->stockService->deductStock($order->load('options'));
                $this->clearOrderedCartItems($order);
            }
        });

        // 변경 전 스냅샷 — 활동 로그 화면은 `changes` 에 실린 항목만 "변경 전 → 변경 후" 표로
        // 그린다(§7-1 D). 줄은 남는데 표가 서지 않으면 운영자는 이 사건으로 **무엇이 바뀌었는지**
        // 를 로그에서 읽을 수 없다. 인수 추가는 하위호환이다 — 기존 3인수 구독자는 초과 인수를
        // 그대로 무시한다(PHP 는 유저 함수에 인수가 더 넘어와도 오류를 내지 않는다).
        HookManager::doAction('sirsoft-ecommerce.order.after_payment_complete', $order, $beforeSnapshot);

        // 주문 확인 알림 훅 (결제 완료 = 주문 확인 시점)
        HookManager::doAction('sirsoft-ecommerce.order.after_confirm', $order);

        // 관리자 신규주문 알림 — 카드(PG) 주문은 주문 생성 시점이 아니라 결제완료 시점에 발송한다.
        // (무통장/비-PG/0원 주문은 각자 다른 시점에 이미 발화하므로 여기서 발화하지 않는다)
        //
        // 조건은 생성 시점(createFromTempOrder)의 분기와 **대칭**이어야 한다. 거기서 발화한
        // 주문이 여기서 또 발화하면 운영자 알림함에 같은 「새로운 주문이 접수되었습니다」가
        // 한 주문으로 두 번 선다 — 어느 쪽이 새 주문인지 구분할 수 없다. 결제완료에 도달한
        // 사실은 바로 위 `order.after_confirm` 이 `order_confirmed_admin` 으로 따로 알린다.
        if ($this->paymentWentThroughPg($order)) {
            HookManager::doAction('sirsoft-ecommerce.order.after_admin_notify', $order);
        }

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
                // 주문서에서 고른 배송지를 그 자리에서 고쳤으면 그 행을 갱신하고, 직접 입력한
                // 주소면 새로 추가한다 — 주문 생성 시점(OrderController)과 같은 갈래다.
                $this->userAddressService->storeShippingInfoForUser(
                    (int) $order->user_id,
                    $orderMeta['shipping_info_for_save'] ?? [],
                    $orderMeta['save_shipping_address_id'] ?? null
                );

                // 메타에서 배송지 저장 플래그 제거
                $order->update([
                    'order_meta' => array_diff_key($orderMeta, array_flip(['save_shipping_address', 'save_shipping_address_id', 'shipping_info_for_save'])),
                ]);
            } catch (\Exception $e) {
                // 교착은 삼키지 않는다 — 바깥 트랜잭션이 이미 되돌려진 뒤 이후 쓰기가 autocommit 으로 남는다(C9).
                ConcurrencyErrors::rethrowIfConcurrencyError($e);
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
        // order_status 가 이미 결제 이후(결제완료·상품준비중·배송…)인 경우(불일치/추가입금)는
        // 아래 입금 기록만 수행한다. 결제완료만 비교하면 상품준비중 주문이 completePayment 로
        // 들어가 결제완료로 **되돌아가고** 마일리지·재고·알림이 다시 돈다.
        if ($markOrderComplete && ! $this->isOrderPastPayment($order)) {
            return $this->completePayment($order, [], $this->depositAmountInMinorUnits($order, $amount));
        }

        // 입금 기록만 수행: 결제완료 전이(마일리지/재고/알림) 없이 payment 만 PAID 로 정합화.
        // 금액 검증은 동일하게 적용(결제예정금액 정확 일치). 입금자명은 위에서 이미 기록함.
        $this->recordDepositPayment($order, $amount);

        return $order->fresh();
    }

    /**
     * 수동 입금확인이 어느 갈래로 처리될지 판정합니다 — confirmManualDeposit 과 같은 기준을 씁니다.
     *
     * 호출부(관리자 입금확인 응답 문장 등)가 사전 상태를 보고 갈래를 따로 추정하면, 이 서비스의 판정이
     * 바뀔 때 응답이 실제 처리와 어긋난다. 처리 전에 이 메서드로 갈래를 받아 쓴다.
     *
     * @param  Order  $order  주문 (처리 전 상태)
     * @param  bool  $markOrderComplete  결제완료 전이 요청 여부
     * @return string `complete`(결제완료 전이) | `record`(입금 기록만, 결제대기 유지) | `status_kept`(이미 결제 이후 — 상태 유지, 결제금액 기록)
     */
    public function resolveManualDepositOutcome(Order $order, bool $markOrderComplete): string
    {
        if ($this->isOrderPastPayment($order)) {
            return 'status_kept';
        }

        return $markOrderComplete ? 'complete' : 'record';
    }

    /**
     * 주문 상태가 이미 결제 이후 단계인지 판정합니다 (취소 제외).
     *
     * @param  Order  $order  주문
     * @return bool 결제완료·상품준비중·배송·구매확정 등이면 true
     */
    private function isOrderPastPayment(Order $order): bool
    {
        return $order->order_status?->isPastPayment() ?? false;
    }

    /**
     * 입금을 기록합니다 — 결제 레코드(payment)를 입금완료(PAID)로 정합화하고, 주문 상태 전이는 하지 않습니다.
     *
     * 입금액이 결제예정금액과 정확히 일치하는지 검증한 뒤(불일치 시 422), 결제 레코드의
     * payment_status·paid_at·입금액을 갱신한다. 주문 상태가 이미 결제 이후 단계면 주문의 결제금액·
     * 미수금·결제일도 함께 채운다. 두 갱신은 한 트랜잭션으로 묶는다 — 한쪽만 남으면 결제행은 PAID 인데
     * 주문은 결제금액 0 인 상태로 굳고 [입금확인] 도 사라진다. 마일리지/재고/알림 등 결제완료 부수효과는
     * 발생시키지 않는다(그건 completePayment 의 책임). 입금자명은 호출부에서 기록한다.
     *
     * @param  Order  $order  주문
     * @param  float  $amount  입금 금액
     * @return void 반환값 없음
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
        // $amount 는 결제 통화(order_currency) 주 단위 입금액이므로 paid_amount_local 에 그대로
        // (센트까지) 들어가고, paid_amount_base 는 base 진실값으로 동결한다.
        $this->validatePaymentAmount($order, $this->depositAmountInMinorUnits($order, $amount));

        // 기록 전 주문 스냅샷 — 입금 기록 훅이 넘겨 활동 로그가 바뀐 결제금액을 변경 내역으로 남긴다.
        $beforeSnapshot = $order->toArray();

        DB::transaction(function () use ($order, $payment, $amount) {
            $payment->update([
                'payment_status' => PaymentStatusEnum::PAID,
                'paid_at' => Carbon::now(),
                'paid_amount_local' => $amount,
                'paid_amount_base' => (float) $order->total_due_amount,
            ]);

            // 주문 상태가 이미 결제 이후면 주문의 결제금액도 함께 채운다.
            //
            // 운영자가 상태를 먼저 올려 둔 뒤(결제대기 → 결제완료 → 상품준비중) 입금을 「기록만」 하면
            // 결제행은 PAID 인데 주문은 결제금액 0·미수금 그대로 남는다. 상태 전이 쪽 정합화
            // (OrderOptionService::transitionOrderStatus)는 이미 지나간 뒤이고, 결제행이 PAID 라
            // [입금확인] 도 사라져 화면으로 되돌릴 방법이 없다 — 이후 환불 예정액이 전부 0원으로
            // 계산된다(실측 2026-10-03). 결제 이전 상태의 주문은 종전대로 결제행만 기록한다.
            if ($this->isOrderPastPayment($order) && (float) $order->total_paid_amount <= 0.0) {
                $paidAmount = (float) $order->total_due_amount;
                $currencySnapshot = $order->currency_snapshot ?? $this->buildCurrencySnapshot();

                $order->update([
                    'paid_at' => $order->paid_at ?? Carbon::now(),
                    'total_paid_amount' => $paidAmount,
                    'total_due_amount' => 0,
                    'mc_total_paid_amount' => $this->buildMultiCurrencyAmount($paidAmount, $currencySnapshot),
                ]);
            }
        });

        // 입금 기록 훅 — 관리자가 "결제완료 전이 없이 입금만 기록"한 경로.
        // 이 경로는 completePayment 를 타지 않으므로 after_payment_complete 가 발화하지 않는다.
        // 현금영수증 자동발급 리스너가 두 훅을 모두 구독해 입금 확인 경로 전체를 덮는다.
        HookManager::doAction('sirsoft-ecommerce.order.after_deposit_recorded', $order->fresh(), $amount, $beforeSnapshot);
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
     * @return void 반환값 없음
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
        $charge = $this->currencyConversionService->resolveSnapshotPaymentCharge(
            (float) $order->total_due_amount,
            $order->currency_snapshot ?? []
        );

        if ($charge['minor_unit_amount'] !== $pgAmount) {
            // 진단 값은 결제 통화 주 단위로 싣는다 — 예상 = 결제예정 청구액, 실제 = 받은 금액.
            // 최소 화폐단위 정수를 그대로 base 통화로 찍으면 USD 주문이 「예상 ₩17, 실제 ₩1,706」 처럼 읽힌다.
            $scale = 10 ** $charge['decimal_places'];

            throw new PaymentAmountMismatchException(
                (float) $charge['amount'],
                $pgAmount / $scale,
                [
                    'stage' => 'pg_amount_verification',
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                ],
                $charge['currency']
            );
        }
    }

    /**
     * 결제 통화 주 단위 입금액을 PG 비교 기준과 같은 최소 화폐단위 정수로 바꿉니다.
     *
     * 관리자 입금확인 창은 결제예정금액을 결제 통화 주 단위($17.06)로 안내하고 그대로 입력받는다.
     * 금액 검증 2단계는 최소 화폐단위(1706)로 비교하므로, 정수로 자르기만 하면(17) 소수 통화 주문의
     * 올바른 입금이 늘 불일치로 거절된다. KRW 처럼 소수 자릿수 0 인 통화는 값이 그대로다.
     *
     * @param  Order  $order  주문 (currency_snapshot 보유)
     * @param  float  $amount  결제 통화 주 단위 입금액
     * @return int 결제 통화 최소 화폐단위 정수
     */
    protected function depositAmountInMinorUnits(Order $order, float $amount): int
    {
        $charge = $this->currencyConversionService->resolveSnapshotPaymentCharge(
            (float) $order->total_due_amount,
            $order->currency_snapshot ?? []
        );

        return (int) round($amount * (10 ** $charge['decimal_places']));
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

        $beforeSnapshot = $order->toArray();

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

        // 변경 전 스냅샷 — 활동 로그 화면은 `changes` 에 실린 항목만 "변경 전 → 변경 후" 표로
        // 그린다(§7-1 D). 줄은 남는데 표가 서지 않으면 운영자는 이 사건으로 **무엇이 바뀌었는지**
        // 를 로그에서 읽을 수 없다. 인수 추가는 하위호환이다 — 기존 3인수 구독자는 초과 인수를
        // 그대로 무시한다(PHP 는 유저 함수에 인수가 더 넘어와도 오류를 내지 않는다).
        HookManager::doAction('sirsoft-ecommerce.order.payment_failed', $order->fresh(), $errorCode, $errorMessage, $beforeSnapshot);

        return $order->fresh();
    }

    // ───────────────────────────────────────────────
    // 수동 결제 처리 확인 창의 세 항목 (Q13·Q14·Q24·Q26·Q28)
    // ───────────────────────────────────────────────

    /**
     * 수동 상태 전이(관리자 단건·일괄·상품 일괄)의 세 효과 — 재고 차감·사용 마일리지 차감·결제금액 기록 —
     * 와 재활성 복원의 해당 여부·금액을 계산합니다. (DB 변경 없음 — 미리보기와 실행 공용 판정)
     *
     * - 재고: P 는 전이 후 활성 행 중 아직 빠지지 않은 행, R-주문은 아직 빠지지 않은 모든 행(종전 재활성
     *   재차감 범위), R-상품은 되살리는 행 중 빠지지 않은 행.
     * - 마일리지: P 는 `is_mileage_deducted` 로 판정한다(결제 전 주문은 복원이 일어난 적이 없다). R 은
     *   원장 순계산(취소로 돌려준 합 − 재활성으로 다시 뺀 합) — 플래그는 복원 뒤에도 true 로 남는다.
     * - 결제금액: P 는 미결제 헤더면 결제예정금액을 기록하고, 결제행이 이미 PAID 면 선택과 무관하게
     *   결제행 수납액으로 맞춘다(auto_sync). R 은 취소로 돌려준 현금을 「다시 받은 것」으로 되돌린다.
     *   돈을 받은 적 없이 취소된 주문은 복원 합계를 받은 것으로 기록할지 묻는다(Q28 — 기본 켬).
     *
     * @param  Order  $order  주문 (options·payment 적재)
     * @param  ManualPaidTransition  $transition  전이
     * @param  bool  $rowsAlreadyRevived  상품 일괄 실행처럼 상품 행을 이미 되살린 뒤에 부르는지
     * @return ManualPaidEffectsPlan 계획
     */
    public function planManualPaidTransitionEffects(Order $order, ManualPaidTransition $transition, bool $rowsAlreadyRevived = false): ManualPaidEffectsPlan
    {
        $currency = $this->userMileageService->baseCurrencyForOrder($order);

        if ($transition->isEmpty()) {
            return ManualPaidEffectsPlan::none($currency);
        }

        $order->loadMissing(['options', 'payment']);

        $restoration = $this->reactivationService()->plan($order, $transition, $rowsAlreadyRevived);

        return new ManualPaidEffectsPlan(
            $this->planStockDeduction($order, $transition),
            $this->planMileageDeduction($order, $transition, $currency),
            $this->planPaidAmountRecord($order, $transition, $restoration),
            $restoration,
            $this->planWarnings($order, $transition),
        );
    }

    /**
     * 확인 창 미리보기 응답을 만듭니다. (목록 일괄·상품 일괄 공용 — DB 변경 없음)
     *
     * 주문별 계획을 같은 판정 메서드(`planManualPaidTransitionEffects`)로 만들고, 같은 회원의 주문은
     * 실행 순서(주문 id 오름차순)대로 잔액을 누적 차감해 「잔액 부족」을 판정한다. 기준 통화가 섞이면
     * 합계 금액 표기는 비운다(화면은 건수만 보인다).
     *
     * @param  array<int, array{0: Order, 1: ManualPaidTransition}>  $entries  [주문, 전이] 목록
     * @param  string  $targetStatus  목표 상태
     * @return array<string, mixed> 미리보기 (target_status·orders·summary)
     */
    public function previewManualPaidEffects(array $entries, string $targetStatus): array
    {
        usort($entries, static fn (array $a, array $b) => (int) $a[0]->id <=> (int) $b[0]->id);

        $balances = [];
        $rows = [];
        $summary = [
            'order_count' => count($entries),
            'affected_order_count' => 0,
            'any_applicable' => false,
            'stock_deduction' => ['applicable_count' => 0],
            'mileage_deduction' => ['applicable_count' => 0, 'total_amount_formatted' => null, 'short_order_numbers' => []],
            'paid_amount_record' => ['applicable_count' => 0, 'total_amount_formatted' => null, 'auto_sync_count' => 0],
            'never_paid_count' => 0,
            'restoration' => ['applicable_count' => 0, 'revived_quantity' => 0, 'coupon_conflict_order_numbers' => [], 'legacy_snapshot_count' => 0],
            'warnings' => ['pg_already_cancelled' => []],
        ];
        $mileageTotals = [];
        $paidTotals = [];

        foreach ($entries as [$order, $transition]) {
            $order->loadMissing(['options', 'payment', 'shippings']);
            $plan = $this->planManualPaidTransitionEffects($order, $transition);
            $currency = (string) $plan->mileage['currency'];
            $number = (string) $order->order_number;

            $balance = null;
            $sufficient = true;
            if ($order->user_id !== null && $plan->mileage['applicable']) {
                $key = $order->user_id.'|'.$currency;
                $balances[$key] ??= (float) $this->userMileageService->availableBalance((int) $order->user_id, $currency);
                $balance = $balances[$key];
                $sufficient = $balance >= $plan->mileage['amount'];
                if ($sufficient) {
                    $balances[$key] -= $plan->mileage['amount'];
                } else {
                    $summary['mileage_deduction']['short_order_numbers'][] = $number;
                }
            }

            $restoration = $plan->restoration->toArray() + [
                'total_amount_before_formatted' => ecommerce_format_price((float) ($plan->restoration->totalsBefore['total_amount'] ?? 0), $currency),
                'total_amount_after_formatted' => ecommerce_format_price((float) ($plan->restoration->totalsAfter['total_amount'] ?? 0), $currency),
            ];

            $rows[] = [
                'order_id' => (int) $order->id,
                'order_number' => $number,
                'transition' => [
                    'enters_paid_course' => $transition->entersPaidCourse,
                    'revives_whole_order' => $transition->revivesWholeOrder,
                    'revived_item_count' => count($transition->revivedItems),
                ],
                'stock_deduction' => [
                    'applicable' => $plan->stock['applicable'],
                    'option_count' => count($plan->stock['option_ids']),
                    'reason' => $plan->stock['reason'],
                ],
                'mileage_deduction' => [
                    'applicable' => $plan->mileage['applicable'],
                    'amount' => $plan->mileage['amount'],
                    'amount_formatted' => ecommerce_format_price($plan->mileage['amount'], $currency),
                    'currency' => $currency,
                    'balance' => $balance,
                    'balance_sufficient' => $sufficient,
                    'reason' => $plan->mileage['reason'],
                ],
                'paid_amount_record' => [
                    'applicable' => $plan->paid['applicable'],
                    'amount' => $plan->paid['amount'],
                    'amount_formatted' => ecommerce_format_price($plan->paid['amount'], $currency),
                    'auto_sync' => $plan->paid['auto_sync_amount'] !== null,
                    'auto_sync_amount' => $plan->paid['auto_sync_amount'],
                    'auto_sync_amount_formatted' => $plan->paid['auto_sync_amount'] !== null
                        ? ecommerce_format_price($plan->paid['auto_sync_amount'], $currency)
                        : null,
                    'reason' => $plan->paid['reason'],
                ],
                'restoration' => $restoration,
                'warnings' => $plan->warnings,
            ];

            if (! $transition->isEmpty()) {
                $summary['affected_order_count']++;
            }
            if ($plan->stock['applicable']) {
                $summary['stock_deduction']['applicable_count']++;
            }
            if ($plan->mileage['applicable']) {
                $summary['mileage_deduction']['applicable_count']++;
                $mileageTotals[$currency] = ($mileageTotals[$currency] ?? 0) + $plan->mileage['amount'];
            }
            if ($plan->paid['applicable']) {
                $summary['paid_amount_record']['applicable_count']++;
                $paidTotals[$currency] = ($paidTotals[$currency] ?? 0) + $plan->paid['amount'];
            }
            if ($plan->paid['auto_sync_amount'] !== null) {
                $summary['paid_amount_record']['auto_sync_count']++;
            }
            if ($plan->paid['reason'] === 'never_paid') {
                $summary['never_paid_count']++;
            }
            if ($plan->restoration->applicable) {
                $summary['restoration']['applicable_count']++;
                $summary['restoration']['revived_quantity'] += $plan->restoration->revivedQuantity();
                if ($plan->restoration->couponConflicts !== []) {
                    $summary['restoration']['coupon_conflict_order_numbers'][] = $number;
                }
                if ($plan->restoration->legacySnapshot) {
                    $summary['restoration']['legacy_snapshot_count']++;
                }
            }
            if (in_array('pg_already_cancelled', $plan->warnings, true)) {
                $summary['warnings']['pg_already_cancelled'][] = $number;
            }
            if ($plan->hasApplicableItems()) {
                $summary['any_applicable'] = true;
            }
        }

        if (count($mileageTotals) === 1) {
            $summary['mileage_deduction']['total_amount_formatted'] = ecommerce_format_price(reset($mileageTotals), (string) array_key_first($mileageTotals));
        }
        if (count($paidTotals) === 1) {
            $summary['paid_amount_record']['total_amount_formatted'] = ecommerce_format_price(reset($paidTotals), (string) array_key_first($paidTotals));
        }

        return ['target_status' => $targetStatus, 'orders' => $rows, 'summary' => $summary];
    }

    /**
     * 수동 상태 전이의 세 효과와 재활성 복원을 적용합니다. (호출부 트랜잭션 내부에서만 호출)
     *
     * 주문 행을 잠그고 다시 읽은 값으로 계획을 다시 만든다(미리보기 이후 바뀐 상태 반영). 운영자가 끈
     * 항목은 건너뛰고, 결제행이 이미 PAID 인 헤더 정합(S2-D4)과 재활성 복원(Q26)은 선택과 무관하게
     * 수행한다. 순서: 쿠폰 충돌 판정 → 마일리지 → 재고 → 복원 → 결제금액 → 장부 정합.
     * 복원이 재고 뒤인 이유는 행이 병합되기 전에 재고 플래그가 정리돼야 해서이고, 장부 정합이 결제
     * 뒤인 이유는 현금성 금액 상한이 회수 뒤의 환불 누적을 읽어야 해서다.
     *
     * @param  Order  $order  주문 (실행 뒤 속성이 갱신된다)
     * @param  ManualPaidTransition  $transition  전이
     * @param  ManualPaidOptions  $options  운영자 선택
     * @return ManualPaidEffectsReport 적용 결과
     *
     * @throws OrderMileageDeductionException 회원 마일리지 잔액이 차감액보다 적을 때
     * @throws InsufficientStockException 재고 부족 (메시지에 주문번호)
     * @throws ReactivationCouponConflictException 재활성할 쿠폰을 다른 주문이 이미 썼을 때
     */
    public function applyManualPaidTransitionEffects(Order $order, ManualPaidTransition $transition, ManualPaidOptions $options): ManualPaidEffectsReport
    {
        if ($transition->isEmpty()) {
            return ManualPaidEffectsReport::none($transition, $options);
        }

        $locked = $this->orderRepository->findForUpdateOrFail($order->id);
        $locked->load(['options', 'payment', 'shippings']);

        $rowsAlreadyRevived = $transition->revivedItems !== [] && ! $transition->revivesWholeOrder;
        $plan = $this->planManualPaidTransitionEffects($locked, $transition, $rowsAlreadyRevived);
        $applied = ManualPaidEffectsReport::emptyApplied();
        $reclaim = ['cash' => 0.0, 'points' => 0];

        $restoring = $transition->revives() && $options->applyRestoration;
        if ($restoring) {
            $this->reactivationService()->assertRestorable($locked, $plan->restoration);
        }

        // ① 마일리지 — 가장 먼저 (일괄이 모든 주문의 부족을 모을 수 있게 이 단계만 따로 부를 수 있다)
        if ($plan->mileage['applicable'] && $options->applyMileageDeduction) {
            $reclaim['points'] = $this->applyManualPaidMileage($locked, $plan);
            $applied['mileage'] = $plan->mileage['amount'];
        }

        // ② 재고 — R-상품 행은 상품 상태 변경이 같은 선택으로 이미 처리했다
        if ($plan->stock['applicable'] && $options->applyStockDeduction) {
            $this->applyManualPaidStock($locked, $transition, $plan);
            $applied['stock'] = true;
        }

        // ③ 재활성 복원 (Q26)
        if ($restoring) {
            $applied['restoration'] = $this->reactivationService()->apply($locked, $transition, $plan->restoration);
            $locked->refresh();
        }

        // ④ 결제금액
        if ($transition->entersPaidCourse) {
            $applied['auto_synced'] = $this->syncPaidHeaderFromSettledPayment($locked);
        }
        $applied['paid'] = $this->applyManualPaidAmount($locked, $plan, $options, $applied['restoration'], $reclaim);

        // ⑤ 장부 정합 (복원이 적용된 경우)
        if (($applied['restoration']['applied'] ?? false) === true) {
            $this->reactivationService()->reconcileLedgerAfterRestoration(
                $locked,
                $applied['restoration']['points_used_after'] ?? null,
                $applied['restoration']['cash_equivalent_after'] ?? null,
            );
        }

        if ($transition->revives()) {
            $this->appendReactivationReclaim($locked, $transition, $reclaim, $applied['restoration']);
        }

        $order->setRawAttributes($locked->fresh()->getAttributes(), true);

        return new ManualPaidEffectsReport($transition, $plan, $options, $applied);
    }

    /**
     * 결제행이 PAID 인데 헤더가 미결제면 결제행 수납액으로 맞춥니다. (S2-D4)
     *
     * 「입금 내역만 기록」은 결제행만 PAID 로 만들고 주문은 결제대기로 남긴다. 그 뒤 운영자가 상태를
     * 올리면 이 경로는 결제완료 처리를 타지 않아 헤더 결제금액이 0 으로 굳고, 결제행이 PAID 라
     * [입금확인]도 사라진다. 결제행이 이미 「돈을 받았다」는 기록이므로 운영자 선택 사항이 아니다.
     * 멱등이다 — 헤더가 채워지면 조건이 거짓이 된다.
     *
     * 결제 전 주문의 취소 접수도 이 메서드로 헤더를 맞춘다(S3-D7) — 받은 돈이 헤더에 없으면 환불 산정이 0원이 된다.
     *
     * @param  Order  $order  주문
     * @return float|null 맞춘 금액 (맞추지 않았으면 null)
     */
    public function syncPaidHeaderFromSettledPayment(Order $order): ?float
    {
        $updates = $this->settledPaymentHeaderUpdates($order);

        if ($updates === null) {
            return null;
        }

        $order->update($updates);

        return (float) $updates['total_paid_amount'];
    }

    /**
     * 결제행 수납액으로 맞출 주문 헤더 값을 계산합니다. (저장하지 않음 — S2-D4 · S3-D7)
     *
     * 판정·금액의 정본이다. 저장하는 `syncPaidHeaderFromSettledPayment()` 와 DB 를 바꾸지 않는 취소 미리보기
     * (`OrderCancellationService::previewRefund`)가 같은 값을 쓴다 — 둘이 따로 계산하면 취소 창이 예고한 환불액과
     * 접수된 환불신청서의 금액이 갈린다.
     *
     * @param  Order  $order  주문
     * @return array{total_paid_amount: float, total_due_amount: float, paid_at: mixed, mc_total_paid_amount: mixed}|null 헤더 갱신값 (맞출 것이 없으면 null)
     */
    public function settledPaymentHeaderUpdates(Order $order): ?array
    {
        $order->loadMissing('payment');
        $settled = $order->payment?->payment_status === PaymentStatusEnum::PAID;
        $headerUnpaid = (float) $order->total_paid_amount <= 0.0 && (float) $order->total_due_amount > 0.0;

        if (! $settled || ! $headerUnpaid) {
            return null;
        }

        // 실제로 수납된 금액은 결제 레코드가 들고 있다 — 미수금을 그대로 옮기지 않고 결제행의 수납액을
        // 쓴다(부분 입금·금액 정정이 있어도 장부가 어긋나지 않는다).
        $paidAmount = $this->resolveSettledBaseAmount($order);
        $snapshot = $order->currency_snapshot ?? $this->buildCurrencySnapshot();

        return [
            'total_paid_amount' => $paidAmount,
            'total_due_amount' => max(0.0, (float) $order->total_due_amount - $paidAmount),
            'paid_at' => $order->paid_at ?? $order->payment->paid_at ?? Carbon::now(),
            'mc_total_paid_amount' => $this->buildMultiCurrencyAmount($paidAmount, $snapshot),
        ];
    }

    /**
     * 결제행이 수납한 금액을 **기준 통화**로 반환합니다. (헤더 결제금액 정합화 전용 — S2-D4)
     *
     * 주문 헤더의 `total_paid_amount` 는 기준 통화 금액이다. 그래서 결제행의 기준 통화 수납액
     * (`paid_amount_base`)만 옮길 수 있다 — 결제 통화 금액(`paid_amount_local`)은 결제 통화가
     * 기준 통화와 같을 때만 같은 단위다. 단위를 세울 수 없으면 미수금(기준 통화)을 쓴다.
     *
     * @param  Order  $order  주문 (payment 로드됨)
     * @return float 기준 통화 수납액
     */
    protected function resolveSettledBaseAmount(Order $order): float
    {
        $payment = $order->payment;
        $base = (float) ($payment->paid_amount_base ?? 0);

        if ($base > 0) {
            return $base;
        }

        $baseCurrency = $this->currencyConversionService->resolveOrderBaseCurrency($order);
        $local = (float) ($payment->paid_amount_local ?? 0);

        if ($local > 0 && ($payment->currency === null || $payment->currency === $baseCurrency)) {
            return $local;
        }

        return (float) $order->total_due_amount;
    }

    /**
     * 운영자가 확인한 결제를 기록합니다. (수동 결제 처리 — 결제금액 기록)
     *
     * 헤더 결제금액·미수금·결제일과 결제행(PAID·수납액·다통화)을 결제완료 처리와 같은 형태로 쓴다.
     * 전제: 운영자가 PG 관리 화면에서 승인을 확인했다. PG 거래번호는 비어 있으므로 결제행 메타에
     * `manual_record`(누가·언제)를 남긴다.
     *
     * @param  Order  $order  주문 (잠근 행)
     * @param  float  $paidAmount  기록할 결제금액 (기준 통화)
     * @return void
     */
    protected function recordManualPayment(Order $order, float $paidAmount): void
    {
        $snapshot = $order->currency_snapshot ?? $this->buildCurrencySnapshot();
        $mc = $this->buildMultiCurrencyAmount($paidAmount, $snapshot);
        $orderCurrency = $snapshot['order_currency'] ?? ($snapshot['base_currency'] ?? 'KRW');

        $order->update([
            'paid_at' => $order->paid_at ?? Carbon::now(),
            'total_paid_amount' => (float) $order->total_paid_amount + $paidAmount,
            'total_due_amount' => max(0.0, (float) $order->total_due_amount - $paidAmount),
            'mc_total_paid_amount' => $this->buildMultiCurrencyAmount((float) $order->total_paid_amount + $paidAmount, $snapshot),
        ]);

        $payment = $order->payment;
        if ($payment === null) {
            return;
        }

        $payment->update([
            'payment_status' => PaymentStatusEnum::PAID,
            'paid_at' => Carbon::now(),
            'paid_amount_local' => $mc[$orderCurrency] ?? $paidAmount,
            'paid_amount_base' => $paidAmount,
            'mc_paid_amount' => $mc,
            'payment_meta' => array_merge($payment->payment_meta ?? [], [
                'manual_record' => ['by' => Auth::id(), 'at' => Carbon::now()->toIso8601String()],
            ]),
        ]);
    }

    /**
     * 재고 차감 계획 — 아직 빠지지 않은 행
     *
     * @param  Order  $order  주문
     * @param  ManualPaidTransition  $transition  전이
     * @return array{applicable: bool, option_ids: array<int, int>, reason: ?string} 계획
     */
    private function planStockDeduction(Order $order, ManualPaidTransition $transition): array
    {
        $ids = [];

        if ($transition->revivesWholeOrder) {
            $ids = $order->options->where('is_stock_deducted', false)->pluck('id')->all();
        } elseif ($transition->entersPaidCourse) {
            $ids = $order->options
                ->reject(fn (OrderOption $row) => in_array($row->option_status?->value, OrderStatusEnum::syncExcludedValues(), true))
                ->where('is_stock_deducted', false)
                ->pluck('id')
                ->all();
        }

        foreach ($transition->revivedItems as $item) {
            if (! $item['is_stock_deducted']) {
                $ids[] = (int) $item['order_option_id'];
            }
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        return ['applicable' => $ids !== [], 'option_ids' => $ids, 'reason' => $ids === [] ? 'no_pending_stock' : null];
    }

    /**
     * 사용 마일리지 차감 계획
     *
     * @param  Order  $order  주문
     * @param  ManualPaidTransition  $transition  전이
     * @param  string  $currency  기준 통화
     * @return array{applicable: bool, amount_p: int, amount_r: int, amount: int, currency: string, reason: ?string} 계획
     */
    private function planMileageDeduction(Order $order, ManualPaidTransition $transition, string $currency): array
    {
        $isMember = $order->user_id !== null;
        $used = max(0, (int) $order->total_points_used_amount);

        $amountP = ($transition->entersPaidCourse && $isMember && ! $order->is_mileage_deducted) ? $used : 0;

        $amountR = 0;
        if ($transition->revives() && $isMember) {
            $reclaimable = (int) floor($this->userMileageService->reclaimablePointsForReactivation($order));
            $amountR = $transition->sweepsAll()
                ? $reclaimable
                : min($reclaimable, (int) floor(array_sum(array_map(
                    static fn (array $item) => (float) $item['subtotal_points_used_amount'] * $item['quantity'] / max(1, $item['row_quantity']),
                    $transition->revivedItems,
                ))));
        }

        $amount = $amountP + $amountR;
        $reason = null;
        if (! $isMember) {
            $reason = 'guest';
        } elseif ($amount <= 0) {
            $reason = $transition->revives() ? 'nothing_to_reclaim' : ($used <= 0 ? 'not_used' : 'already_deducted');
        }

        return [
            'applicable' => $amount > 0,
            'amount_p' => $amountP,
            'amount_r' => $amountR,
            'amount' => $amount,
            'currency' => $currency,
            'reason' => $reason,
        ];
    }

    /**
     * 결제금액 기록 계획
     *
     * @param  Order  $order  주문
     * @param  ManualPaidTransition  $transition  전이
     * @param  ReactivationRestorationPlan  $restoration  복원 계획 (돈을 받은 적 없는 재활성의 금액)
     * @return array{applicable: bool, record_amount_p: float, reclaim_cash_r: float, never_paid_amount: float, amount: float, auto_sync_amount: ?float, reason: ?string} 계획
     */
    private function planPaidAmountRecord(Order $order, ManualPaidTransition $transition, ReactivationRestorationPlan $restoration): array
    {
        $recordP = 0.0;
        $autoSync = null;
        $reason = null;

        if ($transition->entersPaidCourse) {
            $headerUnpaid = (float) $order->total_paid_amount <= 0.0 && (float) $order->total_due_amount > 0.0;
            $settled = $order->payment?->payment_status === PaymentStatusEnum::PAID;

            if ((float) $order->total_paid_amount - (float) $order->total_refunded_amount > 0) {
                $reason = 'already_recorded';
            } elseif ($headerUnpaid && $settled) {
                $autoSync = $this->resolveSettledBaseAmount($order);
                $reason = 'payment_row_settled';
            } elseif ($headerUnpaid) {
                $recordP = (float) $order->total_due_amount;
            } else {
                $reason = 'nothing_due';
            }
        }

        $reclaimCash = 0.0;
        $neverPaid = 0.0;
        if ($transition->revives()) {
            $pool = $this->reclaimableCashForReactivation($order);
            $reclaimCash = $transition->sweepsAll()
                ? $pool
                : min($pool, array_sum(array_map(
                    static fn (array $item) => (float) $item['subtotal_paid_amount'] * $item['quantity'] / max(1, $item['row_quantity']),
                    $transition->revivedItems,
                )));

            // 돈을 받은 적 없이 취소된 주문 — 되살리며 복원 합계를 받은 것으로 기록할지 묻는다(Q28).
            if ($reclaimCash <= 0 && ! $transition->entersPaidCourse && (float) $order->total_paid_amount <= 0.0
                && $restoration->applicable && (float) ($restoration->totalsAfter['total_paid_amount'] ?? 0) > 0) {
                $neverPaid = (float) $restoration->totalsAfter['total_paid_amount'];
                $reason = 'never_paid';
            } elseif ($reclaimCash <= 0 && ! $transition->entersPaidCourse) {
                $reason = 'nothing_to_reclaim';
            }
        }

        $amount = $recordP + $reclaimCash + $neverPaid;

        return [
            'applicable' => $amount > 0,
            'record_amount_p' => $recordP,
            'reclaim_cash_r' => round($reclaimCash, 2),
            'never_paid_amount' => $neverPaid,
            'amount' => round($amount, 2),
            'auto_sync_amount' => $autoSync,
            'reason' => $reason,
        ];
    }

    /**
     * 확인 창 경고 — 카드 등 PG 결제가 이미 취소된 주문을 되살릴 때 (Q24)
     *
     * 되살린 뒤의 환불은 결제수단(PG)으로 정해지는데 PG 거래는 이미 취소돼 다시 취소할 수 없다.
     * 환불수단을 강제하지는 않고 계좌·마일리지로 돌려줘야 한다는 경고만 싣는다.
     *
     * @param  Order  $order  주문
     * @param  ManualPaidTransition  $transition  전이
     * @return array<int, string> 경고 코드
     */
    private function planWarnings(Order $order, ManualPaidTransition $transition): array
    {
        $payment = $order->payment;

        if (! $transition->revives() || $payment === null || (float) $order->total_paid_amount <= 0.0) {
            return [];
        }

        $cancelled = in_array($payment->payment_status, [PaymentStatusEnum::CANCELLED, PaymentStatusEnum::PARTIAL_CANCELLED], true);

        return $cancelled && $payment->needsPgProvider() ? ['pg_already_cancelled'] : [];
    }

    /**
     * 재활성으로 「다시 받은 것」으로 되돌릴 수 있는 현금 (Q14)
     *
     * 취소 출처 완료 환불의 현금 합에서 이미 되돌린 몫을 빼고, 현재 환불 누적으로 자른다.
     *
     * @param  Order  $order  주문
     * @return float 회수 가능 현금 (기준 통화)
     */
    private function reclaimableCashForReactivation(Order $order): float
    {
        $refunded = app(OrderRefundRepositoryInterface::class)->sumCompletedCashRefundForOrder(
            (int) $order->id,
            RefundSourceTypeEnum::cancellationSourceValues(),
        );
        $reclaimed = (float) ($order->order_meta['reactivation_reclaim']['cash'] ?? 0);

        return max(0.0, min($refunded - $reclaimed, (float) $order->total_refunded_amount));
    }

    /**
     * 사용 마일리지를 차감하고 헤더 장부를 맞춥니다. (P·R)
     *
     * R 은 환불 경로 취소가 반환 누적(`total_refunded_points_amount`)을 올렸으므로 그만큼 내린다 — 다시
     * 빼고 헤더를 두면 이후 취소의 「사용 − 반환 = 0」이라 다시 뺀 마일리지를 영영 돌려주지 못한다.
     * 미결제 취소처럼 헤더 사용액이 0 으로 다시 쓰인 몫은 사용액에 더한다. 취소 누적에서도 반환
     * 누적을 내린 만큼 뺀다(화면의 「−취소 금액」 줄).
     *
     * @param  Order  $locked  잠근 주문
     * @param  ManualPaidEffectsPlan  $plan  계획
     * @return int 재활성으로 다시 뺀 마일리지 (R 몫)
     *
     * @throws OrderMileageDeductionException 잔액 부족
     */
    private function applyManualPaidMileage(Order $locked, ManualPaidEffectsPlan $plan): int
    {
        $amountP = (int) $plan->mileage['amount_p'];
        $amountR = (int) $plan->mileage['amount_r'];

        try {
            if ($amountP > 0) {
                $this->deductMileageForOrder($locked, $amountP);
            }

            if ($amountR > 0) {
                HookManager::doAction('sirsoft-ecommerce.mileage.use', $amountR, $locked, ['reason' => 'reactivation']);

                $dec = min((float) $amountR, (float) $locked->total_refunded_points_amount);
                $locked->update([
                    'total_refunded_points_amount' => (float) $locked->total_refunded_points_amount - $dec,
                    'total_points_used_amount' => (float) $locked->total_points_used_amount + ($amountR - $dec),
                    'total_cancelled_amount' => max(0.0, (float) $locked->total_cancelled_amount - $dec),
                ]);
            }
        } catch (MileageValidationException $e) {
            throw OrderMileageDeductionException::insufficientFor($locked, (int) $plan->mileage['amount'], (string) $plan->mileage['currency'], $e);
        }

        return $amountR;
    }

    /**
     * 재고를 차감합니다. (P — 활성 행만 / R-주문 — 종전 재활성 재차감)
     *
     * @param  Order  $locked  잠근 주문
     * @param  ManualPaidTransition  $transition  전이
     * @param  ManualPaidEffectsPlan  $plan  계획
     * @return void
     *
     * @throws InsufficientStockException 재고 부족 (메시지에 주문번호)
     */
    private function applyManualPaidStock(Order $locked, ManualPaidTransition $transition, ManualPaidEffectsPlan $plan): void
    {
        try {
            if ($transition->revivesWholeOrder) {
                $this->stockService->redeductForReactivation($locked);
            } elseif ($transition->entersPaidCourse) {
                // 계획의 대상에는 되살린 상품 행의 **원래 id** 도 들어 있다(미리보기 수량용). 되살린 수량은
                // 상품 상태 변경이 이미 차감했으므로, 여기서는 지금도 취소·클레임 코스에 남은 행을 거른다 —
                // 일부 수량만 되살리면 원래 행은 취소로 남는데, 거르지 않으면 그 남은 수량까지 빠진다.
                $this->stockService->deductStockForOptions(
                    $locked,
                    $locked->options
                        ->whereIn('id', $plan->stock['option_ids'])
                        ->where('is_stock_deducted', false)
                        ->reject(fn (OrderOption $row) => in_array($row->option_status?->value, OrderStatusEnum::syncExcludedValues(), true)),
                );
            }
        } catch (InsufficientStockException $e) {
            throw new InsufficientStockException(
                __('sirsoft-ecommerce::exceptions.manual_paid.stock_insufficient_for_order', [
                    'order_number' => $locked->order_number,
                    'reason' => $e->getMessage(),
                ]),
                $e->getInsufficientItems(),
            );
        }
    }

    /**
     * 결제금액을 기록합니다. (P 결제예정금액 / R 환불 현금 되돌림 / 돈을 받은 적 없는 재활성)
     *
     * 돈을 받은 적 없는 재활성에서 기록을 끄면 복원 합계만큼 미수금을 남긴다 — 결제금액 0·미수금 0 인
     * 판매 상태 주문은 받을 돈도 돌려줄 돈도 없는 모순이 된다.
     *
     * @param  Order  $locked  잠근 주문
     * @param  ManualPaidEffectsPlan  $plan  계획
     * @param  ManualPaidOptions  $options  운영자 선택
     * @param  array<string, mixed>  $restored  복원 적용 보고
     * @param  array{cash: float, points: int}  $reclaim  재활성 회수 기록 (참조)
     * @return float 기록한 금액
     */
    private function applyManualPaidAmount(Order $locked, ManualPaidEffectsPlan $plan, ManualPaidOptions $options, array $restored, array &$reclaim): float
    {
        $neverPaid = ($restored['applied'] ?? false) === true
            ? (float) ($restored['final_amount'] ?? 0)
            : (float) $plan->paid['never_paid_amount'];

        if (! $plan->paid['applicable'] || ! $options->recordPaidAmount) {
            if ($plan->paid['never_paid_amount'] > 0 && $neverPaid > 0 && $options->applyRestoration) {
                $locked->update(['total_due_amount' => $neverPaid]);
            }

            return 0.0;
        }

        $recorded = 0.0;

        if ($plan->paid['record_amount_p'] > 0) {
            $this->recordManualPayment($locked, (float) $plan->paid['record_amount_p']);
            $recorded += (float) $plan->paid['record_amount_p'];
        }

        if ($plan->paid['reclaim_cash_r'] > 0) {
            $cash = (float) $plan->paid['reclaim_cash_r'];
            $locked->update([
                'total_refunded_amount' => max(0.0, (float) $locked->total_refunded_amount - $cash),
                'total_cancelled_amount' => max(0.0, (float) $locked->total_cancelled_amount - $cash),
            ]);
            $reclaim['cash'] = $cash;
            $recorded += $cash;
        }

        if ($plan->paid['never_paid_amount'] > 0 && $neverPaid > 0) {
            $locked->update(['total_due_amount' => $neverPaid]);
            $this->recordManualPayment($locked, $neverPaid);
            $recorded += $neverPaid;
        }

        return $recorded;
    }

    /**
     * 재활성 회수 기록을 `order_meta.reactivation_reclaim` 에 남깁니다.
     *
     * 취소·환불 문서는 고치지 않는다 — 재활성은 별도 사건이다. 누적 `cash` 는 다음 재활성의 현금 회수
     * 가능액에서 빠진다. 일괄 처리는 한 전이를 두 단계로 나눠 부르므로 같은 전이의 두 번째 호출은 앞
     * 항목에 합친다.
     *
     * @param  Order  $locked  잠근 주문
     * @param  ManualPaidTransition  $transition  전이
     * @param  array{cash: float, points: int}  $reclaim  이번 회수
     * @param  array<string, mixed>  $restored  복원 적용 보고
     * @return void
     */
    private function appendReactivationReclaim(Order $locked, ManualPaidTransition $transition, array $reclaim, array $restored): void
    {
        $restoredApplied = ($restored['applied'] ?? false) === true;
        if ($reclaim['cash'] <= 0 && $reclaim['points'] <= 0 && ! $restoredApplied) {
            return;
        }

        $locked->refresh();
        $meta = $locked->order_meta ?? [];
        $state = $meta['reactivation_reclaim'] ?? ['cash' => 0, 'points' => 0, 'history' => []];
        $state['cash'] = round((float) ($state['cash'] ?? 0) + $reclaim['cash'], 2);
        $state['points'] = (int) ($state['points'] ?? 0) + $reclaim['points'];

        $entry = [
            'at' => Carbon::now()->toIso8601String(),
            'by' => Auth::id(),
            'trigger' => $transition->trigger,
            'cash' => $reclaim['cash'],
            'points' => $reclaim['points'],
        ];
        if ($restoredApplied) {
            $entry['restoration'] = [
                'revived' => $restored['revived'] ?? [],
                'totals_before' => $restored['totals_before'] ?? [],
                'totals_after' => $restored['totals_after'] ?? [],
                'coupons_reclaimed' => $restored['coupons_reclaimed'] ?? [],
                'legacy_snapshot' => $restored['legacy_snapshot'] ?? false,
            ];
        }

        $history = $state['history'] ?? [];
        $key = spl_object_id($transition);
        $index = $this->reclaimHistoryIndex[$locked->id][$key] ?? null;
        if ($index !== null && isset($history[$index])) {
            $history[$index]['cash'] = (float) $history[$index]['cash'] + $reclaim['cash'];
            $history[$index]['points'] = (int) $history[$index]['points'] + $reclaim['points'];
            if (isset($entry['restoration'])) {
                $history[$index]['restoration'] = $entry['restoration'];
            }
        } else {
            $history[] = $entry;
            $this->reclaimHistoryIndex[$locked->id][$key] = array_key_last($history);
        }
        $state['history'] = $history;
        $meta['reactivation_reclaim'] = $state;

        $locked->update(['order_meta' => $meta]);
    }

    /**
     * 재활성 복원 서비스를 늦게 해석합니다. (OrderOptionService 와의 순환을 끊는다)
     *
     * @return OrderReactivationService 복원 서비스
     */
    protected function reactivationService(): OrderReactivationService
    {
        return $this->reactivationService ??= app(OrderReactivationService::class);
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
     */
    protected function syncOptionStatuses(Order $order, OrderStatusEnum $newStatus): void
    {
        $query = $order->options()
            ->whereNotIn('option_status', OrderStatusEnum::syncExcludedValues());

        // 전/후를 적으려면 갱신 **전** 상태를 읽어야 한다. 이 경로는 관계 빌더의 raw update
        // 라 `OrderOptionService` 의 어떤 훅도 지나지 않아, 결제완료·0원 주문 확정·결제실패가
        // 끌고 간 상품 전이가 활동로그에 **한 줄도 남지 않았다**(A3·A4·A5).
        $changes = $query->get(['id', 'order_id', 'option_status'])
            ->map(fn ($option): array => [
                'order_option_id' => (int) $option->id,
                'order_id' => (int) $option->order_id,
                'previous' => $option->option_status instanceof OrderStatusEnum
                    ? $option->option_status->value
                    : (string) $option->option_status,
                'new' => $newStatus->value,
            ])
            ->filter(fn (array $change): bool => $change['previous'] !== $change['new'])
            ->values()
            ->all();

        $query->update([
            'option_status' => $newStatus->value,
            'updated_at' => Carbon::now(),
        ]);

        if ($changes !== []) {
            HookManager::doAction(
                'sirsoft-ecommerce.order_option.after_sync_status',
                $changes,
                $newStatus->value,
                'order_status_sync',
            );
        }
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
