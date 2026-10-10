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
use Modules\Sirsoft\Ecommerce\Services\Concerns\FiresClaimHooksAfterCommit;
use Modules\Sirsoft\Ecommerce\Services\Concerns\MasksSensitiveResponse;
use Modules\Sirsoft\Ecommerce\Support\ClaimOutstanding;
use Modules\Sirsoft\Ecommerce\Support\ClaimPaymentRedirect;
use Modules\Sirsoft\Ecommerce\Support\SystemActor;

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
 *  - `sirsoft-ecommerce.claim_payment.void_approval` (보상 — 선택 구현)
 *      인자: (array $default, ClaimPayment $payment, array $callback, array $approveResult)
 *      반환: {handled: bool, success: bool, error_code: ?string, error_message: ?string}
 *      PG 는 승인했는데 모듈이 금액 대조로 거절한 건을 되돌린다(망취소). 구현이 없으면 오류 로그로
 *      남겨 운영자가 PG 콘솔에서 수동 취소한다.
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
    use FiresClaimHooksAfterCommit;
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

        $requestedBy = $this->payerUserIdOf($claim);
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
            $method = $this->resolveDefaultPaymentMethodFor($this->payerUserIdOf($claim));

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
     * PG 가 승인했는데 모듈이 금액 대조로 거절하면(`paid_amount` 불일치·미반환) 고객은 청구된 채
     * 실패 화면을 본다. 그래서 `$voidOnReject` 가 참이면 `claim_payment.void_approval` 필터로
     * 망취소를 요청한다 — API 승인 경로(`respondApprove`)는 PG 별 보상 수단을 모르므로 이 훅이
     * 유일한 보상 통로다. 서버 콜백 경로는 PG 별 보상 클로저를 직접 들고 있으므로
     * (`ClaimPaymentCallback::approveAndRedirect()`) 거짓을 넘겨 두 번 취소하지 않는다.
     *
     * @param  ClaimPayment  $payment  결제 대기 건
     * @param  array  $callback  PG 콜백 파라미터 (플러그인이 해석)
     * @param  bool  $voidOnReject  승인 후 금액 거절 시 망취소 훅을 부를지 여부
     * @return ClaimPayment 결제 완료 건
     *
     * @throws OrderClaimException 결제 대기 아님 / 연동 부재 / 승인 실패 / 금액 불일치
     */
    public function approvePgPayment(ClaimPayment $payment, array $callback, bool $voidOnReject = true): ClaimPayment
    {
        // 여기서 잡는 행 잠금은 최신 행을 읽기 위한 것이지 동시 승인을 직렬화하는 장치가 아니다 —
        // 트랜잭션이 곧바로 커밋되므로 아래 검사–확정 구간에는 잠금이 남지 않는다. 그 구간의 모듈
        // 쪽 방어는 **기록 단계의 조건부 갱신**(`updateIfStatus()` — 결제 대기일 때만 확정·실패
        // 기록)뿐이다: 같은 건을 두 번 확정하거나 확정된 행을 실패로 덮는 것은 막지만, 이 메서드는
        // 클라이언트가 부르는 엔드포인트에서 호출되므로 PG 호출 자체는 동시에 두 번 나갈 수 있다
        // (`transaction_id` UNIQUE 는 서로 다른 행의 중복만 막는다). 이중 **청구** 방지는 결제 플러그인의 몫이다 — 계약
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
            // 실패 기록은 **아직 결제 대기일 때만** 쓴다 — PG 왕복 동안 행을 잠그지 않으므로 그
            // 사이 다른 요청이 같은 건을 확정했을 수 있다. 무조건 쓰면 결제 완료된 행의 원응답이
            // 실패 응답으로 덮여 대사 근거가 사라진다(#599 validate P2).
            $this->paymentRepository->updateIfStatus($locked, ClaimPaymentStatusEnum::PENDING, [
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

        // 승인 금액을 대조할 수 없으면(`paid_amount` 미반환) **불일치로 처리한다**(#599 validate P2 #11).
        // 종전에는 키가 없으면 대조를 건너뛰어, 금액을 돌려주지 않는 연동은 얼마가 승인됐든 확정됐다
        // — 결제창 금액을 조작한 요청이 그대로 결제 완료가 된다.
        $paidAmountReported = isset($result['paid_amount']) && is_numeric($result['paid_amount']);

        if (! $paidAmountReported || ! $this->paidAmountMatches($locked, (int) $result['paid_amount'])) {
            $this->paymentRepository->updateIfStatus($locked, ClaimPaymentStatusEnum::PENDING, [
                'pg_error_code' => 'AMOUNT_MISMATCH',
                // 금액은 에러 사유가 아니다 — 대사에 쓴 값은 raw_response 와 아래 로그에 남기고
                // 이 컬럼에는 사유만 둔다(컬럼 의미를 흐리면 운영자 화면이 금액을 사유로 읽는다).
                'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
            ]);

            Log::warning('[claim.payment] PG 승인 금액 불일치', [
                'payment_number' => $locked->payment_number,
                'paid_amount' => $result['paid_amount'] ?? null,
                'paid_amount_reported' => $paidAmountReported,
                'expected_minor_unit_amount' => $this->expectedMinorUnitAmount($locked),
            ]);

            if ($voidOnReject) {
                $this->voidRejectedApproval($locked, $callback, $result);
            }

            throw OrderClaimException::paymentAmountMismatch();
        }

        // 확정도 **아직 결제 대기일 때만** 한다 — 동시 승인 요청 두 건이 모두 여기까지 오면
        // 무조건 갱신은 같은 건을 두 번 확정하고 후속 전이·훅을 두 번 낸다.
        $paid = $this->paymentRepository->updateIfStatus($locked, ClaimPaymentStatusEnum::PENDING, [
            'payment_status' => ClaimPaymentStatusEnum::PAID,
            'transaction_id' => $result['transaction_id'] ?? null,
            'raw_response' => $this->sanitizeResponse($result['raw_response'] ?? null),
            'pg_error_code' => null,
            'pg_error_message' => null,
            'paid_at' => Carbon::now(),
        ]);

        if ($paid === null) {
            $current = $this->paymentRepository->findByPaymentNumber($locked->payment_number) ?? $locked;

            // 다른 요청이 먼저 확정했으면 그 건을 그대로 돌려준다 — 같은 결제 건의 재승인을 결제사 쪽에서 한 번으로 접는
            // 멱등은 결제 플러그인 계약이다(docs/api/claim-payments.md).
            if ($current->isPaid()) {
                return $current;
            }

            // PG 왕복 사이에 철회·고아 정리가 결제 건을 닫았다(F5). PG 에서는 승인됐으므로 고객은 청구된 채 남는다 —
            // 망취소하고 실패를 돌려준다. 예전에는 닫힌 결제 건을 그대로 돌려줘 화면은 성공이었다. 서버 콜백 경로는
            // PG 별 보상 클로저가 이 예외 키로 같은 보상을 한다(`ClaimPaymentCallback`).
            Log::warning('[claim.payment] 승인 중 결제 건이 닫혀 승인을 되돌립니다', [
                'payment_number' => $current->payment_number,
                'payment_status' => $current->payment_status?->value,
                'transaction_id' => $result['transaction_id'] ?? null,
            ]);

            if ($voidOnReject) {
                $this->voidRejectedApproval($current, $callback, $result);
            }

            throw OrderClaimException::paymentWithdrawnDuringApproval();
        }

        return $this->confirmPaid($paid);
    }

    /**
     * PG 가 승인했지만 모듈이 거절한 결제를 망취소로 되돌립니다. (`claim_payment.void_approval` 필터 위임)
     *
     * 보상은 실패해도 흐름을 멈추지 않는다 — 호출부는 곧이어 거절 예외를 던지고, 되돌리지 못한
     * 건은 오류 로그로 남아 운영자가 PG 콘솔에서 수동 취소한다.
     *
     * @param  ClaimPayment  $payment  거절된 결제 건
     * @param  array  $callback  PG 콜백 파라미터
     * @param  array  $approveResult  승인 필터 결과 (transaction_id 등 취소 근거)
     * @return void
     */
    protected function voidRejectedApproval(ClaimPayment $payment, array $callback, array $approveResult): void
    {
        try {
            $void = HookManager::applyFilters(
                'sirsoft-ecommerce.claim_payment.void_approval',
                ['handled' => false, 'success' => false, 'error_code' => null, 'error_message' => null],
                $payment,
                $callback,
                $approveResult,
            );
        } catch (\Throwable $e) {
            Log::error('[claim.payment] 승인 거절 건 망취소 중 예외 — PG 콘솔에서 수동 취소가 필요합니다', [
                'payment_number' => $payment->payment_number,
                'transaction_id' => $approveResult['transaction_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->markVoidFailed($payment, $approveResult, 'exception');

            return;
        }

        if (empty($void['handled']) || empty($void['success'])) {
            Log::error('[claim.payment] 승인 거절 건을 망취소하지 못했습니다 — PG 콘솔에서 수동 취소가 필요합니다', [
                'payment_number' => $payment->payment_number,
                'transaction_id' => $approveResult['transaction_id'] ?? null,
                'handled' => ! empty($void['handled']),
                'error_code' => $void['error_code'] ?? null,
            ]);
            $this->markVoidFailed($payment, $approveResult, (string) ($void['error_code'] ?? (empty($void['handled']) ? 'unhandled' : 'unknown')));

            return;
        }

        Log::info('[claim.payment] 승인 거절 건 망취소 완료', [
            'payment_number' => $payment->payment_number,
            'transaction_id' => $approveResult['transaction_id'] ?? null,
        ]);
    }

    /**
     * 망취소 실패를 결제 건에 남깁니다. (B4)
     *
     * 고객 돈이 PG 에 묶인 사실을 로그에만 두면 운영자 화면에서 그 결제 건을 찾을 수 없다. 사유 코드 `VOID_FAILED` 와
     * 승인 거래 ID 를 남기면 관리자 클레임 상세가 「결제사 콘솔에서 직접 취소」 를 띄운다. 기록이 실패해도 흐름은 멈추지 않는다.
     *
     * @param  ClaimPayment  $payment  거절된 결제 건
     * @param  array  $approveResult  승인 필터 결과
     * @param  string  $reason  망취소 실패 사유 코드
     * @return void 반환값 없음
     */
    protected function markVoidFailed(ClaimPayment $payment, array $approveResult, string $reason): void
    {
        try {
            $this->paymentRepository->update(
                $this->paymentRepository->findByPaymentNumber($payment->payment_number) ?? $payment,
                [
                    'pg_error_code' => 'VOID_FAILED',
                    'pg_error_message' => trim($reason.' '.($approveResult['transaction_id'] ?? '')),
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
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

            // 결제 통화의 소수 자릿수로 맞춘다(S9) — 정수로 자르면 소수 통화에서 청구 2.40 에 2 만 차감돼, 결제 건은
            // PAID 인데 미수납 0.40 이 남아 신청서가 결제완료로 옮겨지지 않고 남은 돈을 낼 곳도 없다. 원화는 0자리라 같다.
            $amount = round(
                $locked->getTotalAmount(),
                $this->currencyService->getDecimalPlaces((string) $locked->currency_code, $locked->order?->currency_snapshot),
            );

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
        // **잔액 판정 → PG·마일리지 환급 → 누적 갱신**을 결제 건 행 잠금 하나 안에서 한다
        // (#599 validate P2). 종전에는 잠금이 최신 행을 읽는 데서 끝나, 철회 환급과 차액 정산 같은
        // 두 요청이 같은 잔액을 읽고 각자 다른 금액을 PG 에 보냈다 — 멱등키가 금액을 재료로
        // 쓰므로 PG 가 둘을 한 번으로 접지 못해 낸 돈보다 더 돌려준다. PG 응답을 기다리는 동안
        // 그 한 행이 잠기는 비용은 이중 환급보다 작다(다른 결제 건·주문은 막지 않는다).
        //
        // PG 가 거절하면 트랜잭션이 되돌아가므로, 거절 사유 컬럼은 롤백 뒤에 따로 기록한다.
        $pgFailure = null;

        try {
            [$updated, $refundAmount] = DB::transaction(function () use ($payment, $amount, $reason, &$pgFailure) {
                $locked = $this->paymentRepository->findByPaymentNumberForUpdate($payment->payment_number) ?? $payment;

                $net = $locked->netPaidAmount();
                $refundAmount = $amount === null ? $net : min((float) $amount, $net);

                if (! $locked->isPaid() || $refundAmount <= 0) {
                    return [$locked, 0.0];
                }

                if ($locked->isPointPayment()) {
                    $userId = $locked->order?->user_id ?? $this->resolveClaim($locked)?->requested_by;

                    // 환급 대상을 못 찾으면 **환급하지 않은 채로 환급 완료를 기록하지 않는다** — 아래
                    // 갱신은 `refunded_amount` 를 누적하고 상태를 옮기므로, 조용히 건너뛰면 낸 돈이
                    // 사라진 채 장부만 정산된 것으로 남고 재시도조차 `isPaid()` 에 막힌다.
                    if ($userId === null) {
                        Log::error('[claim.payment] 마일리지 환급 대상을 해석하지 못해 환급을 중단합니다', [
                            'payment_number' => $locked->payment_number,
                            'payable_type' => $locked->payable_type,
                            'payable_id' => $locked->payable_id,
                        ]);

                        throw OrderClaimException::paymentRefundFailed('MILEAGE_OWNER_UNRESOLVED');
                    }

                    // 원장 상한(`restoreForClaimPayment()`)이 같은 결제 건 행을 잠그고 기환급액을 다시
                    // 세므로, 이 경로 밖에서 들어온 환급과도 겹치지 않는다.
                    $this->mileageService->restoreForClaimPayment((int) $userId, $refundAmount, $locked->currency_code, $locked);
                    $pgRefundTransactionId = null;
                } else {
                    $pgRefundTransactionId = $this->executePgRefund($locked, $refundAmount, $reason, $pgFailure);
                }

                $refundedTotal = (float) $locked->refunded_amount + $refundAmount;
                $fullyRefunded = $refundedTotal >= $locked->getTotalAmount() - 0.005;

                $columns = [
                    'refunded_amount' => $refundedTotal,
                    'refunded_at' => Carbon::now(),
                    'payment_status' => $fullyRefunded ? ClaimPaymentStatusEnum::REFUNDED : ClaimPaymentStatusEnum::PAID,
                ];

                // PG 환급마다 결제사가 준 환급 거래 ID 를 남긴다(P-D11) — 승인 거래 ID(`transaction_id`)와 따로,
                // 부분 환급이 여러 번이면 차례대로 쌓는다. 버리면 결제사 취소 내역과 대사할 근거가 없다.
                if (! $locked->isPointPayment()) {
                    $raw = is_array($locked->raw_response) ? $locked->raw_response : [];
                    $raw['refunds'][] = [
                        'transaction_id' => $pgRefundTransactionId,
                        'amount' => $refundAmount,
                        'refunded_at' => Carbon::now()->toIso8601String(),
                    ];
                    $columns['raw_response'] = $raw;
                }

                $updated = $this->paymentRepository->update($locked, $columns);

                return [$updated, $refundAmount];
            });
        } catch (OrderClaimException $e) {
            if ($pgFailure !== null) {
                $this->paymentRepository->update(
                    $this->paymentRepository->findByPaymentNumber($payment->payment_number) ?? $payment,
                    $pgFailure,
                );
            }

            throw $e;
        }

        if ($refundAmount <= 0) {
            return $updated;
        }

        $claim = $this->resolveClaim($updated);

        if ($claim !== null) {
            $this->fireAfterCommit(fn () => HookManager::doAction(
                'sirsoft-ecommerce.claim.'.$claim->claimType()->value.'.payment_refunded',
                $claim,
                $updated,
                $refundAmount,
            ));
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
        // 단 이미 한 번 받은 뒤(승인을 지난 뒤) 올린 차액은 승인 시도가 다시 오지 않으므로 지금 만든다(S3).
        if ($claim->charge_method === ClaimChargeMethodEnum::PAY_ON_APPROVAL && $pending === null && $paid <= 0.0) {
            return null;
        }

        $newMethod = null;

        if ($pending === null) {
            // 신규 결제행도 생성 전에 신청자 자격을 본다 — 허용 목록의 첫 수단이 마일리지인 상점에서
            // 비회원 신청서의 차액 결제행이 `point` 로 만들어지면 그 건은 다시 열리지 않는다.
            $newMethod = $this->resolveDefaultPaymentMethodFor($this->payerUserIdOf($claim));
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
     * ID 로 결제 건을 조회합니다.
     *
     * @param  int  $id  결제 건 ID
     * @return ClaimPayment|null 결제 건
     */
    public function findById(int $id): ?ClaimPayment
    {
        return $this->paymentRepository->findById($id);
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
     * 이 신청자가 클레임 추가결제에 쓸 수 있는 수단 목록을 반환합니다.
     *
     * 결제 대기 건의 수단은 접수 시점에 확정되지만 그 뒤에 **낼 수 없게 되는 경우**가 있다 —
     * 마일리지 잔액이 모자라거나, 운영자가 그 수단을 `claim.charge_payment_methods` 에서 내리거나.
     * 두 경우 모두 `pay()` 가 거절하므로, 화면이 다른 수단을 고를 수 있어야 사용자가 신청서를
     * 철회하지 않고 빠져나갈 수 있다(#599 3차 실측 F-1·F-2).
     *
     * 허용 목록의 단일 출처는 전역 모듈 설정이다 — 신청 화면이 `claimable` 응답에서 읽는 것도
     * 같은 설정의 사본이라, 신청서 상세가 데이터소스를 더 들 필요가 없다. 판정은 `pay()` 가
     * 쓰는 `allowedPaymentMethods()` · `assertPaymentMethodAvailableToRequester()` 와 **같은 두
     * 게이트**다 — 화면 옵션 ⊆ 게이트 ⊆ 서비스를 유지해야 고를 수 있는데 누르면 막히는 칸이
     * 생기지 않는다.
     *
     * @param  int|null  $userId  신청자 회원 ID (비회원이면 null)
     * @return array<int, string> 쓸 수 있는 결제 수단
     */
    public function availablePaymentMethodsFor(?int $userId): array
    {
        return array_values(array_filter(
            $this->allowedPaymentMethods(),
            function (string $method) use ($userId): bool {
                try {
                    $this->assertPaymentMethodAvailableToRequester($method, $userId);
                } catch (OrderClaimException) {
                    return false;
                }

                return true;
            },
        ));
    }

    /**
     * 신청서의 미수납 청구액을 반환합니다. (청구액 − 실수납액)
     *
     * 식은 `ClaimOutstanding::amount()` 가 단일 출처다 — 관리자 목록
     * (`ClaimAdminListResource::outstandingFromLoadedPayments()`)은 같은 식에 eager load 한 결제 건
     * 합계를 넣는다. 여기서는 재료(실수납 합계)를 저장소에서 모으기만 한다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return float 미수납 청구액 (0 이상)
     */
    public function outstandingAmount(OrderExchange|OrderReturn $claim): float
    {
        if (! $claim->requiresAdditionalPayment()) {
            return 0.0;
        }

        return ClaimOutstanding::amount(
            (float) $claim->total_charge_amount,
            $this->paymentRepository->sumPaidAmount($claim->claimType()->value, $claim->id),
        );
    }

    /**
     * 신청서에 **실제로 들어온 돈**을 반환합니다. (부분 환급을 뺀 실수납 합계)
     *
     * `outstandingAmount()` 는 청구액에서 이것을 빼 0 으로 클램프하므로 「아직 아무것도 내지
     * 않았는가」를 그 값으로는 알 수 없다 — 청구액이 0 이어도 0 이 나온다. 운영자의 금액 수정을
     * 어디까지 결제행에 반영할지 가르는 판정(`AdminClaimService::updateAmounts()`)에는 수납액
     * 자체가 필요해 별도로 노출한다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return float 실수납 합계 (0 이상)
     */
    public function paidAmount(OrderExchange|OrderReturn $claim): float
    {
        return $this->paymentRepository->sumPaidAmount($claim->claimType()->value, $claim->id);
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
        // (`modules/_bundled/sirsoft-ecommerce/docs/api/claim-payments.md` 결제 확정 절)도 "옮겨지고 … 훅이 발행됩니다" 로
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
                // 경고로 올린다(B5) — 신청서가 결제대기에 멈춘 이유가 여기 말고는 없다. 상세는
                // `ClaimResource::paid_but_transition_failed` 로 같은 사실을 보인다.
                Log::warning('[claim.payment] 결제 확정 전이 거절 — 결제대기 유지', [
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
            // 결제 확정은 낸 사람이 한 일이지만 그 뒤 승인은 설정이 한 일이다 — 작업자는 시스템(S1-D16).
            SystemActor::run(fn () => $this->transitionClaim($claim, 'approved'));
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
     * PG 가 거절하면 거절 사유 컬럼 갱신값을 `$failure` 에 담아 돌려준다 — 호출부가 트랜잭션 안에서
     * 부르므로 여기서 쓰면 곧 이어지는 예외의 롤백에 함께 지워진다. 호출부가 롤백 뒤에 기록한다.
     *
     * @param  ClaimPayment  $payment  결제 완료 건
     * @param  float  $amount  환급액 (기준통화)
     * @param  string|null  $reason  환급 사유
     * @param  array|null  $failure  PG 거절 시 기록할 컬럼 갱신값 (참조)
     * @return string|null 결제사 환급 거래 ID (플러그인이 주지 않으면 null)
     *
     * @throws OrderClaimException 연동 부재 / 환급 실패
     */
    protected function executePgRefund(ClaimPayment $payment, float $amount, ?string $reason, ?array &$failure = null): ?string
    {
        // PG 실환급액은 결제 통화여야 한다 — 결제 시점 동결 환율(mc_amount)로 비례 환산한다.
        // 환산 조건 판정은 `resolvePaymentCurrencyTotal()` 이 단일 출처다 (플러그인도 같은 값을
        // 부분취소 판정에 쓰므로, 여기서 따로 판정하면 두 쪽이 갈라진다).
        $localAmount = $this->localRefundAmount($payment, $amount);

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
            $failure = [
                'pg_error_code' => $result['error_code'] ?? null,
                'pg_error_message' => $result['error_message'] ?? null,
            ];

            Log::error('[claim.payment] PG 환급 실패', [
                'payment_number' => $payment->payment_number,
                'amount' => $amount,
                'error_code' => $result['error_code'] ?? null,
                'error_message' => $result['error_message'] ?? null,
            ]);

            // 승인 축과 같은 이유로 코드만 노출한다 (원문은 위 컬럼·로그에 남는다).
            throw OrderClaimException::paymentRefundFailed((string) ($result['error_code'] ?? 'unknown'));
        }

        return filled($result['transaction_id'] ?? null) ? (string) $result['transaction_id'] : null;
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
        return $this->paymentCurrencyShareOf($payment, (float) $payment->refunded_amount);
    }

    /**
     * 기준통화 누적액에 대응하는 결제 통화 누적액을 **결제 통화 자릿수로** 반환합니다.
     *
     * 누적액이 청구 총액에 닿으면 결제 통화 총액 그 자체를 돌려준다 — 비율로 다시 곱하면
     * 반올림 오차로 총액과 한 단위가 어긋난다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @param  float  $baseCumulative  기준통화 누적액
     * @return float 결제 통화 누적액
     */
    protected function paymentCurrencyShareOf(ClaimPayment $payment, float $baseCumulative): float
    {
        $baseTotal = $payment->getTotalAmount();

        if ($baseCumulative <= 0 || $baseTotal <= 0) {
            return 0.0;
        }

        $localTotal = $this->resolvePaymentCurrencyTotal($payment);

        if ($baseCumulative >= $baseTotal - 0.005) {
            return $localTotal;
        }

        return round($localTotal * ($baseCumulative / $baseTotal), $this->paymentCurrencyDecimals($payment));
    }

    /**
     * 이번 환급액(기준통화)을 PG 에 보낼 결제 통화 금액으로 환산합니다.
     *
     * **누적 차분**으로 구한다(#599 validate P2 #9): `결제통화(환급 후 누적) − 결제통화(환급 전 누적)`.
     * 회차마다 따로 반올림하면 0 자리 통화에서 334 + 334 + 334 = 1,002 처럼 합이 결제 총액(1,001)을
     * 넘고, 종전처럼 소수 2자리로 두면 엔화에 333.63 같은 금액을 보내 PG 가 거절한다. 누적 차분은
     * 매 회차 결제 통화 자릿수를 지키면서 마지막 회차가 정확히 잔액이 되게 한다.
     *
     * @param  ClaimPayment  $payment  결제 건 (환급 전 `refunded_amount`)
     * @param  float  $amount  이번 환급액 (기준통화)
     * @return float 결제 통화 환급액
     */
    protected function localRefundAmount(ClaimPayment $payment, float $amount): float
    {
        if ($payment->getTotalAmount() <= 0) {
            return $amount;
        }

        $before = (float) $payment->refunded_amount;
        $after = min($payment->getTotalAmount(), $before + $amount);

        return round(
            max(0.0, $this->paymentCurrencyShareOf($payment, $after) - $this->paymentCurrencyShareOf($payment, $before)),
            $this->paymentCurrencyDecimals($payment),
        );
    }

    /**
     * 결제 통화의 소수 자릿수를 거래 시점 스냅샷 기준으로 반환합니다.
     *
     * 결제 통화 판정은 `resolvePaymentCurrencyTotal()` 과 같다 — 주문 통화로 동결 환산액이 있으면
     * 그 통화, 없으면 결제 건의 통화다.
     *
     * @param  ClaimPayment  $payment  결제 건
     * @return int 소수 자릿수
     */
    protected function paymentCurrencyDecimals(ClaimPayment $payment): int
    {
        $payment->loadMissing('order');
        $snapshot = $payment->order?->currency_snapshot ?? [];
        $orderCurrency = $snapshot['order_currency'] ?? $payment->currency_code;
        $currency = $orderCurrency !== $payment->currency_code && isset($payment->mc_amount[$orderCurrency])
            ? $orderCurrency
            : $payment->currency_code;

        return $this->currencyService->getDecimalPlaces((string) $currency, $snapshot !== [] ? $snapshot : null);
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
     * 클레임 추가결제를 낼 회원 id 를 반환합니다. (비회원 주문이면 null)
     *
     * 운영자 직권 접수 건은 `requested_by` 가 비회원 신청과 똑같이 null 이다. 그 값으로 결제할 사람을 정하면
     * 회원 주문의 판매자 접수 건이 비회원 취급되어 마일리지가 선택지·기본 수단에서 빠진다(S3-D15). 신청서의
     * 비회원 판정(`isGuestRequest`)과 같이 **주문의 주인**으로 가른다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return int|null 결제할 회원 id
     */
    public function payerUserIdOf(OrderExchange|OrderReturn $claim): ?int
    {
        if ($claim->requested_by !== null) {
            return (int) $claim->requested_by;
        }

        $claim->loadMissing('order');

        return $claim->order?->user_id === null ? null : (int) $claim->order->user_id;
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
