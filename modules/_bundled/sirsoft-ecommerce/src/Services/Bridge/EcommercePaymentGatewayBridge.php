<?php

namespace Modules\Sirsoft\Ecommerce\Services\Bridge;

use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\BuyerContact;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ChargeAmount;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ClaimPaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ClaimPaymentTotals;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\MetaEncoding;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\OrderRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentBrief;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentCompletion;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFailure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentFilters;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentOutcome;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\PaymentRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ReceiptRequester;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RefundRecord;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RetryRestoreRequest;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\RetryRestoreResult;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\ShippingAddress;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\TaxSplit;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\VbankIssuance;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Data\WindowClosure;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ChargeNotResolvable;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\InvalidPaymentTransition;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\PaymentNotFound;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions\ProviderMismatch;
use Modules\Sirsoft\Ecommerce\Contracts\Payment\PaymentGatewayBridge;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CurrencyConversionException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\PaymentGatewayRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\DepositAfterCancelRecorder;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 결제사 플러그인 공개 계약 구현 — 기존 이커머스 서비스와 저장소에 위임만 한다 (#916).
 *
 * 동작이 바뀌는 것은 재결제 복구(restoreForRetry) 하나다. 서비스는 호출 때마다 컨테이너에서 해석한다 —
 * 시험이 바꿔 끼운 서비스와 통화 설정 초기화가 이 구현에도 닿게 하려는 것이다.
 *
 * 결제 행 범위: 조회는 호출 결제사 행만 돌려주고, 주문번호 단건 조회는 아직 결제 전인 행(다른 결제사 id 로 만들어진 행)도 보인다.
 * 쓰기는 결제 전 행이면 결제사 id 가 달라도 진행하고, 결제(발급) 뒤 행이 다른 결제사 것이면 ProviderMismatch 를 던진다.
 *
 * @internal 다른 확장은 {@see PaymentGatewayBridge} 또는 하위 인터페이스로 주입받는다.
 */
class EcommercePaymentGatewayBridge implements PaymentGatewayBridge
{
    /** recordPgAttributes() 가 받는 컬럼(정확히 일치) */
    public const ALLOWED_PG_ATTRIBUTES = [
        'transaction_id', 'merchant_order_id', 'embedded_pg_provider', 'payment_device', 'is_interest_free', 'receipt_url',
    ];

    /** recordPgAttributes() 가 받는 컬럼 접두 */
    public const ALLOWED_PG_ATTRIBUTE_PREFIXES = ['card_', 'vbank_', 'dbank_', 'buyer_', 'cash_receipt_'];

    /** 결제 전으로 보는 결제 상태 — 다른 결제사가 넘겨받을 수 있다 */
    private const CLAIMABLE_STATUSES = [
        PaymentStatusEnum::READY,
        PaymentStatusEnum::IN_PROGRESS,
        PaymentStatusEnum::FAILED,
        PaymentStatusEnum::CANCELLED,
    ];

    /**
     * EcommercePaymentGatewayBridge 생성자
     *
     * @param  OrderRepositoryInterface  $orders  주문 저장소
     * @param  OrderPaymentRepositoryInterface  $payments  주문 결제 저장소
     * @param  ClaimPaymentRepositoryInterface  $claimPayments  클레임 결제 저장소
     * @param  PaymentGatewayRepositoryInterface  $gateway  결제사 계약 전용 저장소
     */
    public function __construct(
        private OrderRepositoryInterface $orders,
        private OrderPaymentRepositoryInterface $payments,
        private ClaimPaymentRepositoryInterface $claimPayments,
        private PaymentGatewayRepositoryInterface $gateway,
    ) {}

    // ───────────────────────────────────────────────
    // PaymentLookup
    // ───────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string|null  $transactionId  거래번호
     * @return bool 결제 완료 행이 있으면 true
     */
    public function isTransactionPaid(string|array $pg, ?string $transactionId): bool
    {
        if ($transactionId === null || $transactionId === '') {
            return false;
        }

        return $this->gateway->isTransactionPaid($this->pgList($pg), $transactionId);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $transactionId  거래번호
     * @param  PaymentFilters|null  $filters  추가 조건
     * @return PaymentRecord|null 없으면 null
     */
    public function findPaymentByTransactionId(string|array $pg, string $transactionId, ?PaymentFilters $filters = null): ?PaymentRecord
    {
        return $this->record($this->gateway->firstPayment($this->pgList($pg), ['transaction_id' => $transactionId] + $this->criteria($filters)));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @param  PaymentFilters|null  $filters  추가 조건
     * @return PaymentRecord|null 없으면 null
     */
    public function findPaymentByOrderNumber(string|array $pg, string $orderNumber, ?PaymentFilters $filters = null): ?PaymentRecord
    {
        $criteria = ['order_number' => $orderNumber] + $this->criteria($filters);

        if ($filters?->requiresTransactionId()) {
            return $this->record($this->gateway->firstPayment($this->pgList($pg), $criteria));
        }

        // 거래번호 조건이 없으면 결제 전 행도 보인다 — 주문 생성 때 기본 결제사 id 가 들어간 행을 콜백이 찾아야 한다.
        $pgs = $this->pgList($pg);
        foreach ($this->gateway->listPayments(null, $criteria) as $payment) {
            if ($this->isVisibleForOrderLookup($payment, $pgs)) {
                return $this->record($payment);
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @return array<int, PaymentRecord> 결제 행 목록
     */
    public function listEscrowPayments(string|array $pg, string $orderNumber): array
    {
        return $this->records($this->gateway->listPayments($this->pgList($pg), ['order_number' => $orderNumber, 'escrow' => true]));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  DateTimeInterface  $since  결제 행 생성 시각 하한
     * @param  bool  $requireMeta  결제 meta 가 있는 행만
     * @return array<int, PaymentRecord> 결제 행 목록
     */
    public function listPaymentsByProvider(string|array $pg, DateTimeInterface $since, bool $requireMeta = false): array
    {
        return $this->records($this->gateway->listPayments($this->pgList($pg), ['created_since' => $since, 'require_meta' => $requireMeta]));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  DateTimeInterface  $since  결제 행 생성 시각 하한
     * @param  bool  $requireMeta  결제 meta 가 있는 행만
     * @return array<int, PaymentBrief> 결제 행 요약 목록
     */
    public function listPaymentBriefsByProvider(string|array $pg, DateTimeInterface $since, bool $requireMeta = false): array
    {
        return $this->gateway->listPaymentBriefs($this->pgList($pg), ['created_since' => $since, 'require_meta' => $requireMeta])
            ->map(fn (OrderPayment $payment): PaymentBrief => new PaymentBrief(
                orderNumber: (string) $payment->joined_order_number,
                transactionId: $payment->transaction_id === null ? null : (string) $payment->transaction_id,
                paymentMeta: is_array($payment->payment_meta) ? $payment->payment_meta : [],
            ))
            ->values()
            ->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @param  ReceiptRequester  $requester  요청자
     * @param  PaymentFilters|null  $filters  추가 조건
     * @return PaymentRecord|null 없거나 소유 조건을 못 넘으면 null
     */
    public function findReceiptPayment(string|array $pg, string $orderNumber, ReceiptRequester $requester, ?PaymentFilters $filters = null): ?PaymentRecord
    {
        $criteria = ['order_number' => $orderNumber] + $this->criteria($filters);

        switch ($requester->kind) {
            case ReceiptRequester::MEMBER:
                $criteria['owner_user_id'] = (int) $requester->userId;
                break;
            case ReceiptRequester::GUEST_TOKEN:
                $verified = app(GuestOrderAuthService::class)->verifyToken($requester->token, $orderNumber);
                if ($verified === null) {
                    return null;
                }
                $criteria['guest_only'] = true;
                $criteria['order_key'] = (int) $verified->id;
                break;
            default:
                $criteria['guest_only'] = true;
        }

        return $this->record($this->gateway->firstPayment($this->pgList($pg), $criteria));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $orderNumber  주문번호
     * @return RefundRecord|null 없으면 null
     */
    public function latestRefund(string|array $pg, string $orderNumber): ?RefundRecord
    {
        $payment = $this->gateway->firstPayment($this->pgList($pg), ['order_number' => $orderNumber]);
        if ($payment === null) {
            return null;
        }

        $refund = $this->gateway->latestRefundForOrder((int) $payment->order_id);

        return $refund === null ? null : RefundRecord::fromModel($refund);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Order|string  $order  주문 또는 주문번호
     * @param  float|null  $baseAmount  기준 통화 금액(null 이면 결제 예정액)
     * @return ChargeAmount 청구액
     */
    public function resolveChargeAmount(Order|string $order, ?float $baseAmount = null): ChargeAmount
    {
        $model = $order instanceof Order ? $order : $this->orderOrFail($order);

        try {
            $charge = app(CurrencyConversionService::class)->resolveSnapshotPaymentCharge(
                $baseAmount ?? (float) $model->total_due_amount,
                $model->currency_snapshot ?? []
            );
        } catch (CurrencyConversionException $e) {
            throw ChargeNotResolvable::from($e);
        }

        return ChargeAmount::fromArray($charge);
    }

    /**
     * {@inheritDoc}
     *
     * @param  Order|string  $order  주문 또는 주문번호
     * @return TaxSplit 면세, 과세, 부가세
     */
    public function pgTaxSplit(Order|string $order): TaxSplit
    {
        $model = $order instanceof Order ? $order : $this->orderOrFail($order);

        return new TaxSplit(
            taxFree: (float) $model->pgTaxFreeAmount(),
            taxable: (float) $model->pgTaxableAmount(),
            vat: (float) ($model->total_vat_amount ?? 0),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  OrderPayment  $payment  훅 인자 결제 행
     * @return PaymentRecord DTO
     */
    public function describePayment(OrderPayment $payment): PaymentRecord
    {
        $orderNumber = $payment->relationLoaded('order') ? $payment->order?->order_number : null;

        return PaymentRecord::fromModel($payment, $orderNumber === null ? null : (string) $orderNumber);
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null 기본 결제사 id
     */
    public function defaultPgProvider(): ?string
    {
        $value = app(EcommerceSettingsService::class)->getSetting('order_settings.default_pg_provider');

        return is_string($value) && $value !== '' ? $value : null;
    }

    // ───────────────────────────────────────────────
    // OrderAccess
    // ───────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return OrderRecord|null 없으면 null
     */
    public function findOrderByNumber(string $orderNumber, string|array $pg): ?OrderRecord
    {
        $order = $this->processing()->findByOrderNumber($orderNumber);

        return $order === null ? null : $this->orderRecord($order, $pg);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string|array<int, string>  $pg  결제사 id(호출 결제사 기록용, 반환 범위에는 쓰지 않음)
     * @return Order|null 없으면 null
     */
    public function findOrderModel(string $orderNumber, string|array $pg): ?Order
    {
        $this->pgList($pg);

        return $this->processing()->findByOrderNumber($orderNumber);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @return ShippingAddress|null 없으면 null
     */
    public function getShippingAddress(string $orderNumber): ?ShippingAddress
    {
        $address = $this->gateway->findOrderByNumber($orderNumber)?->shippingAddress;

        return $address === null ? null : ShippingAddress::fromModel($address);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @return BuyerContact|null 주문이 없으면 null
     */
    public function findBuyerContact(string $orderNumber): ?BuyerContact
    {
        $order = $this->gateway->findOrderByNumber($orderNumber);
        if ($order === null) {
            return null;
        }

        $payment = $order->payment;
        $address = $order->shippingAddress;
        $text = static fn ($value): ?string => $value === null ? null : (string) $value;

        // 칸마다 결제 행 → 주문자 순(null 일 때만 다음 값) — 현금영수증 리스너가 주문 Model 관계로 읽던 순서 그대로다.
        return new BuyerContact(
            name: $text($payment?->buyer_name ?? $address?->orderer_name),
            email: $text($payment?->buyer_email ?? $address?->orderer_email),
            phone: $text($payment?->buyer_phone ?? $address?->orderer_phone),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $namespace  meta 키
     * @return array<string, mixed>|null 값
     */
    public function getOrderMeta(string $orderNumber, string $pg, string $namespace): ?array
    {
        $this->assertNamespace($pg, $namespace);

        $order = $this->gateway->findOrderByNumber($orderNumber, ['id', 'order_meta']);
        $value = $order?->order_meta[$namespace] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|null  $token  비회원 조회 토큰
     * @param  string  $orderNumber  주문번호
     * @return bool 맞으면 true
     */
    public function verifyGuestOrderToken(?string $token, string $orderNumber): bool
    {
        return app(GuestOrderAuthService::class)->verifyToken($token, $orderNumber) !== null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @return array<string, mixed>|null 토큰 정보
     */
    public function issueGuestOrderToken(string $orderNumber): ?array
    {
        $order = $this->gateway->findOrderByNumber($orderNumber);
        if ($order === null || $order->user_id !== null) {
            return null;
        }

        return app(GuestOrderAuthService::class)->issueForOrder($order);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $permission  권한 식별자
     * @return bool|null 범위 판정(주문 없음 null)
     */
    public function isOrderWithinScope(string $orderNumber, string $permission): ?bool
    {
        $order = $this->gateway->findOrderByNumber($orderNumber);

        return $order === null ? null : PermissionHelper::checkScopeAccess($order, $permission);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @param  string  $transactionId  거래번호
     * @param  string  $permission  권한 식별자
     * @return bool 범위 판정
     */
    public function isTransactionWithinScope(string|array $pg, string $transactionId, string $permission): bool
    {
        if (PermissionHelper::getEffectiveScope($permission) === null) {
            return true;
        }

        $order = $this->gateway->findOrderByProviderTransaction($this->pgList($pg), $transactionId);

        return $order !== null && PermissionHelper::checkScopeAccess($order, $permission);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $orderNumbers  주문번호 목록
     * @param  string  $permission  권한 식별자
     * @return array<int, string> 범위 안 주문번호
     */
    public function orderNumbersWithinScope(array $orderNumbers, string $permission): array
    {
        if ($orderNumbers === [] || PermissionHelper::getEffectiveScope($permission) === null) {
            return $orderNumbers;
        }

        return $this->gateway->filterOrderNumbersByScope($orderNumbers, $permission);
    }

    // ───────────────────────────────────────────────
    // ClaimPaymentLookup
    // ───────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param  string  $number  콜백 주문번호
     * @return bool 클레임 결제 번호면 true
     */
    public function isClaimPaymentNumber(string $number): bool
    {
        return ClaimPayment::isClaimPaymentNumber($number);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $paymentNumber  결제 번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return ClaimPaymentRecord|null 없으면 null
     */
    public function findClaimPayment(string $paymentNumber, string|array $pg): ?ClaimPaymentRecord
    {
        $payment = $this->findClaimPaymentModel($paymentNumber, $pg);

        return $payment === null ? null : $this->claimPaymentRecord($payment);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $paymentNumber  결제 번호
     * @param  string|array<int, string>  $pg  결제사 id
     * @return ClaimPayment|null 없으면 null
     */
    public function findClaimPaymentModel(string $paymentNumber, string|array $pg): ?ClaimPayment
    {
        $payment = $this->claimPayments->findByPaymentNumber($paymentNumber);
        if ($payment === null) {
            return null;
        }

        $provider = $payment->pg_provider;

        return $provider === null || $provider === '' || in_array($provider, $this->pgList($pg), true) ? $payment : null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return ClaimPaymentRecord DTO
     */
    public function describeClaimPayment(ClaimPayment $payment): ClaimPaymentRecord
    {
        return $this->claimPaymentRecord($payment);
    }

    /**
     * {@inheritDoc}
     *
     * @param  ClaimPayment|string  $payment  결제 건 또는 결제 번호
     * @return ChargeAmount|null 통화를 해석할 수 없으면 null
     */
    public function resolveClaimCharge(ClaimPayment|string $payment): ?ChargeAmount
    {
        $model = $payment instanceof ClaimPayment ? $payment : $this->claimPayments->findByPaymentNumber($payment);
        if ($model === null) {
            return null;
        }

        $charge = app(ClaimPaymentService::class)->resolvePaymentCharge($model);

        return $charge === null ? null : ChargeAmount::fromArray($charge);
    }

    /**
     * {@inheritDoc}
     *
     * @param  ClaimPayment|string  $payment  결제 건 또는 결제 번호
     * @return ClaimPaymentTotals 합계
     */
    public function claimPaymentTotals(ClaimPayment|string $payment): ClaimPaymentTotals
    {
        $model = $payment instanceof ClaimPayment ? $payment : $this->claimPayments->findByPaymentNumber($payment);
        if ($model === null) {
            throw new PaymentNotFound("Claim payment not found: {$payment}");
        }

        $service = app(ClaimPaymentService::class);

        return new ClaimPaymentTotals(
            paymentCurrencyTotal: (float) $service->resolvePaymentCurrencyTotal($model),
            refundedPaymentCurrencyTotal: (float) $service->resolveRefundedPaymentCurrencyTotal($model),
            refundedAmount: (float) $model->refunded_amount,
        );
    }

    // ───────────────────────────────────────────────
    // PaymentLifecycle
    // ───────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  PaymentCompletion  $completion  결제사 응답 값
     * @param  int|null  $pgAmount  결제사 승인 금액
     * @param  Closure|null  $guard  잠금 안 판정
     * @return PaymentOutcome 결과
     */
    public function completePayment(string $orderNumber, string $pg, PaymentCompletion $completion, ?int $pgAmount = null, ?Closure $guard = null): PaymentOutcome
    {
        $order = $this->orderOrFail($orderNumber);

        $run = function (Order $order, ?OrderPayment $payment) use ($pg, $completion, $pgAmount, $guard): PaymentOutcome {
            if ($payment !== null) {
                $this->assertWritable($payment, $pg);
            }

            if ($guard !== null) {
                $reason = $guard($this->orderRecord($order, $pg), $payment === null ? null : PaymentRecord::fromModel($payment, $order->order_number));
                if (is_string($reason)) {
                    return new PaymentOutcome(false, $reason, $this->orderRecord($order, $pg));
                }
            }

            $before = $order->order_status;
            $data = $completion->toPaymentData(is_array($payment?->payment_meta) ? $payment->payment_meta : [], $pg);

            try {
                $fresh = $this->processing()->completePayment($order, $data, $pgAmount);
            } catch (CurrencyConversionException $e) {
                throw ChargeNotResolvable::from($e);
            }

            $applied = ! in_array($before, [OrderStatusEnum::PAYMENT_COMPLETE, OrderStatusEnum::CANCELLED], true)
                && $fresh->order_status === OrderStatusEnum::PAYMENT_COMPLETE;

            return new PaymentOutcome($applied, $applied ? null : 'ignored', $this->orderRecord($fresh, $pg));
        };

        if ($guard === null) {
            // 기존 결제 완료 서비스 자체 트랜잭션만 — 결제 완료 훅이 커밋 뒤에 도는 현행 동작을 바꾸지 않는다.
            return $run($order, $order->payment);
        }

        return DB::transaction(function () use ($order, $run) {
            [$lockedOrder, $lockedPayment] = $this->lockPair($order);

            return $run($lockedOrder, $lockedPayment);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $errorCode  실패 코드
     * @param  string  $errorMessage  실패 메시지
     * @return OrderRecord 처리 뒤 주문
     */
    public function failPayment(string $orderNumber, string $pg, string $errorCode, string $errorMessage): OrderRecord
    {
        $order = $this->orderOrFail($orderNumber);
        if ($order->payment !== null) {
            $this->assertWritable($order->payment, $pg);
        }

        return $this->orderRecord($this->processing()->failPayment($order, $errorCode, $errorMessage), $pg);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string|null  $cancelCode  취소 코드
     * @param  string|null  $cancelMessage  취소 메시지
     * @return OrderRecord 처리 뒤 주문
     */
    public function recordPaymentCancellation(string $orderNumber, string $pg, ?string $cancelCode = null, ?string $cancelMessage = null): OrderRecord
    {
        $order = $this->orderOrFail($orderNumber);
        if ($order->payment !== null) {
            $this->assertWritable($order->payment, $pg);
        }

        return $this->orderRecord($this->processing()->recordPaymentCancellation($order, $cancelCode, $cancelMessage), $pg);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  PaymentFailure  $failure  실패 기록
     * @return OrderRecord 처리 뒤 주문
     */
    public function recordPaymentFailure(string $orderNumber, string $pg, PaymentFailure $failure): OrderRecord
    {
        $order = $this->orderOrFail($orderNumber);
        $this->writeFailure($order, $pg, $failure);

        return $this->orderRecord($this->orderOrFail($orderNumber), $pg);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  WindowClosure  $closure  닫힘 정보
     * @param  Closure|null  $guard  잠금 안 판정
     * @return PaymentOutcome 결과
     */
    public function markPaymentWindowClosed(string $orderNumber, string $pg, WindowClosure $closure, ?Closure $guard = null): PaymentOutcome
    {
        $order = $this->orderOrFail($orderNumber);

        $run = function (Order $order, ?OrderPayment $payment) use ($orderNumber, $pg, $closure, $guard): PaymentOutcome {
            if ($payment !== null) {
                $this->assertWritable($payment, $pg);
            }

            if (! $order->order_status->isBeforePayment()) {
                return new PaymentOutcome(false, 'order_not_payable', $this->orderRecord($order, $pg));
            }

            if ($guard !== null) {
                $reason = $guard($this->orderRecord($order, $pg), $payment === null ? null : PaymentRecord::fromModel($payment, $order->order_number));
                if (is_string($reason)) {
                    return new PaymentOutcome(false, $reason, $this->orderRecord($order, $pg));
                }
            }

            $failed = $this->processing()->failPayment($order, $closure->code, $closure->message);
            $cancelled = $this->processing()->recordPaymentCancellation($failed, $closure->code, $closure->cancelMessage);

            if ($closure->failure !== null) {
                $this->writeFailure($cancelled, $pg, $closure->failure);
            }

            return new PaymentOutcome(true, null, $this->orderRecord($this->orderOrFail($orderNumber), $pg));
        };

        if ($guard === null) {
            return $run($order, $order->payment);
        }

        return DB::transaction(function () use ($order, $run) {
            [$lockedOrder, $lockedPayment] = $this->lockPair($order);

            return $run($lockedOrder, $lockedPayment);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  VbankIssuance  $issuance  발급 정보
     * @param  Closure|null  $guard  잠금 안 판정
     * @return PaymentOutcome 결과
     */
    public function onVbankAccountIssued(string $orderNumber, string $pg, VbankIssuance $issuance, ?Closure $guard = null): PaymentOutcome
    {
        $order = $this->orderOrFail($orderNumber);

        $outcome = DB::transaction(function () use ($order, $pg, $issuance, $guard): PaymentOutcome {
            [$lockedOrder, $lockedPayment] = $this->lockPair($order);
            if ($lockedPayment === null) {
                return new PaymentOutcome(false, 'payment_not_found', $this->orderRecord($lockedOrder, $pg));
            }
            $this->assertWritable($lockedPayment, $pg);

            if ($guard !== null) {
                $reason = $guard($this->orderRecord($lockedOrder, $pg), PaymentRecord::fromModel($lockedPayment, $lockedOrder->order_number));
                if (is_string($reason)) {
                    return new PaymentOutcome(false, $reason, $this->orderRecord($lockedOrder, $pg));
                }
            }

            $attributes = $issuance->toAttributes($pg);
            if ($issuance->writeNulls) {
                // 결제 행 속성에 그대로 넣고 저장 — null 도 기록한다.
                $this->gateway->forceSavePayment($lockedPayment, $attributes);
            } else {
                // 관계 빌더 갱신과 같은 경로(모델 이벤트 없음, null 키 제외)
                $this->gateway->updatePaymentColumns((int) $lockedPayment->id, $attributes);
            }

            if ($guard !== null && $issuance->clearCart) {
                $this->processing()->onVbankAccountIssued($lockedOrder);
            }

            return new PaymentOutcome(true, null, null);
        });

        if (! $outcome->applied) {
            return $outcome;
        }

        if ($guard === null && $issuance->clearCart) {
            // 커밋 뒤 장바구니 정리 — 발급 저장 뒤 별도로 부르던 현행 순서
            $this->processing()->onVbankAccountIssued($order);
        }

        return new PaymentOutcome(true, null, $this->orderRecord($this->orderOrFail($orderNumber), $pg));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  Closure  $guard  잠금 안 판정
     * @param  Closure  $meta  새 meta 계산
     * @return bool 바꿨으면 true
     */
    public function expireAwaitingDeposit(string $orderNumber, string $pg, Closure $guard, Closure $meta): bool
    {
        $order = $this->orderOrFail($orderNumber);

        return DB::transaction(function () use ($order, $pg, $guard, $meta): bool {
            [$lockedOrder, $payment] = $this->lockPair($order);
            if ($payment === null) {
                return false;
            }
            $this->assertWritable($payment, $pg);

            if (! $guard(PaymentRecord::fromModel($payment, $lockedOrder->order_number))) {
                return false;
            }

            $this->gateway->forceSavePayment($payment, [
                'payment_status' => PaymentStatusEnum::EXPIRED,
                'payment_meta' => $meta(is_array($payment->payment_meta) ? $payment->payment_meta : []),
            ]);

            return true;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  float  $amount  입금액
     * @param  string|null  $transactionId  거래번호
     * @return bool 새로 기록했으면 true
     */
    public function recordDepositAfterCancel(string $orderNumber, string $pg, float $amount, ?string $transactionId = null): bool
    {
        $order = $this->orderOrFail($orderNumber);
        if ($order->payment !== null) {
            $this->assertWritable($order->payment, $pg);
        }

        // 기록기가 자기 트랜잭션에서 결제 행만 잠그고 훅을 발화한다 — 바깥 트랜잭션으로 감싸면 훅 시점이 바뀌므로 그대로 위임한다.
        return app(DepositAfterCancelRecorder::class)->record($order, $amount, $pg, $transactionId);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $reason  취소 사유
     * @param  int|null  $cancelledBy  처리자 회원 id
     * @return OrderRecord 처리 뒤 주문
     */
    public function cancelOrderForVbankRefund(string $orderNumber, string $pg, string $reason, ?int $cancelledBy = null): OrderRecord
    {
        $order = $this->orderOrFail($orderNumber);
        if ($order->payment !== null) {
            $this->assertWritable($order->payment, $pg);
        }

        $result = app(OrderCancellationService::class)->cancelOrder(
            $order->fresh(['options', 'payment', 'shippings']) ?? $order,
            $reason,
            null,
            $cancelledBy,
            false,
        );

        return $this->orderRecord($result->order, $pg);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  RetryRestoreRequest  $request  복구 요청
     * @return RetryRestoreResult 결과
     */
    public function restoreForRetry(string $orderNumber, string $pg, RetryRestoreRequest $request): RetryRestoreResult
    {
        if ($request->metaPrefix !== $pg) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.retry_prefix_mismatch', ['prefix' => $request->metaPrefix, 'pg' => $pg]));
        }

        $order = $this->orderOrFail($orderNumber);
        $processing = $this->processing();

        [$result, $restored] = DB::transaction(function () use ($order, $pg, $request, $processing): array {
            [$lockedOrder, $payment] = $this->lockPair($order);
            if ($payment === null) {
                return [new RetryRestoreResult(false, 'payment_not_found', $this->orderRecord($lockedOrder, $pg)), null];
            }
            if ($payment->pg_provider !== $pg) {
                throw ProviderMismatch::between($payment->pg_provider, $pg);
            }

            $blocker = $processing->retryRestoreBlocker($lockedOrder, $payment);
            if ($blocker !== null) {
                throw InvalidPaymentTransition::because($lockedOrder->order_number, $blocker);
            }

            $restorable = $lockedOrder->order_status === OrderStatusEnum::CANCELLED
                && in_array($payment->payment_status, [PaymentStatusEnum::FAILED, PaymentStatusEnum::CANCELLED], true);
            if (! $restorable) {
                return [new RetryRestoreResult(false, 'not_restorable_state', $this->orderRecord($lockedOrder, $pg)), null];
            }

            if ($request->expectedAmount !== null) {
                try {
                    $charge = $this->resolveChargeAmount($lockedOrder);
                } catch (ChargeNotResolvable) {
                    return [new RetryRestoreResult(false, 'charge_not_resolvable', $this->orderRecord($lockedOrder, $pg)), null];
                }
                if ($charge->minorUnitAmount !== $request->expectedAmount) {
                    return [new RetryRestoreResult(false, 'amount_mismatch', $this->orderRecord($lockedOrder, $pg)), null];
                }
            }

            $restored = $processing->restorePaymentForRetry(
                $lockedOrder,
                $payment,
                $request->metaPrefix,
                $request->clearTransaction,
                $request->recordFailureStage,
            );

            return [new RetryRestoreResult(true, null, null, $restored['retry_count']), $restored];
        });

        if ($restored === null) {
            return $result;
        }

        $processing->firePaymentRetryRestored($order, $pg, $restored);

        return new RetryRestoreResult(true, null, $this->orderRecord($this->orderOrFail($orderNumber), $pg), $result->retryCount);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  array<string, mixed>  $attributes  컬럼 => 값
     * @param  array<string, mixed>  $metaPatch  병합할 meta
     * @return PaymentRecord 처리 뒤 결제 행
     */
    public function recordPgAttributes(string $orderNumber, string $pg, array $attributes, array $metaPatch = []): PaymentRecord
    {
        foreach (array_keys($attributes) as $column) {
            if (! $this->isAllowedPgAttribute((string) $column)) {
                throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.pg_attribute_not_allowed', ['column' => $column]));
            }
        }

        $order = $this->orderOrFail($orderNumber);

        return DB::transaction(function () use ($order, $orderNumber, $pg, $attributes, $metaPatch): PaymentRecord {
            [$lockedOrder, $payment] = $this->lockPair($order);
            if ($payment === null) {
                throw new PaymentNotFound("Payment not found: {$orderNumber}");
            }
            $this->assertWritable($payment, $pg);

            $updates = $attributes;
            if ($metaPatch !== []) {
                $updates['payment_meta'] = array_merge(is_array($payment->payment_meta) ? $payment->payment_meta : [], $metaPatch);
            }
            if ($updates !== []) {
                $this->gateway->updatePayment($payment, $updates);
            }

            return PaymentRecord::fromModel($payment, $lockedOrder->order_number);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  array<string, mixed>  $patch  병합할 값
     * @param  MetaEncoding  $encoding  저장 인코딩
     * @return array<string, mixed> 저장된 meta
     */
    public function mergePaymentMeta(string $orderNumber, string $pg, array $patch, MetaEncoding $encoding = MetaEncoding::Eloquent): array
    {
        return $this->mutatePaymentMetaWithLock(
            $orderNumber,
            $pg,
            fn (array $meta): array => array_merge($meta, $patch),
            $encoding,
        ) ?? [];
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  Closure  $mutator  meta 변형
     * @param  MetaEncoding  $encoding  저장 인코딩
     * @return array<string, mixed>|null 저장된 meta
     */
    public function mutatePaymentMetaWithLock(string $orderNumber, string $pg, Closure $mutator, MetaEncoding $encoding = MetaEncoding::Eloquent): ?array
    {
        $order = $this->orderOrFail($orderNumber);

        return DB::transaction(function () use ($order, $orderNumber, $pg, $mutator, $encoding): ?array {
            [$lockedOrder, $payment] = $this->lockPair($order);
            if ($payment === null) {
                throw new PaymentNotFound("Payment not found: {$orderNumber}");
            }
            $this->assertWritable($payment, $pg);

            $current = is_array($payment->payment_meta) ? $payment->payment_meta : [];
            $next = $mutator($current, PaymentRecord::fromModel($payment, $lockedOrder->order_number), $this->orderRecord($lockedOrder, $pg));
            if (! is_array($next)) {
                return null;
            }

            if ($encoding === MetaEncoding::UnescapedUnicode) {
                $this->gateway->writeUnescapedPaymentMeta((int) $payment->id, $next, true);
            } else {
                $this->gateway->updatePayment($payment, ['payment_meta' => $next]);
            }

            return $next;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  int  $orderId  주문 id
     * @param  string  $pg  결제사 id
     * @param  string  $section  meta 최상위 키
     * @param  array<int, string>  $keys  비울 하위 키
     * @return int 바꾼 결제 행 수
     */
    public function scrubPaymentMetaKeys(int $orderId, string $pg, string $section, array $keys): int
    {
        return DB::transaction(function () use ($orderId, $pg, $section, $keys): int {
            $updated = 0;

            foreach ($this->gateway->lockPaymentsForProvider($orderId, $pg) as $row) {
                $meta = is_array($row->payment_meta) ? $row->payment_meta : [];
                if (! isset($meta[$section]) || ! is_array($meta[$section])) {
                    continue;
                }

                $changed = false;
                foreach ($keys as $key) {
                    if (array_key_exists($key, $meta[$section]) && $meta[$section][$key] !== null) {
                        $meta[$section][$key] = null;
                        $changed = true;
                    }
                }
                if (! $changed) {
                    continue;
                }

                // 수정 시각을 건드리지 않는다 — 익명화는 결제 기록의 변경이 아니다.
                $this->gateway->writeUnescapedPaymentMeta((int) $row->id, $meta, false);
                $updated++;
            }

            return $updated;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $orderNumber  주문번호
     * @param  string  $pg  결제사 id
     * @param  string  $namespace  meta 키
     * @param  Closure  $mutator  변형
     * @return array<string, mixed>|null 저장된 값
     */
    public function mutateOrderMetaWithLock(string $orderNumber, string $pg, string $namespace, Closure $mutator): ?array
    {
        $this->assertNamespace($pg, $namespace);

        return DB::transaction(function () use ($orderNumber, $namespace, $mutator): ?array {
            $order = $this->gateway->lockOrderMetaByNumber($orderNumber);
            if ($order === null) {
                return null;
            }

            $meta = is_array($order->order_meta) ? $order->order_meta : [];
            $existing = isset($meta[$namespace]) && is_array($meta[$namespace]) ? $meta[$namespace] : null;
            $next = $mutator($existing);
            if (! is_array($next)) {
                return null;
            }

            $meta[$namespace] = $next;
            $this->gateway->saveOrderMeta($order, $meta);

            return $next;
        });
    }

    // ───────────────────────────────────────────────
    // 내부 도우미
    // ───────────────────────────────────────────────

    /**
     * 주문 처리 서비스를 지금 컨테이너에서 해석합니다.
     *
     * @return OrderProcessingService 서비스
     */
    private function processing(): OrderProcessingService
    {
        return app(OrderProcessingService::class);
    }

    /**
     * 주문번호로 주문을 찾고 없으면 예외를 던집니다.
     *
     * @param  string  $orderNumber  주문번호
     * @return Order 주문
     *
     * @throws PaymentNotFound 주문번호로 주문을 찾지 못했을 때
     */
    private function orderOrFail(string $orderNumber): Order
    {
        return $this->processing()->findByOrderNumber($orderNumber) ?? throw PaymentNotFound::forOrder($orderNumber);
    }

    /**
     * 주문 → 결제 행 순으로 잠그고 잠긴 결제 행을 주문 관계에 붙입니다.
     *
     * @param  Order  $order  주문
     * @return array{0: Order, 1: OrderPayment|null} 잠긴 주문과 결제 행
     */
    private function lockPair(Order $order): array
    {
        $lockedOrder = $this->orders->findForUpdate((int) $order->id) ?? $order;
        $payment = $this->payments->findByOrderIdForUpdate((int) $order->id);
        $lockedOrder->setRelation('payment', $payment);

        return [$lockedOrder, $payment];
    }

    /**
     * 결제 행 실패 기록을 잠금 아래 씁니다(결제 행이 없으면 아무것도 하지 않음).
     *
     * @param  Order  $order  주문
     * @param  string  $pg  결제사 id
     * @param  PaymentFailure  $failure  실패 기록
     * @return void 반환값 없음
     *
     * @throws InvalidArgumentException 실패 상태가 FAILED, CANCELLED 가 아닐 때
     * @throws ProviderMismatch 결제(발급) 뒤 행이 다른 결제사 것일 때
     */
    private function writeFailure(Order $order, string $pg, PaymentFailure $failure): void
    {
        if (! in_array($failure->status, [PaymentStatusEnum::FAILED, PaymentStatusEnum::CANCELLED], true)) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.failure_status_invalid'));
        }

        DB::transaction(function () use ($order, $pg, $failure): void {
            [, $payment] = $this->lockPair($order);
            if ($payment === null) {
                return;
            }
            $this->assertWritable($payment, $pg);

            $updates = [
                'payment_status' => $failure->status->value,
                'payment_meta' => ($failure->meta)(is_array($payment->payment_meta) ? $payment->payment_meta : []),
            ];
            if ($failure->takeOverProvider) {
                $updates = ['pg_provider' => $pg] + $updates;
            }

            $this->gateway->updatePayment($payment, $updates);
        });
    }

    /**
     * 결제 행을 호출 결제사가 쓸 수 있는지 확인합니다.
     *
     * @param  OrderPayment  $payment  결제 행
     * @param  string  $pg  결제사 id
     * @return void 반환값 없음
     *
     * @throws ProviderMismatch 결제(발급) 뒤 행이 다른 결제사 것일 때
     */
    private function assertWritable(OrderPayment $payment, string $pg): void
    {
        if ($payment->pg_provider === $pg || $this->isClaimable($payment)) {
            return;
        }

        // 결제사가 식별자를 바꾸기 전에 남긴 행(예: 옛 `nicepay`)은 그 결제사 행이다 — 등록 항목의 legacy_ids 로 선언한다
        if (is_string($payment->pg_provider) && in_array($payment->pg_provider, $this->legacyProviderIds($pg), true)) {
            return;
        }

        throw ProviderMismatch::between($payment->pg_provider, $pg);
    }

    /**
     * 결제사가 등록 항목(`sirsoft-ecommerce.payment.registered_pg_providers`)의 `legacy_ids` 로 선언한 옛 식별자를 돌려줍니다.
     *
     * @param  string  $pg  결제사 id
     * @return array<int, string> 옛 식별자 목록
     */
    private function legacyProviderIds(string $pg): array
    {
        // 보관하지 않는다 — 이 구현은 singleton 이라 큐 워커에서 플러그인을 켜고 끈 뒤에도 옛 목록이 남는다. 쓰기 판정에서만 부른다.
        foreach ((array) HookManager::applyFilters('sirsoft-ecommerce.payment.registered_pg_providers', []) as $provider) {
            if (is_array($provider) && ($provider['id'] ?? null) === $pg && is_array($provider['legacy_ids'] ?? null)) {
                return array_values(array_filter($provider['legacy_ids'], 'is_string'));
            }
        }

        return [];
    }

    /**
     * 결제 전 행인지(다른 결제사가 넘겨받을 수 있는지) 판정합니다.
     *
     * @param  OrderPayment  $payment  결제 행
     * @return bool 결제 전이면 true
     */
    private function isClaimable(OrderPayment $payment): bool
    {
        return in_array($payment->payment_status, self::CLAIMABLE_STATUSES, true)
            && $payment->paid_at === null
            && ($payment->transaction_id === null || $payment->transaction_id === '')
            && $payment->vbank_issued_at === null;
    }

    /**
     * 주문번호 단건 조회에서 결제 행을 보여 줄지 판정합니다(자기 행 또는 결제 전 행).
     *
     * @param  OrderPayment  $payment  결제 행
     * @param  array<int, string>  $pgs  결제사 id 목록
     * @return bool 보이면 true
     */
    private function isVisibleForOrderLookup(OrderPayment $payment, array $pgs): bool
    {
        return in_array($payment->pg_provider, $pgs, true) || $this->isClaimable($payment);
    }

    /**
     * 주문 Model 을 DTO 로 바꿉니다(결제 행은 범위 판정 뒤 싣는다).
     *
     * @param  Order  $order  주문
     * @param  string|array<int, string>  $pg  결제사 id
     * @return OrderRecord DTO
     */
    private function orderRecord(Order $order, string|array $pg): OrderRecord
    {
        $payment = $order->payment;
        $record = $payment !== null && $this->isVisibleForOrderLookup($payment, $this->pgList($pg))
            ? PaymentRecord::fromModel($payment, (string) $order->order_number)
            : null;

        // 배송지와 주문 상품 활성 여부는 여기서 읽어 넘긴다 — DTO 는 조회하지 않는다. 저장하지 않은 Model 은 읽지 않는다.
        $address = $order->relationLoaded('shippingAddress') || $order->exists ? $order->shippingAddress : null;

        return OrderRecord::fromModel($order, $record, $this->hasActiveOptions($order), $address);
    }

    /**
     * 주문에 취소되지 않은 주문 상품이 있는지 판정합니다(적재된 관계가 있으면 그것으로, 없으면 저장소 한 번).
     *
     * @param  Order  $order  주문
     * @return bool 있으면 true
     */
    private function hasActiveOptions(Order $order): bool
    {
        if ($order->relationLoaded('options')) {
            $cancelled = OrderStatusEnum::CANCELLED->value;

            return $order->options->contains(function ($option) use ($cancelled): bool {
                $status = $option->option_status;

                return ($status instanceof OrderStatusEnum ? $status->value : (string) $status) !== $cancelled;
            });
        }

        return $order->exists && $this->gateway->hasActiveOrderOptions((int) $order->id);
    }

    /**
     * 클레임 결제 건 Model 을 DTO 로 바꿉니다(부모 주문번호는 적재된 관계가 있으면 그것으로, 없으면 저장소 한 번).
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return ClaimPaymentRecord DTO
     */
    private function claimPaymentRecord(ClaimPayment $payment): ClaimPaymentRecord
    {
        if ($payment->relationLoaded('order')) {
            $orderNumber = $payment->order?->order_number;
        } else {
            $orderNumber = $payment->order_id === null ? null : $this->gateway->orderNumberById((int) $payment->order_id);
        }

        return ClaimPaymentRecord::fromModel($payment, $orderNumber === null ? null : (string) $orderNumber);
    }

    /**
     * 결제사 id 인자를 목록으로 정리합니다.
     *
     * @param  string|array<int, string>  $pg  결제사 id
     * @return array<int, string> 목록
     *
     * @throws InvalidArgumentException 결제사 id 가 비었을 때
     */
    private function pgList(string|array $pg): array
    {
        $list = array_values(array_filter(array_map('strval', (array) $pg), fn (string $id) => $id !== ''));
        if ($list === []) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.provider_required'));
        }

        return $list;
    }

    /**
     * 필터를 저장소 조회 조건으로 바꿉니다.
     *
     * @param  PaymentFilters|null  $filters  필터
     * @return array<string, mixed> 조건
     */
    private function criteria(?PaymentFilters $filters): array
    {
        if ($filters === null) {
            return [];
        }

        return array_filter([
            'escrow' => $filters->escrow,
            'has_transaction_id' => $filters->hasTransactionId === true ? true : null,
            'non_empty_transaction_id' => $filters->nonEmptyTransactionId ? true : null,
            'status' => $filters->status?->value,
            'owner_user_id' => $filters->ownerUserId,
        ], fn ($value) => $value !== null);
    }

    /**
     * 저장소 조회 결과 결제 행을 DTO 로 바꿉니다.
     *
     * @param  OrderPayment|null  $payment  결제 행(joined_order_number 포함)
     * @return PaymentRecord|null DTO
     */
    private function record(?OrderPayment $payment): ?PaymentRecord
    {
        return $payment === null ? null : PaymentRecord::fromModel($payment, (string) $payment->joined_order_number);
    }

    /**
     * 저장소 조회 결과 목록을 DTO 목록으로 바꿉니다.
     *
     * @param  Collection<int, OrderPayment>  $payments  결제 행 목록
     * @return array<int, PaymentRecord> DTO 목록
     */
    private function records(Collection $payments): array
    {
        return $payments->map(fn (OrderPayment $payment) => $this->record($payment))->values()->all();
    }

    /**
     * 결제사 부가 정보 컬럼 허용 여부를 판정합니다.
     *
     * @param  string  $column  컬럼
     * @return bool 허용이면 true
     */
    private function isAllowedPgAttribute(string $column): bool
    {
        if (in_array($column, self::ALLOWED_PG_ATTRIBUTES, true)) {
            return true;
        }

        foreach (self::ALLOWED_PG_ATTRIBUTE_PREFIXES as $prefix) {
            if (str_starts_with($column, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 주문 meta namespace 가 호출 결제사 접두로 시작하는지 확인합니다.
     *
     * @param  string  $pg  결제사 id
     * @param  string  $namespace  meta 키
     * @return void 반환값 없음
     *
     * @throws InvalidArgumentException namespace 가 `{결제사 id}_` 로 시작하지 않을 때
     */
    private function assertNamespace(string $pg, string $namespace): void
    {
        if ($pg === '' || ! str_starts_with($namespace, $pg.'_')) {
            throw new InvalidArgumentException(__('sirsoft-ecommerce::exceptions.payment_contract.order_meta_namespace_invalid', ['namespace' => $namespace, 'prefix' => $pg.'_']));
        }
    }
}
