<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\Concerns\MasksSensitiveResponse;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;

/**
 * 클레임 추가결제 서비스 (S5)
 *
 * 교환·반품 신청서의 청구액(클레임 배송비 + 옵션 차액)을 원 주문과 분리된 결제 건(`ClaimPayment`,
 * CP 채번)으로 수납하고, 철회·반려·재발송지 수정(D14)에 따른 환급을 수행한다. `payment_pending`
 * 축(§4-2 전이표)은 이 서비스가 소유한다 — 그 상태로의 **진입**은 결제 건 생성과 한 몸이고
 * **탈출**은 결제 확정이라, 신청서 서비스(교환·반품)는 게이트만 열어 두고 이 서비스가 옮긴다.
 *
 * ## 결제 시점 2종 (§4-5)
 *
 *  - `pay_on_request`: 접수 직후 `initiateForRequest()` 가 결제 대기 건을 만들고 신청서를 결제대기로
 *    옮긴다. 결제가 확정되면 `claim.auto_approve_after_payment` 가 참일 때만 자동 승인하고, 아니면
 *    결제대기에 머문 채 운영자 승인을 기다린다(전이표 "수동 approved").
 *  - `pay_on_approval`: 운영자 승인 시도 시 `requestPaymentOnApproval()` 이 결제 대기 건을 만들고
 *    결제대기로 옮긴다. 결제가 확정되면 운영자가 이미 승인한 것이므로 항상 승인으로 옮긴다.
 *  - `deduct_from_refund`: 결제 건을 만들지 않는다 — 환불 실행 시점에 상계된다.
 *
 * ## PG 연동 계약 (provider-agnostic)
 *
 * 결제창 진입은 주문 결제와 같은 응답 계약(`requires_pg_payment` / `pg_payment_handler` /
 * `pg_payment_data`)을 쓰되 `pg_payment_data.order_number` 에 **결제번호(CP)** 를 싣는다. 승인·환급은
 * 주문 결제 훅을 재사용할 수 없다 — 결제 플러그인의 콜백 컨트롤러는 주문번호로 `Order` 를 조회해
 * `OrderProcessingService::completePayment(Order)` 를 부르고, 환불 리스너(`payment.refund`)는
 * `Order`·`OrderPayment` 를 전제하므로 CP 건은 그 경로에서 `order_not_found` 로 끝난다. 그래서
 * 다음 두 필터 훅을 결제 플러그인이 구현해야 클레임 결제가 PG 를 관통한다:
 *
 *  - `sirsoft-ecommerce.claim_payment.approve`
 *      인자: (array $default, ClaimPayment $payment, array $callback)
 *      반환: {success: bool, transaction_id: ?string, paid_amount: ?int(최소 화폐단위), raw_response: ?array,
 *             error_code: ?string, error_message: ?string}
 *  - `sirsoft-ecommerce.claim_payment.refund`
 *      인자: (array $default, ClaimPayment $payment, float $amount(결제 통화), ?string $reason, string $idempotencyKey)
 *      반환: {success: bool, transaction_id: ?string, error_code: ?string, error_message: ?string}
 *
 * 구현한 플러그인이 없으면 승인은 `claim_payment_provider_unavailable` 로 거절된다 — 결제창은 열렸는데
 * 서버가 확정하지 못하는 상태를 조용히 통과시키지 않는다. 마일리지 결제는 모듈 안에서 끝난다.
 *
 * 멱등 축: `payment_number` / `transaction_id` UNIQUE, 마일리지는 `claim_payment_id`, PG 환급은
 * `g7-claimpay-{id}-{누적환급액}-{이번환급액}` 멱등키. 승인의 이중 **청구** 방지는 모듈이 아니라
 * 결제 플러그인 몫이다(같은 `payment_number` 재승인을 PG 쪽에서 한 번으로 접는다 — 계약 문서 참조).
 */
class ClaimPaymentService
{
    use MasksSensitiveResponse;

    /** 결제 대기 건 취소 사유 — 신청서가 종결되어 함께 정리 */
    public const CANCEL_REASON_CLAIM_FINALIZED = 'claim_finalized';

    /** 결제 대기 건 취소 사유 — 청구액 재산정으로 대기 건이 불필요해짐 */
    public const CANCEL_REASON_CHARGE_SETTLED = 'charge_settled';

    /** 결제 대기 건 취소 사유 — 보존 기간이 지난 고아 건을 배치가 정리 */
    public const CANCEL_REASON_ORPHAN_REAPED = 'orphan_reaped';

    /**
     * @param  ClaimPaymentRepositoryInterface  $paymentRepository  클레임 결제 Repository
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 신청서 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 신청서 Repository
     * @param  OrderExchangeService  $exchangeService  교환 서비스 (상태 전이)
     * @param  OrderReturnService  $returnService  반품 서비스 (상태 전이)
     * @param  UserMileageService  $mileageService  마일리지 서비스
     * @param  SequenceService  $sequenceService  채번 서비스
     * @param  EcommerceSettingsService  $settingsService  설정 서비스
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  OrderProcessingService  $orderProcessingService  주문 처리 서비스 (PG provider 판정)
     */
    public function __construct(
        protected ClaimPaymentRepositoryInterface $paymentRepository,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderExchangeService $exchangeService,
        protected OrderReturnService $returnService,
        protected UserMileageService $mileageService,
        protected SequenceService $sequenceService,
        protected EcommerceSettingsService $settingsService,
        protected CurrencyConversionService $currencyService,
        protected OrderProcessingService $orderProcessingService,
    ) {}

    // ───────────────────────────────────────────────
    // 결제 대기 건 생성 (payment_pending 진입)
    // ───────────────────────────────────────────────

    /**
     * 접수 직후 신청 시점 결제(`pay_on_request`)의 결제 대기 건을 만들고 신청서를 결제대기로 옮깁니다.
     *
     * 청구액이 없거나 신청 시점 결제가 아니면 아무것도 하지 않는다(null). 접수는 이미 커밋된 뒤이므로
     * 결제 건 생성과 전이는 별도 트랜잭션이며, 실패하면 신청서는 `REQUESTED` 로 남는다.
     *
     * @param  OrderExchange|OrderReturn  $claim  접수된 신청서
     * @param  string|null  $paymentMethod  결제 수단 (null 이면 허용 목록의 첫 수단)
     * @return ClaimPayment|null 결제 대기 건 (결제 대상이 아니면 null)
     *
     * @throws OrderClaimException 결제 수단 불허
     */
    public function initiateForRequest(OrderExchange|OrderReturn $claim, ?string $paymentMethod = null): ?ClaimPayment
    {
        if ($claim->charge_method !== ClaimChargeMethodEnum::PAY_ON_REQUEST || ! $claim->requiresAdditionalPayment()) {
            return null;
        }

        if ($this->claimStatus($claim) !== 'requested') {
            return null;
        }

        $requestedBy = $claim->requested_by === null ? null : (int) $claim->requested_by;
        $method = $paymentMethod === null || $paymentMethod === ''
            ? $this->resolveDefaultPaymentMethodFor($requestedBy)
            : $this->resolvePaymentMethod($paymentMethod);
        $this->assertPaymentMethodAvailableToRequester($method, $requestedBy);

        // 접수 직후 결제 진입도 같은 순서를 지킨다 — 통화 기준을 못 세우는 주문에서 결제행을 먼저
        // 만들면, 뒤이은 진입 거절이 결제대기로 옮겨진 신청서와 낼 수 없는 결제행을 남긴다.
        if ($method !== PaymentMethodEnum::POINT->value) {
            $claim->loadMissing('order');
            $this->assertClaimPaymentCurrencyResolvable($claim->order);
        }

        $payment = $this->createPendingPayment($claim, $method, (float) $claim->total_charge_amount);

        $this->transitionClaim($claim, 'payment_pending');

        return $payment;
    }

    /**
     * 운영자 승인 시도 시 승인 시점 결제(`pay_on_approval`)의 결제 대기 건을 만들고 신청서를 결제대기로 옮깁니다.
     *
     * 이미 실수납된 금액이 청구액을 채우면 결제 건을 만들지 않고 null 을 돌려준다 — 호출부(관리자 승인
     * 경로)는 그때 승인 전이를 이어가면 된다. 결제대기로 옮긴 뒤에는 결제 확정이 승인으로 옮긴다.
     *
     * @param  OrderExchange|OrderReturn  $claim  승인하려는 신청서
     * @param  int|null  $processedBy  처리 관리자 ID
     * @return ClaimPayment|null 결제 대기 건 (결제할 것이 없으면 null)
     *
     * @throws OrderClaimException 전이 불가
     */
    public function requestPaymentOnApproval(OrderExchange|OrderReturn $claim, ?int $processedBy = null): ?ClaimPayment
    {
        if ($claim->charge_method !== ClaimChargeMethodEnum::PAY_ON_APPROVAL || ! $claim->requiresAdditionalPayment()) {
            return null;
        }

        $outstanding = $this->outstandingAmount($claim);

        if ($outstanding <= 0) {
            return null;
        }

        // 결제대기로 갈 수 있는 자리인지 **결제행을 만들기 전에** 본다.
        //
        // 이 메서드는 운영자가 [승인] 을 누른 자리에서 불린다. 승인의 출발점은 접수만이
        // 아니다 — 보류(`on_hold`)도 승인으로 갈 수 있다(전이표가 그렇게 정한다). 그런데
        // 보류에서 결제대기로는 갈 수 없어서, 아래 전이가 거절을 던진다. 그때 결제행은 이미
        // 커밋된 뒤라 **신청서는 보류에 얼어붙고 낼 수 없는 결제행만 남는다**: 다시 승인을
        // 눌러도 같은 자리에서 같은 거절이 나오므로 운영자에게는 반려·철회밖에 남지 않는다
        // (실측 EX20260915-0853360266 — 결제행 CP20260915-1004285647 이 그렇게 남았다).
        //
        // 그래서 결제대기를 **거치지 않는다**. 이미 청구액을 받아야 하는 건이니 결제행은
        // 만들되 신청서는 제자리에 두고, 호출부가 결제 요청만 알리게 한다. 결제가 확정되면
        // `confirm()` 이 그 자리에서 승인으로 잇는다 — 접수에서 온 건과 같은 종착이다.
        $canEnterPaymentPending = $this->claimStatus($claim) === 'payment_pending'
            || $this->canTransitionTo($claim, 'payment_pending');

        // 신청 시점 결제·차액 정산과 같은 게이트를 탄다 — 허용 목록의 첫 수단을 그대로 쓰면
        // 마일리지를 앞에 둔 상점에서 비회원 신청서의 결제행이 `point` 로 만들어져, 그 건은
        // 수단을 명시하지 않는 한 다시 열리지 않는다. 결제행을 만드는 세 경로가 같은 기준을 써야 한다.
        $payment = $this->paymentRepository->findPendingByPayable($claim->claimType()->value, $claim->id);

        if ($payment === null) {
            $method = $this->resolveDefaultPaymentMethodFor($claim->requested_by === null ? null : (int) $claim->requested_by);

            // 통화 기준도 같은 이유로 결제행 앞에서 본다 — 미루면 승인 시도가 결제대기로 옮긴
            // 신청서와 낼 수 없는 결제행을 남긴다(`initiateForRequest()`·`settleChargeDifference()` 동형).
            if ($method !== PaymentMethodEnum::POINT->value) {
                $claim->loadMissing('order');
                $this->assertClaimPaymentCurrencyResolvable($claim->order);
            }

            $payment = $this->createPendingPayment($claim, $method, $outstanding);
        }

        if ($canEnterPaymentPending && $this->claimStatus($claim) !== 'payment_pending') {
            $this->transitionClaim($claim, 'payment_pending', ['processed_by' => $processedBy]);
        }

        return $payment;
    }

    /**
     * 신청서가 지금 자리에서 목표 상태로 갈 수 있는지 판정합니다.
     *
     * 유형마다 상태 enum 이 다르므로 전이표도 각자 들고 있다 — 여기서는 판정만 빌려 온다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $target  목표 상태 값
     * @return bool 전이 허용 여부
     */
    protected function canTransitionTo(OrderExchange|OrderReturn $claim, string $target): bool
    {
        return $claim instanceof OrderExchange
            ? $claim->exchange_status->canTransitionTo(ExchangeStatusEnum::from($target))
            : $claim->return_status->canTransitionTo(ReturnStatusEnum::from($target));
    }

    // ───────────────────────────────────────────────
    // 결제 실행 (유저)
    // ───────────────────────────────────────────────

    /**
     * 결제 대기 건의 결제를 시작합니다. (결제 수단 확정 → PG 결제창 파라미터 또는 마일리지 즉시 결제)
     *
     * @param  ClaimPayment  $payment  결제 대기 건
     * @param  string|null  $paymentMethod  결제 수단 (null 이면 결제 건에 기록된 수단)
     * @param  int|null  $userId  결제 주체 회원 ID (비회원이면 null — 마일리지 결제 불가)
     * @return array{payment: ClaimPayment, requires_pg_payment: bool, pg_provider: ?string, pg_payment_handler: ?string, pg_payment_data: ?array} 결제 진입 결과
     *
     * @throws OrderClaimException 결제 대기 아님 / 수단 불허 / 마일리지 부족
     */
    public function pay(ClaimPayment $payment, ?string $paymentMethod = null, ?int $userId = null): array
    {
        if (! $payment->isPending()) {
            throw OrderClaimException::paymentNotPending();
        }

        $method = $this->resolvePaymentMethod($paymentMethod ?? $payment->payment_method);

        // 수단 확정 가드는 결제행을 고치기 **전에** 전부 통과해야 한다 — 거절된 요청이 결제행을
        // 바꿔 놓으면 사용자는 422 를 받는데 행은 마일리지(`amount=0`·`pg_provider=null`)로 남아,
        // 그 상태의 상세 응답이 실제로 낼 수 없는 수단을 안내한다.
        $this->assertPaymentMethodAvailableToRequester($method, $userId);

        // 통화 기준도 같은 이유로 결제행 앞에서 본다 — 아래에서 파라미터를 만들지 못해 거절하면
        // 수단만 바뀐 행이 남는다. 마일리지는 PG 진입 파라미터를 쓰지 않으므로 대상이 아니다.
        if ($method !== PaymentMethodEnum::POINT->value) {
            $payment->loadMissing('order');
            $this->assertClaimPaymentCurrencyResolvable($payment->order);
        }

        if ($method !== $payment->payment_method) {
            $payment = $this->paymentRepository->update($payment, [
                'payment_method' => $method,
                'pg_provider' => $this->resolvePgProvider($method),
                'amount' => $method === PaymentMethodEnum::POINT->value ? 0 : $payment->getTotalAmount(),
                'points_amount' => $method === PaymentMethodEnum::POINT->value ? $payment->getTotalAmount() : 0,
            ]);
        }

        if ($method === PaymentMethodEnum::POINT->value) {
            return [
                'payment' => $this->payWithMileage($payment, $userId),
                'requires_pg_payment' => false,
                'pg_provider' => null,
                'pg_payment_handler' => null,
                'pg_payment_data' => null,
            ];
        }

        $payload = $this->buildPaymentPayload($payment);

        // 결제 진입 액션은 파라미터를 만들지 못한 상태를 200 으로 돌려주지 않는다 — 화면은
        // 결제창을 열 수 없는데 오류도 없어 원인이 드러나지 않는다.
        if ($payload['requires_pg_payment'] && $payload['pg_payment_data'] === null) {
            throw OrderClaimException::paymentCurrencyUnavailable();
        }

        return ['payment' => $payment] + $payload;
    }

    /**
     * PG 결제창 진입 파라미터를 만듭니다. (주문 결제 응답 계약과 같은 4키)
     *
     * `pg_payment_data.order_number` 에 결제번호(CP)를 싣는다 — 결제 플러그인의 콜백이 이 값으로
     * 결제 건을 되찾는다. 금액은 주문 통화 스냅샷 환율로 결제 통화의 최소 화폐단위 정수로 환산한다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return array{requires_pg_payment: bool, pg_provider: ?string, pg_payment_handler: ?string, pg_payment_data: ?array} 결제 진입 파라미터
     */
    public function buildPaymentPayload(ClaimPayment $payment): array
    {
        $payment->loadMissing('order.shippingAddress');
        $order = $payment->order;
        $claim = $this->resolveClaim($payment);

        $provider = $payment->pg_provider ?: $this->resolvePgProvider($payment->payment_method);

        if ($payment->isPointPayment() || in_array($provider, [null, 'manual', 'internal', 'none'], true)) {
            return [
                'requires_pg_payment' => false,
                'pg_provider' => null,
                'pg_payment_handler' => null,
                'pg_payment_data' => null,
            ];
        }

        // 진입 금액은 승인 대조와 **같은 해석기**를 쓴다 — 스냅샷을 못 읽을 때 대조는 닫히는데
        // 진입만 현재 상점 설정으로 미끄러지면 두 값이 갈라진다(0 자리 통화가 2 자리로 읽혀
        // 100 배가 된 금액으로 결제창이 열리고, 승인 확정 뒤에 불일치로 거절된다).
        // 읽기 경로(신청서 상세)도 이 메서드를 부르므로 여기서는 예외를 던지지 않고 진입
        // 파라미터만 비운다 — 청구는 남아 있으므로 `requires_pg_payment` 는 참 그대로다.
        $charge = $this->resolvePaymentCharge($payment);

        if ($charge === null) {
            return [
                'requires_pg_payment' => true,
                'pg_provider' => "sirsoft-{$provider}",
                'pg_payment_handler' => $this->resolvePgPaymentHandler($provider),
                'pg_payment_data' => null,
            ];
        }

        $shippingAddress = $order?->shippingAddress;
        $claimNumber = $claim instanceof OrderExchange ? $claim->exchange_number : $claim?->return_number;

        return [
            'requires_pg_payment' => true,
            'pg_provider' => "sirsoft-{$provider}",
            'pg_payment_handler' => $this->resolvePgPaymentHandler($provider),
            'pg_payment_data' => [
                'order_number' => $payment->payment_number,
                'order_name' => __('sirsoft-ecommerce::messages.claims.payment_order_name', [
                    'type' => $claim?->claimType()->label() ?? '',
                    'number' => $claimNumber ?? '',
                ]),
                'amount' => $charge['minor_unit_amount'],
                'currency' => $charge['currency'],
                'customer_name' => $shippingAddress?->orderer_name,
                'customer_email' => $shippingAddress?->orderer_email,
                'customer_phone' => preg_replace('/[^0-9]/', '', $shippingAddress?->orderer_phone ?? ''),
                'customer_key' => $order?->user_id ? "user_{$order->user_id}" : null,
                'payment_method' => $payment->payment_method,
                'escrow_products' => [],
                // 결제 플러그인이 CP 건임을 판별하는 힌트 — 주문 결제와 같은 핸들러가 같은 콜백으로 돌아온다.
                'claim_payment_number' => $payment->payment_number,
                'claim_type' => $claim?->claimType()->value,
                'claim_number' => $claimNumber,
                // 사용자가 결제창을 스스로 닫았을 때 돌아갈 주소 — **서버가 만들어 준다.**
                //
                // 복귀 주소 계약(회원 상세 / 비회원 조회 화면 / 신청서를 못 읽을 때의 폴백)은
                // `ClaimPaymentRedirect` 가 SSoT 다. 결제 플러그인이 이 주소를 스스로 조립하면
                // 그 분기를 프런트에 복제하게 되고, 한쪽만 바뀌면 조용히 어긋난다 — 특히
                // 비회원은 조회 토큰을 전제할 수 없어 목적지가 갈린다. 의도된 중단은 실패가
                // 아니므로 `?claim_payment=cancelled` 다(`ClaimPaymentRedirect::cancelled()`).
                'claim_cancel_return_url' => ClaimPaymentRedirect::cancelled($payment),
            ],
        ];
    }

    /**
     * PG 콜백으로 결제를 승인·확정합니다. (`claim_payment.approve` 필터 훅 위임)
     *
     * 결제 건을 잠근 뒤 판정한다 — 같은 콜백이 두 번 도달해도 두 번째는 이미 결제 완료라 그대로 반환한다.
     * 승인 금액은 청구액의 결제 통화 환산값과 대조하고, 다르면 확정하지 않는다.
     *
     * @param  ClaimPayment  $payment  결제 대기 건
     * @param  array  $callback  PG 콜백 파라미터 (플러그인이 해석)
     * @return ClaimPayment 결제 완료 건
     *
     * @throws OrderClaimException 결제 대기 아님 / 연동 부재 / 승인 실패 / 금액 불일치
     */
    public function approvePgPayment(ClaimPayment $payment, array $callback): ClaimPayment
    {
        // 여기서 잡는 행 잠금은 최신 행을 읽기 위한 것이지 동시 승인을 직렬화하는 장치가 아니다 —
        // 트랜잭션이 곧바로 커밋되므로 아래 검사–확정 구간에는 잠금이 남지 않는다. 그 구간에는
        // 모듈 쪽 방어가 없다: `transaction_id` UNIQUE 는 서로 다른 행이 같은 거래를 갖는 것만
        // 막고(같은 행에 두 번 update 되는 것은 못 막으며, 필터가 null 을 돌려주면 NULL 중복이라
        // 아무것도 막지 않는다), 이 메서드는 클라이언트가 부르는 엔드포인트에서 호출되므로 동시
        // 승인 요청은 순차가 아니다. 이중 **청구** 방지는 결제 플러그인의 몫이다 — 계약
        // (docs/api/claim-payments.md "결제 플러그인 연동 계약")이 같은 `payment_number` 의 재승인을
        // PG 쪽에서 한 번으로 접는 멱등을 요구한다. 잠금을 승인 확정까지 늘리지 않는 이유는
        // 외부 PG 호출이 트랜잭션 안에 들어가 응답을 기다리는 동안 행이 잠기기 때문이다.
        $locked = DB::transaction(function () use ($payment) {
            return $this->paymentRepository->findByPaymentNumberForUpdate($payment->payment_number) ?? $payment;
        });

        if ($locked->isPaid()) {
            return $locked;
        }

        if (! $locked->isPending()) {
            throw OrderClaimException::paymentNotPending();
        }

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.approve',
            ['success' => false, 'handled' => false, 'transaction_id' => null, 'paid_amount' => null, 'raw_response' => null, 'error_code' => null, 'error_message' => null],
            $locked,
            $callback,
        );

        if (empty($result['handled']) && empty($result['success'])) {
            throw OrderClaimException::paymentProviderUnavailable();
        }

        if (empty($result['success'])) {
            $this->paymentRepository->update($locked, [
                'pg_error_code' => $result['error_code'] ?? null,
                'pg_error_message' => $result['error_message'] ?? null,
                'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
            ]);

            Log::warning('[claim.payment] PG 승인 실패', [
                'payment_number' => $locked->payment_number,
                'error_code' => $result['error_code'] ?? null,
                'error_message' => $result['error_message'] ?? null,
            ]);

            // 응답에는 PG 가 준 **코드**만 싣는다. 원문 메시지는 상점 MID·PG 내부 상태 같은 것을 담고
            // 이 엔드포인트는 비회원(비인증)에게도 열려 있으므로, 원문은 바로 위 로그로만 남긴다.
            throw OrderClaimException::paymentFailed((string) ($result['error_code'] ?? 'unknown'));
        }

        if (isset($result['paid_amount']) && ! $this->paidAmountMatches($locked, (int) $result['paid_amount'])) {
            $this->paymentRepository->update($locked, [
                'pg_error_code' => 'AMOUNT_MISMATCH',
                // 금액은 에러 사유가 아니다 — 대사에 쓴 값은 raw_response 와 아래 로그에 남기고
                // 이 컬럼에는 사유만 둔다(컬럼 의미를 흐리면 운영자 화면이 금액을 사유로 읽는다).
                'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
            ]);

            Log::warning('[claim.payment] PG 승인 금액 불일치', [
                'payment_number' => $locked->payment_number,
                'paid_amount' => $result['paid_amount'] ?? null,
                'expected_minor_unit_amount' => $this->expectedMinorUnitAmount($locked),
            ]);

            throw OrderClaimException::paymentAmountMismatch();
        }

        $paid = $this->paymentRepository->update($locked, [
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'transaction_id' => $result['transaction_id'] ?? null,
            'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
            'pg_error_code' => null,
            'pg_error_message' => null,
            'paid_at' => Carbon::now(),
        ]);

        return $this->confirmPaid($paid);
    }

    /**
     * 마일리지로 결제합니다. (통화별 FIFO 차감, claim_payment_id 멱등)
     *
     * @param  ClaimPayment  $payment  결제 대기 건
     * @param  int  $userId  결제 주체 회원 ID
     * @return ClaimPayment 결제 완료 건
     *
     * @throws OrderClaimException 결제 대기 아님 / 마일리지 부족
     */
    public function payWithMileage(ClaimPayment $payment, int $userId): ClaimPayment
    {
        $paid = DB::transaction(function () use ($payment, $userId) {
            $locked = $this->paymentRepository->findByPaymentNumberForUpdate($payment->payment_number) ?? $payment;

            if ($locked->isPaid()) {
                return $locked;
            }

            if (! $locked->isPending()) {
                throw OrderClaimException::paymentNotPending();
            }

            $amount = (int) round($locked->getTotalAmount());

            // 마일리지 차감의 멱등 축은 거래 행의 `claim_payment_id` 다 — 같은 결제 건으로 두 번
            // 차감되면 그 컬럼이 중복 기록을 드러낸다. 환급측(restoreForClaimPayment)이 그 컬럼으로
            // 누적 상한을 계산하므로, 차감이 중복되면 상한도 함께 부풀어 되돌릴 수 없다.
            try {
                $this->mileageService->deductForClaimPayment($userId, $amount, $locked->currency_code, $locked);
            } catch (MileageValidationException $e) {
                throw OrderClaimException::paymentFailed($e->getMessage());
            }

            return $this->paymentRepository->update($locked, [
                'payment_method' => PaymentMethodEnum::POINT->value,
                'pg_provider' => null,
                'amount' => 0,
                'points_amount' => $amount,
                'payment_status' => ClaimPaymentStatusEnum::PAID,
                'paid_at' => Carbon::now(),
            ]);
        });

        return $this->confirmPaid($paid);
    }

    // ───────────────────────────────────────────────
    // 환급
    // ───────────────────────────────────────────────

    /**
     * 신청서의 결제 건을 전액 환급하고 결제 대기 건을 취소합니다. (철회·반려)
     *
     * 환급 실패는 예외로 올린다 — 신청서는 이미 종결됐으므로 호출부가 기록·재시도를 결정한다.
     *
     * @param  OrderExchange|OrderReturn  $claim  종결된 신청서
     * @param  string|null  $reason  환급 사유
     * @return array<int, ClaimPayment> 환급·취소된 결제 건 목록
     *
     * @throws OrderClaimException 환급 실패
     */
    public function refundPaymentsForClaim(OrderExchange|OrderReturn $claim, ?string $reason = null): array
    {
        $processed = [];

        foreach ($this->listForClaim($claim) as $payment) {
            if ($payment->isPending()) {
                $processed[] = $this->cancelPending($payment, $claim, self::CANCEL_REASON_CLAIM_FINALIZED);

                continue;
            }

            if ($payment->isRefundable()) {
                $processed[] = $this->refundPayment($payment, null, $reason);
            }
        }

        return $processed;
    }

    /**
     * 결제 건을 환급합니다. (금액을 주면 부분 환급, 없으면 잔액 전액)
     *
     * 부분 환급은 `refunded_amount` 누적으로, 전액 환급은 `payment_status=refunded` 로 기록한다.
     *
     * @param  ClaimPayment  $payment  결제 완료 건
     * @param  float|null  $amount  환급액 (null 이면 실수납 잔액 전액)
     * @param  string|null  $reason  환급 사유
     * @return ClaimPayment 환급 반영된 결제 건
     *
     * @throws OrderClaimException 환급 불가 / PG 환급 실패
     */
    public function refundPayment(ClaimPayment $payment, ?float $amount = null, ?string $reason = null): ClaimPayment
    {
        // 승인 경로와 같은 형태다 — 잠금은 최신 행을 읽는 용도이고, 중복 환급을 막는 축은
        // 누적 환급액(`refunded_amount`)으로 잔액을 다시 계산하는 것과 PG 멱등키
        // `g7-claimpay-{id}-{누적환급액}-{이번환급액}` 이다. 같은 금액을 두 번 보내면 PG 가 같은
        // 키로 받아 한 번만 처리하고, 실패 후 다른 금액으로 재시도하면 키가 바뀌어 새 요청으로
        // 처리된다. 잠금을 PG 호출까지 늘리지 않는 이유도 승인과 같다.
        $locked = DB::transaction(function () use ($payment) {
            return $this->paymentRepository->findByPaymentNumberForUpdate($payment->payment_number) ?? $payment;
        });

        $net = $locked->netPaidAmount();
        $refundAmount = $amount === null ? $net : min((float) $amount, $net);

        if (! $locked->isPaid() || $refundAmount <= 0) {
            return $locked;
        }

        // 마일리지 축의 멱등은 PG 멱등키가 아니라 원장 상한이 맡는다 — `restoreForClaimPayment()` 가
        // 이 결제 건으로 이미 환급된 합계를 세서 결제 시 차감한 `points_amount` 를 넘지 못하게 자른다.
        // 그래서 아래 행 갱신이 실패해 재시도가 들어와도 낸 것보다 더 돌려주는 일은 생기지 않는다.
        if ($locked->isPointPayment()) {
            $userId = $locked->order?->user_id ?? $this->resolveClaim($locked)?->requested_by;

            // 환급 대상을 못 찾으면 **환급하지 않은 채로 환급 완료를 기록하지 않는다** — 아래 갱신은
            // 조건 없이 `refunded_amount` 를 누적하고 상태를 옮기므로, 여기서 조용히 건너뛰면 낸 돈이
            // 사라진 채 장부만 정산된 것으로 남고 재시도조차 `isPaid()` 에 막힌다.
            if ($userId === null) {
                Log::error('[claim.payment] 마일리지 환급 대상을 해석하지 못해 환급을 중단합니다', [
                    'payment_number' => $locked->payment_number,
                    'payable_type' => $locked->payable_type,
                    'payable_id' => $locked->payable_id,
                ]);

                throw OrderClaimException::paymentRefundFailed('MILEAGE_OWNER_UNRESOLVED');
            }

            $this->mileageService->restoreForClaimPayment((int) $userId, $refundAmount, $locked->currency_code, $locked);
        } else {
            $this->executePgRefund($locked, $refundAmount, $reason);
        }

        $refundedTotal = (float) $locked->refunded_amount + $refundAmount;
        $fullyRefunded = $refundedTotal >= $locked->getTotalAmount() - 0.005;

        $updated = $this->paymentRepository->update($locked, [
            'refunded_amount' => $refundedTotal,
            'refunded_at' => Carbon::now(),
            'payment_status' => $fullyRefunded ? ClaimPaymentStatusEnum::REFUNDED : ClaimPaymentStatusEnum::PAID,
        ]);

        $claim = $this->resolveClaim($updated);

        if ($claim !== null) {
            HookManager::doAction(
                'sirsoft-ecommerce.claim.'.$claim->claimType()->value.'.payment_refunded',
                $claim,
                $updated,
                $refundAmount,
            );
        }

        return $updated;
    }

    /**
     * 청구액 변경(재발송지 수정 D14 / 사유 변경)에 따른 차액을 정산합니다.
     *
     *  - 청구액이 실수납액보다 크면: 결제 대기 건을 차액으로 갱신(없으면 생성)하고, 신청 시점 결제라면
     *    신청서를 결제대기로 옮긴다.
     *  - 청구액이 실수납액보다 작으면: 가장 최근 결제 건부터 차액을 부분 환급하고, 남은 결제 대기 건은 취소한다.
     *  - 같으면: 남은 결제 대기 건만 정리한다(청구액 0원이면 취소).
     *
     * 환불금 차감 방식은 결제 건이 없으므로 아무것도 하지 않는다.
     *
     * @param  OrderExchange|OrderReturn  $claim  청구액이 갱신된 신청서
     * @param  string|null  $reason  환급 사유
     * @return ClaimPayment|null 새로 만들어졌거나 갱신된 결제 대기 건 (없으면 null)
     *
     * @throws OrderClaimException 환급 실패
     */
    public function settleChargeDifference(OrderExchange|OrderReturn $claim, ?string $reason = null): ?ClaimPayment
    {
        if ($claim->charge_method === null || ! $claim->charge_method->requiresPayment()) {
            $this->cancelPendingPayments($claim);

            return null;
        }

        $total = (float) $claim->total_charge_amount;
        $paid = $this->paymentRepository->sumPaidAmount($claim->claimType()->value, $claim->id);
        $difference = round($total - $paid, 2);

        if ($difference < 0) {
            $this->refundDifference($claim, -$difference, $reason);
            $this->cancelPendingPayments($claim);

            return null;
        }

        $pending = $this->paymentRepository->findPendingByPayable($claim->claimType()->value, $claim->id);

        if ($difference <= 0) {
            $this->cancelPendingPayments($claim);

            return null;
        }

        // 승인 시점 결제는 승인 시도 때 결제 건이 생기므로, 아직 결제대기가 아니면 청구액만 바뀐 채 둔다.
        if ($claim->charge_method === ClaimChargeMethodEnum::PAY_ON_APPROVAL && $pending === null) {
            return null;
        }

        $newMethod = null;

        if ($pending === null) {
            // 신규 결제행도 생성 전에 신청자 자격을 본다 — 허용 목록의 첫 수단이 마일리지인 상점에서
            // 비회원 신청서의 차액 결제행이 `point` 로 만들어지면 그 건은 다시 열리지 않는다.
            $newMethod = $this->resolveDefaultPaymentMethodFor($claim->requested_by === null ? null : (int) $claim->requested_by);
        }

        // 수정 경로도 결제행을 건드리기 전에 통화 기준을 본다 — 여기서 미루면 신청서 수정과 차액
        // 정산은 커밋된 뒤라, 진입 파라미터만 비어 돌아가 화면이 결제창을 열 수 없는데 오류도 없다.
        if (($pending?->payment_method ?? $newMethod) !== PaymentMethodEnum::POINT->value) {
            $claim->loadMissing('order');
            $this->assertClaimPaymentCurrencyResolvable($claim->order);
        }

        $payment = $pending !== null
            ? $this->paymentRepository->update($pending, $this->amountColumns($pending->payment_method, $difference, $claim))
            : $this->createPendingPayment($claim, $newMethod, $difference);

        if ($this->claimStatus($claim) === 'requested' && $claim->charge_method === ClaimChargeMethodEnum::PAY_ON_REQUEST) {
            $this->transitionClaim($claim, 'payment_pending');
        }

        return $payment;
    }

    /**
     * 신청서의 결제 대기 건을 취소합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $reason  취소 사유 (활동 로그 properties 에 원값으로 남는다)
     * @return int 취소한 건수
     */
    public function cancelPendingPayments(
        OrderExchange|OrderReturn $claim,
        string $reason = self::CANCEL_REASON_CHARGE_SETTLED,
    ): int {
        $count = 0;

        foreach ($this->listForClaim($claim) as $payment) {
            if ($payment->isPending()) {
                $this->cancelPending($payment, $claim, $reason);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 보존 기간이 지난 고아 결제 대기 건을 정리합니다. (정리 배치 전용)
     *
     * 취소 대상은 **종결된 신청서(반려·철회·완료)에 남았거나 신청서 자체가 사라진** 결제
     * 대기 건뿐이다 — 살아 있는 신청서(결제대기 등)의 대기 건은 사용자가 아직 결제할 수
     * 있는 유일한 통로라, 결제창 이탈 후 며칠이 지나도 건드리지 않는다. 종결 경로가 대기
     * 건을 함께 취소하지 못한 채 끝난 건(프로세스 중단 등)이 이 배치의 대상이다.
     *
     * @param  int  $days  보존 기간(일)
     * @param  int  $limit  한 번에 처리할 최대 건수
     * @param  bool  $dryRun  실제 취소 없이 대상 건수만 셀지 여부
     * @return array{scanned: int, cancelled: int, skipped_active: int} 처리 결과
     */
    public function reapOrphanedPendingPayments(int $days, int $limit, bool $dryRun = false): array
    {
        $targets = $this->paymentRepository->listPendingOlderThan(now()->subDays($days), $limit);

        $result = ['scanned' => $targets->count(), 'cancelled' => 0, 'skipped_active' => 0];

        foreach ($targets as $payment) {
            $claim = $this->resolveClaim($payment);

            if ($claim !== null && ! $claim->isFinal()) {
                $result['skipped_active']++;

                continue;
            }

            if (! $dryRun) {
                $this->cancelPending($payment, $claim, self::CANCEL_REASON_ORPHAN_REAPED);

                Log::info('[claim.payment] 고아 결제 대기 건 취소', [
                    'claim_payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'payable_type' => $payment->payable_type,
                    'payable_id' => $payment->payable_id,
                ]);
            }

            $result['cancelled']++;
        }

        return $result;
    }

    // ───────────────────────────────────────────────
    // 조회
    // ───────────────────────────────────────────────

    /**
     * 결제번호로 결제 건을 조회합니다.
     *
     * @param  string  $paymentNumber  결제번호 (CP)
     * @return ClaimPayment|null 결제 건
     */
    public function findByNumber(string $paymentNumber): ?ClaimPayment
    {
        return $this->paymentRepository->findByPaymentNumber($paymentNumber);
    }

    /**
     * 신청서의 결제 건 목록을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return Collection<int, ClaimPayment> 결제 건 목록
     */
    public function listForClaim(OrderExchange|OrderReturn $claim): Collection
    {
        return $this->paymentRepository->listByPayable($claim->claimType()->value, $claim->id);
    }

    /**
     * 신청서의 미수납 청구액을 반환합니다. (청구액 − 실수납액)
     *
     * 관리자 목록의 `ClaimAdminListResource::outstandingFromLoadedPayments()` 가 이 식의
     * eager 판(행당 쿼리 없이 로드된 컬렉션 합산)이다 — 식을 바꾸면 그쪽도 함께 고칠 것.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return float 미수납 청구액 (0 이상)
     */
    public function outstandingAmount(OrderExchange|OrderReturn $claim): float
    {
        if (! $claim->requiresAdditionalPayment()) {
            return 0.0;
        }

        // audit:allow service-config-value-clamp reason: 미수납액은 도메인 불변식으로 음수가 될 수 없다(초과 수납은 settleChargeDifference 가 환급으로 해소) — 설정 한계값 재클램프가 아니다
        return max(0.0, round((float) $claim->total_charge_amount - $this->paymentRepository->sumPaidAmount($claim->claimType()->value, $claim->id), 2));
    }

    /**
     * 결제 건이 속한 신청서를 반환합니다.
     *
     * 다형 관계(`payable`) 대신 유형별 Repository 로 싣는다 — 관계 로드 범위(신청 상품 행)를 Repository 가 정한다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return OrderExchange|OrderReturn|null 신청서
     */
    public function resolveClaim(ClaimPayment $payment): OrderExchange|OrderReturn|null
    {
        return match ($payment->claimType()) {
            ClaimTypeEnum::EXCHANGE => $this->exchangeRepository->findById((int) $payment->payable_id, ['exchangeOptions']),
            ClaimTypeEnum::RETURN => $this->returnRepository->findById((int) $payment->payable_id, ['returnOptions']),
            default => null,
        };
    }

    /**
     * 주문의 클레임 추가결제 실수납 합계를 반환합니다. (주문 상세 `claim_additional_paid_total` SSoT)
     *
     * @param  Order  $order  주문
     * @return array{amount: float, mc: array<string, float>} 합계
     */
    public function paidTotalForOrder(Order $order): array
    {
        return $this->paymentRepository->sumNetPaidByOrder($order->id);
    }

    // ───────────────────────────────────────────────
    // 내부 헬퍼
    // ───────────────────────────────────────────────

    /**
     * 결제 대기 건 하나를 취소하고 취소 훅을 발화합니다. (취소 3경로 공용 단일 지점)
     *
     * 취소 사유는 유형 세그먼트를 훅 이름에 넣지 않고 인자로 싣는다 — 고아 정리 배치는
     * 신청서가 이미 사라진 건도 취소하므로 유형을 항상 알 수 없다. 유형을 아는 호출부는
     * `$claim` 을 넘기고, 모르는 호출부는 null 을 넘긴다.
     *
     * 취소 대상은 언제나 **미수납 대기 행**이라 돈이 오간 사건이 아니다. 그래도 이력에
     * 남기는 이유는, 청구액 재산정(`settleChargeDifference`)처럼 사람이 부른 경로가 섞여
     * 있어 "왜 결제 요청이 사라졌는가" 를 운영자가 되짚을 통로가 필요하기 때문이다.
     *
     * @param  ClaimPayment  $payment  취소할 결제 대기 건
     * @param  OrderExchange|OrderReturn|null  $claim  신청서 (해석 불가 시 null)
     * @param  string  $reason  취소 사유 (`CANCEL_REASON_*`)
     * @return ClaimPayment 취소된 결제 건
     */
    protected function cancelPending(
        ClaimPayment $payment,
        OrderExchange|OrderReturn|null $claim,
        string $reason,
    ): ClaimPayment {
        $cancelled = $this->paymentRepository->update($payment, [
            'payment_status' => ClaimPaymentStatusEnum::CANCELLED,
        ]);

        HookManager::doAction('sirsoft-ecommerce.claim.payment_cancelled', $cancelled, $claim, $reason);

        return $cancelled;
    }

    /**
     * 결제 확정 뒤 신청서 전이와 훅 발화를 수행합니다.
     *
     * 청구액이 전액 수납되면 자동 승인 여부와 무관하게 **`paid`(결제완료)를 경유**한다(미수납 잔액이
     * 남아 있으면 결제대기에 머문다 — 화면은 상태 라벨이 아니라 `outstanding_amount` 로 판정한다). 종전에는
     * 결제대기에서 곧장 승인으로 넘어가고 자동 승인이 꺼져 있으면 결제대기에 머물렀는데, 그러면
     * 이미 수납이 끝난 건이 '결제대기' 라벨로 남아 운영자가 미수납 건과 구분할 수 없었다.
     * 경유 지점을 하나 두면 결제 확정 시각과 승인 시각이 상태 이력에서 갈린다.
     *
     * 승인 시점 결제는 운영자가 이미 승인한 것이므로 결제가 끝나면 이어서 승인으로 옮기고, 신청 시점
     * 결제는 `claim.auto_approve_after_payment` 가 참일 때만 옮긴다. 승인 전이가 재고 부족 등으로
     * 거절되면 결제는 확정된 채 `paid` 에 머문다 — 운영자가 재고를 확보한 뒤 수동 승인한다.
     *
     * @param  ClaimPayment  $payment  결제 완료 건
     * @return ClaimPayment 결제 완료 건
     */
    protected function confirmPaid(ClaimPayment $payment): ClaimPayment
    {
        $claim = $this->resolveClaim($payment);

        if ($claim === null) {
            return $payment;
        }

        // 미수납 잔액이 남아 있으면 이 건은 아직 '결제 완료'가 아니다 — 훅도 발화하지 않는다.
        // 분할 수납(승인 전 수정으로 청구액이 오른 뒤 앞 건만 결제한 경우)에서 발화하면 알림·로그가
        // 잔액이 남은 신청서에 완료를 통지한다. 훅 이름이 약속하는 사실과 발화 조건을 일치시킨다.
        if ($this->outstandingAmount($claim) > 0) {
            return $payment;
        }

        // 상태 전이가 훅보다 **먼저**다. 이 훅의 소비자는 알림·활동 로그이고 둘 다 신청서의
        // 현재 상태를 읽는다 — 훅을 앞세우면 정상 경로에서도 리스너가 보는 상태가 언제나
        // `payment_pending` 이라 '결제완료' 를 통지하면서 결제대기라고 적게 된다. 문서
        // (`docs/api/claim-payments.md` 결제 확정 절)도 "옮겨지고 … 훅이 발행됩니다" 로
        // 이 순서를 계약으로 공표한다.
        //
        // 전이는 결제대기 건에서만 일어난다. 이미 `paid` 인 건의 차액을 마저 수납한 경우처럼
        // 전이할 것이 없어도 훅은 발화한다 — 청구액이 다시 전액 수납됐다는 사실은 같기 때문이다.
        if ($this->claimStatus($claim) === 'payment_pending') {
            try {
                // 전이 결과를 되받는다 — 훅 인자가 전이 전 인스턴스면 소비자가 읽는 상태가
                // 여전히 `payment_pending` 이라 순서를 바꾼 의미가 사라진다.
                $claim = $this->transitionClaim($claim, 'paid');
            } catch (OrderClaimException $e) {
                // 결제는 확정됐는데 상태만 못 옮겼다 — 상태를 조용히 앞당기지 않고 운영자에게 남긴다.
                // 훅도 발화하지 않는다: 결제대기에 머문 신청서에 '결제 완료' 를 통지하면 고객이
                // 받은 안내와 화면 상태가 어긋난다(잔액이 남은 분할 수납을 미발화로 막는 것과 같은 이유).
                Log::info('[claim.payment] 결제 확정 전이 거절 — 결제대기 유지', [
                    'payment_number' => $payment->payment_number,
                    'reason' => $e->getMessageKey(),
                ]);

                return $payment;
            }
        }

        HookManager::doAction('sirsoft-ecommerce.claim.'.$claim->claimType()->value.'.payment_completed', $claim, $payment);

        $shouldApprove = $claim->charge_method === ClaimChargeMethodEnum::PAY_ON_APPROVAL
            || (bool) $this->settingsService->getSetting('claim.auto_approve_after_payment', false);

        if (! $shouldApprove) {
            return $payment;
        }

        try {
            $this->transitionClaim($claim, 'approved');
        } catch (OrderClaimException $e) {
            // 결제는 확정됐다 — 승인만 운영자 몫으로 남긴다(신규 옵션 재고 부족 등).
            Log::info('[claim.payment] 결제 확정 후 자동 승인 건너뜀 — 결제완료 유지', [
                'payment_number' => $payment->payment_number,
                'reason' => $e->getMessageKey(),
            ]);
        }

        return $payment;
    }

    /**
     * 결제 대기 건을 만듭니다. (CP 채번)
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $paymentMethod  결제 수단
     * @param  float  $amount  청구액 (기준통화)
     * @return ClaimPayment 결제 대기 건
     */
    protected function createPendingPayment(OrderExchange|OrderReturn $claim, string $paymentMethod, float $amount): ClaimPayment
    {
        return $this->paymentRepository->create([
            'payable_type' => $claim->claimType()->value,
            'payable_id' => $claim->id,
            'order_id' => $claim->order_id,
            'payment_number' => $this->sequenceService->generateCode(SequenceType::CLAIM_PAYMENT),
            'payment_method' => $paymentMethod,
            'pg_provider' => $this->resolvePgProvider($paymentMethod),
            'payment_status' => ClaimPaymentStatusEnum::PENDING,
            'currency_code' => $claim->currency_code ?: 'KRW',
        ] + $this->amountColumns($paymentMethod, $amount, $claim));
    }

    /**
     * 결제 수단에 따른 금액 컬럼 값을 만듭니다.
     *
     * @param  string  $paymentMethod  결제 수단
     * @param  float  $amount  청구액 (기준통화)
     * @param  OrderExchange|OrderReturn  $claim  신청서 (다통화 환산 스냅샷 출처)
     * @return array<string, mixed> amount / points_amount / mc_amount
     */
    protected function amountColumns(string $paymentMethod, float $amount, OrderExchange|OrderReturn $claim): array
    {
        $isPoint = $paymentMethod === PaymentMethodEnum::POINT->value;
        $claim->loadMissing('order');
        $snapshot = $claim->order?->currency_snapshot ?? [];

        return [
            'amount' => $isPoint ? 0 : $amount,
            'points_amount' => $isPoint ? $amount : 0,
            'mc_amount' => $snapshot !== []
                ? ($this->currencyService->convertToMultiCurrencyWithSnapshot($amount == (int) $amount ? (int) $amount : $amount, $snapshot) ?: null)
                : null,
        ];
    }

    /**
     * 청구액 감소분을 최근 결제 건부터 부분 환급합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  float  $amount  환급할 차액
     * @param  string|null  $reason  환급 사유
     * @return void
     */
    protected function refundDifference(OrderExchange|OrderReturn $claim, float $amount, ?string $reason): void
    {
        $remaining = $amount;

        foreach ($this->paymentRepository->listPaidByPayable($claim->claimType()->value, $claim->id)->reverse() as $payment) {
            if ($remaining <= 0) {
                break;
            }

            $portion = min($remaining, $payment->netPaidAmount());
            $this->refundPayment($payment, $portion, $reason);
            $remaining = round($remaining - $portion, 2);
        }
    }

    /**
     * PG 환급을 실행합니다. (`claim_payment.refund` 필터 훅 위임)
     *
     * @param  ClaimPayment  $payment  결제 완료 건
     * @param  float  $amount  환급액 (기준통화)
     * @param  string|null  $reason  환급 사유
     * @return void
     *
     * @throws OrderClaimException 연동 부재 / 환급 실패
     */
    protected function executePgRefund(ClaimPayment $payment, float $amount, ?string $reason): void
    {
        // PG 실환급액은 결제 통화여야 한다 — 결제 시점 동결 환율(mc_amount)로 비례 환산한다.
        // 환산 조건 판정은 `resolvePaymentCurrencyTotal()` 이 단일 출처다 (플러그인도 같은 값을
        // 부분취소 판정에 쓰므로, 여기서 따로 판정하면 두 쪽이 갈라진다).
        $baseTotal = $payment->getTotalAmount();
        $localAmount = $baseTotal > 0
            ? round($this->resolvePaymentCurrencyTotal($payment) * ($amount / $baseTotal), 2)
            : $amount;

        // 키 재료에 이번 환급액을 포함한다 — 결제건 ID + 환급 전 누적액만으로는 "부분환급 A 가
        // PG 실패(누적액 미변경)한 뒤 다른 금액 B 로 재시도" 가 같은 키를 받아, 계약("같은 키의
        // 재요청은 PG 쪽에서 한 번만 처리")대로면 B 가 A 로 접힌다. 같은 금액의 재시도는 같은
        // 키를 유지해 PG 쪽 중복 처리를 막는다. 금액은 기준통화(누적액과 같은 단위) ×100 정수.
        $idempotencyKey = sprintf(
            'g7-claimpay-%d-%d-%d',
            $payment->id,
            (int) round((float) $payment->refunded_amount * 100),
            (int) round($amount * 100),
        );

        $result = HookManager::applyFilters(
            'sirsoft-ecommerce.claim_payment.refund',
            ['success' => false, 'handled' => false, 'transaction_id' => null, 'error_code' => null, 'error_message' => null],
            $payment,
            $localAmount,
            $reason,
            $idempotencyKey,
        );

        if (empty($result['handled']) && empty($result['success'])) {
            throw OrderClaimException::paymentProviderUnavailable();
        }

        if (empty($result['success'])) {
            $this->paymentRepository->update($payment, [
                'pg_error_code' => $result['error_code'] ?? null,
                'pg_error_message' => $result['error_message'] ?? null,
            ]);

            Log::error('[claim.payment] PG 환급 실패', [
                'payment_number' => $payment->payment_number,
                'amount' => $amount,
                'error_code' => $result['error_code'] ?? null,
                'error_message' => $result['error_message'] ?? null,
            ]);

            // 승인 축과 같은 이유로 코드만 노출한다 (원문은 위 컬럼·로그에 남는다).
            throw OrderClaimException::paymentRefundFailed((string) ($result['error_code'] ?? 'unknown'));
        }
    }

    /**
     * PG 가 승인한 금액(최소 화폐단위)이 청구액과 같은지 판정합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  int  $paidAmount  PG 승인 금액
     * @return bool 일치 여부
     */
    protected function paidAmountMatches(ClaimPayment $payment, int $paidAmount): bool
    {
        $expected = $this->expectedMinorUnitAmount($payment);

        // 기준을 세울 수 없으면 통과시키지 않는다 — 주문이 사라져 통화 스냅샷을 못 읽으면 현재 상점
        // 설정으로 미끄러져 소수 자릿수·절사 규칙이 달라지고, 그 상태의 "일치" 는 근거가 없다.
        return $expected !== null && $expected === $paidAmount;
    }

    /**
     * 이 주문으로 결제 통화 기준을 세울 수 있는지 판정합니다.
     *
     * 청구액이 아니라 **기준의 존재**만 본다 — 그래서 결제 건이 아직 없는 접수·수정 시점에도
     * 같은 판정을 앞당겨 쓸 수 있다. `resolvePaymentCharge()` 의 null 조건과 단일 출처다.
     *
     * @param  Order|null  $order  결제 건이 딸린 주문
     * @return bool 결제 통화 기준을 세울 수 있는지 여부
     */
    public function canResolvePaymentCurrency(?Order $order): bool
    {
        return ! empty($order?->currency_snapshot);
    }

    /**
     * 결제행을 만들거나 고치기 **전에** 결제 통화 기준을 세울 수 있는지 검증합니다.
     *
     * 기준을 못 세우면 결제창 진입 파라미터를 만들 수 없으므로 그 결제는 어차피 실패한다. 그때
     * 거절을 상태 변경 **뒤로** 미루면 사용자는 422 를 받는데 결제행과 `payment_pending` 전이는
     * 남아, 낼 수 없는 청구가 걸린 신청서가 된다 (같은 이유로 수단 확정 가드도 앞에 둔다 —
     * `pay()` 주석 참조).
     *
     * @param  Order|null  $order  결제 건이 딸린 주문
     * @return void
     *
     * @throws OrderClaimException 통화 기준 부재
     */
    public function assertClaimPaymentCurrencyResolvable(?Order $order): void
    {
        if (! $this->canResolvePaymentCurrency($order)) {
            throw OrderClaimException::paymentCurrencyUnavailable();
        }
    }

    /**
     * 거래 시점 통화 스냅샷 기준의 결제 통화 청구액을 반환합니다.
     *
     * 결제창 진입(`pg_payment_data.amount`)·승인 금액 대조(`paid_amount`)와 같은 기준이며, 결제
     * 플러그인의 결제창 진입 전 검증 지점(서명 발급·승인키 발급 등)이 클라이언트가 보낸 금액을
     * 대조할 때 쓰라고 공개한다 — 주문 결제의 `resolveOrderPaymentChargeAmount` 에 대응하는
     * 클레임 결제 축이다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return array{minor_unit_amount: int, currency: string}|null 결제 통화 청구액 (스냅샷을 읽을 수 없으면 null)
     */
    public function resolvePaymentCharge(ClaimPayment $payment): ?array
    {
        $payment->loadMissing('order');

        if (! $this->canResolvePaymentCurrency($payment->order)) {
            return null;
        }

        $charge = $this->currencyService->resolveSnapshotPaymentCharge(
            (float) $payment->amount,
            $payment->order->currency_snapshot,
        );

        return [
            'minor_unit_amount' => (int) $charge['minor_unit_amount'],
            'currency' => (string) $charge['currency'],
        ];
    }

    /**
     * 결제 통화 기준의 청구 총액을 반환합니다.
     *
     * `claim_payment.refund` 필터가 받는 `$amount` 와 **같은 통화**의 총액이다. 결제 플러그인은
     * 이 값으로 이번 환급이 부분취소인지(잔액 전액인지) 판정한다 — PG 취소 API 는 대부분
     * 부분/전액을 다른 파라미터로 요구하므로 그 판정이 틀리면 잔액이 통째로 취소된다.
     *
     * 환산 조건(주문 통화 ≠ 기준통화 · 동결 환산액 보유)을 플러그인마다 다시 구현하면 모듈이
     * 조건을 바꿀 때 사본들이 조용히 어긋난다. 그래서 이 판정은 여기가 단일 출처다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return float 결제 통화 기준 총액 (환산할 수 없으면 기준통화 총액)
     */
    public function resolvePaymentCurrencyTotal(ClaimPayment $payment): float
    {
        $payment->loadMissing('order');
        $snapshot = $payment->order?->currency_snapshot ?? [];
        $orderCurrency = $snapshot['order_currency'] ?? $payment->currency_code;
        $baseTotal = $payment->getTotalAmount();

        if ($orderCurrency !== $payment->currency_code && isset($payment->mc_amount[$orderCurrency]) && $baseTotal > 0) {
            return (float) $payment->mc_amount[$orderCurrency];
        }

        return $baseTotal;
    }

    /**
     * 결제 통화 기준의 환급 누적액을 반환합니다.
     *
     * 누적액(`refunded_amount`)은 기준통화로 적재되므로, 결제 통화 총액에 대한 비율로 환산한다 —
     * 모듈이 이번 환급액을 넘길 때 쓰는 비례식과 같다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return float 결제 통화 기준 환급 누적액
     */
    public function resolveRefundedPaymentCurrencyTotal(ClaimPayment $payment): float
    {
        $refunded = (float) $payment->refunded_amount;
        $baseTotal = $payment->getTotalAmount();

        if ($refunded <= 0 || $baseTotal <= 0) {
            return 0.0;
        }

        return round($this->resolvePaymentCurrencyTotal($payment) * ($refunded / $baseTotal), 2);
    }

    /**
     * 거래 시점 통화 스냅샷 기준의 청구액(최소 화폐단위)을 반환합니다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return int|null 최소 화폐단위 청구액 (스냅샷을 읽을 수 없으면 null)
     */
    protected function expectedMinorUnitAmount(ClaimPayment $payment): ?int
    {
        return $this->resolvePaymentCharge($payment)['minor_unit_amount'] ?? null;
    }

    /**
     * 접수 전에 신청 시점 결제 수단의 신청자 자격을 검증합니다.
     *
     * 수단을 **명시한** 요청만 본다 — 미지정은 접수 시점에 신청자가 쓸 수 있는 수단으로 확정되므로
     * 여기서 거절하면 청구가 없는 신청까지 막는 오거절이 된다. 이 검사가 접수 앞에 서야 하는 이유는
     * 접수가 자체 트랜잭션으로 커밋되기 때문이다 — 뒤에서 거절하면 신청서(수량 잠금)만 남는다.
     *
     * @param  string|null  $paymentMethod  요청이 지정한 결제 수단
     * @param  int|null  $userId  신청자 회원 ID (비회원이면 null)
     * @return void
     *
     * @throws OrderClaimException 불허 수단·비회원의 마일리지 결제
     */
    public function assertRequestPaymentMethodAvailable(?string $paymentMethod, ?int $userId): void
    {
        if ($paymentMethod === null || $paymentMethod === '') {
            return;
        }

        $this->assertPaymentMethodAvailableToRequester($this->resolvePaymentMethod($paymentMethod), $userId);
    }

    /**
     * 수단 미지정 시 신청자가 실제로 쓸 수 있는 기본 결제 수단을 고릅니다.
     *
     * 허용 목록의 첫 수단을 그대로 쓰면, 마일리지를 앞에 둔 상점에서 비회원 결제행이 `point` 로
     * 만들어져 다시 열리지 않는다. 신청자가 쓸 수 없는 수단은 건너뛴다.
     *
     * @param  int|null  $userId  신청자 회원 ID (비회원이면 null)
     * @return string 결제 수단
     *
     * @throws OrderClaimException 신청자가 쓸 수 있는 수단이 하나도 없을 때
     */
    protected function resolveDefaultPaymentMethodFor(?int $userId): string
    {
        $method = $this->resolvePaymentMethod(null);

        if ($method !== PaymentMethodEnum::POINT->value || $userId !== null) {
            return $method;
        }

        foreach ($this->allowedPaymentMethods() as $candidate) {
            if ($candidate !== PaymentMethodEnum::POINT->value) {
                return $candidate;
            }
        }

        throw OrderClaimException::pointPaymentRequiresMember();
    }

    /**
     * 결제 수단이 신청자에게 실제로 사용 가능한지 검증합니다.
     *
     * 마일리지는 회원 원장에서 차감하므로 비회원은 쓸 수 없다. 이 판정은 결제행을 **만들기 전에도**
     * 서야 한다 — 만든 뒤에 거절하면 신청서(수량 잠금)와 `point` 가 박힌 결제행이 남고, 그 결제행은
     * 클라이언트가 다른 수단을 명시하지 않는 한 다시 열리지 않는다. FormRequest 는 허용 목록만 보고
     * 회원 여부를 보지 않으므로 이 게이트가 유일한 판정 지점이다.
     *
     * @param  string  $paymentMethod  확정된 결제 수단
     * @param  int|null  $userId  신청자 회원 ID (비회원이면 null)
     * @return void
     *
     * @throws OrderClaimException 비회원의 마일리지 결제
     */
    protected function assertPaymentMethodAvailableToRequester(string $paymentMethod, ?int $userId): void
    {
        if ($paymentMethod === PaymentMethodEnum::POINT->value && $userId === null) {
            throw OrderClaimException::pointPaymentRequiresMember();
        }
    }

    /**
     * 허용된 클레임 추가결제 수단 목록(`claim.charge_payment_methods`)을 반환합니다.
     *
     * @return array<int, string> 허용 수단
     */
    protected function allowedPaymentMethods(): array
    {
        return array_values(array_filter(array_map(
            'strval',
            (array) $this->settingsService->getSetting('claim.charge_payment_methods', [PaymentMethodEnum::CARD->value, PaymentMethodEnum::POINT->value]),
        )));
    }

    /**
     * 결제 수단 입력을 허용 목록(`claim.charge_payment_methods`)으로 검증해 반환합니다.
     *
     * @param  string|null  $paymentMethod  결제 수단 (null 이면 허용 목록의 첫 수단)
     * @return string 결제 수단
     *
     * @throws OrderClaimException 불허 수단
     */
    protected function resolvePaymentMethod(?string $paymentMethod): string
    {
        $allowed = $this->allowedPaymentMethods();

        if ($paymentMethod === null || $paymentMethod === '') {
            return $allowed[0] ?? PaymentMethodEnum::CARD->value;
        }

        if (! in_array($paymentMethod, $allowed, true)) {
            throw OrderClaimException::paymentMethodNotAllowed();
        }

        return $paymentMethod;
    }

    /**
     * 결제 수단의 PG provider 를 판정합니다. (마일리지는 null)
     *
     * @param  string  $paymentMethod  결제 수단
     * @return string|null PG provider id
     */
    protected function resolvePgProvider(string $paymentMethod): ?string
    {
        if ($paymentMethod === PaymentMethodEnum::POINT->value) {
            return null;
        }

        $provider = $this->orderProcessingService->determinePgProvider($paymentMethod);

        return in_array($provider, ['manual', 'internal', 'none'], true) ? null : $provider;
    }

    /**
     * PG provider 의 프론트 결제 진입 핸들러를 레지스트리에서 찾습니다.
     *
     * @param  string  $provider  PG provider id
     * @return string|null 핸들러 풀네임
     */
    protected function resolvePgPaymentHandler(string $provider): ?string
    {
        foreach ($this->settingsService->getRegisteredPgProviders() as $entry) {
            if (($entry['id'] ?? null) === $provider) {
                $handler = $entry['payment_handler'] ?? null;

                return is_string($handler) && $handler !== '' ? $handler : null;
            }
        }

        return null;
    }

    /**
     * 신청서의 현재 상태 값을 반환합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return string 상태 값
     */
    protected function claimStatus(OrderExchange|OrderReturn $claim): string
    {
        $claim->refresh();

        return $claim instanceof OrderExchange
            ? $claim->exchange_status->value
            : $claim->return_status->value;
    }

    /**
     * 신청서를 유형별 서비스로 전이합니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @param  string  $target  목표 상태 값
     * @param  array  $context  부가 정보
     * @return OrderExchange|OrderReturn 전이된 신청서
     *
     * @throws OrderClaimException 전이 불가
     */
    protected function transitionClaim(OrderExchange|OrderReturn $claim, string $target, array $context = []): OrderExchange|OrderReturn
    {
        return $claim instanceof OrderExchange
            ? $this->exchangeService->transition($claim, ExchangeStatusEnum::from($target), $context)
            : $this->returnService->transition($claim, ReturnStatusEnum::from($target), $context);
    }
}
