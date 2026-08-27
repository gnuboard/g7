<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\DTO\CancellableAmount;
use Modules\Sirsoft\Ecommerce\DTO\CancellationAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ExchangeAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\OrderAdjustment;
use Modules\Sirsoft\Ecommerce\DTO\ReturnAdjustment;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;

/**
 * 취소 가능 금액 산정기 (D19)
 *
 * "이 주문에서 지금 얼마까지 되돌릴 수 있는가" 를 세 상한의 최솟값으로 산정한다.
 *
 * ```
 * cancellable = max(0, min(① domain_refundable, ② payment_capacity) − ③ reserved)
 * ```
 *
 * | 축 | 정의 | 출처 |
 * |---|---|---|
 * | ① 도메인 환불 대상 | 재계산으로 나온 환불 대상 금액 | `OrderAdjustmentService::calculate()` **그대로** |
 * | ② 결제수단 잔여 한도 | 수단별 실청구액 − 누적취소액 | `OrderPayment::getCancellableAmount()` + 마일리지 잔여 |
 * | ③ 진행 중 클레임 선점액 | 아직 실행되지 않은 다른 클레임이 잡아 둔 환불 예정액 | 클레임 3종 조회 |
 *
 * ① 을 여기서 다시 계산하지 않는 것이 이 클래스의 핵심 규율이다. 배송비 재판정·조건부무료
 * 소멸·쿠폰 최소금액 미달 복원이 전부 `OrderAdjustmentService` 에서 나오므로, 같은 식을
 * 두 벌로 두면 한쪽만 고쳐질 때 화면이 보여 준 한도와 서버가 강제하는 한도가 갈린다.
 *
 * ③ 은 신설 축이다. 기존 취소는 즉시 실행이라 선점 구간이 0 이었지만, 클레임은
 * 승인 → 회수 → 완료 사이에 시간이 있어 "승인은 됐는데 아직 환불되지 않은" 금액이 존재한다.
 * 그 금액이 두 번 나가지 않게 하려면 한도에서 미리 빼야 한다.
 *
 * 관리자 화면 표시와 서버 실행 가드가 **같은 메서드**를 쓴다. 저장 시점과 실행 직전 두 번
 * 재계산해 동시 처리를 차단하는 것이 D19 의 설계다.
 */
class CancellableAmountCalculator
{
    /**
     * 실제로 청구가 발생해 잔여 한도를 가질 수 있는 결제 상태.
     *
     * 부분취소된 결제행도 남은 한도가 있으므로 포함한다 — `PAID` 만 보면 한 번이라도
     * 부분취소된 주문의 잔여 한도가 통째로 0 이 되어 정당한 환불이 막힌다.
     * 전체취소는 `getCancellableAmount()` 가 이미 0 이하를 돌려주므로 값으로 걸러진다.
     */
    protected const CHARGED_PAYMENT_STATUSES = [
        PaymentStatusEnum::PAID,
        PaymentStatusEnum::PARTIAL_CANCELLED,
        PaymentStatusEnum::CANCELLED,
    ];

    /**
     * @param  OrderAdjustmentService  $adjustmentService  주문 변경 금액 계산 서비스 (① 축 SSoT)
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 Repository
     * @param  OrderReturnRepositoryInterface  $returnRepository  반품 신청서 Repository
     * @param  OrderExchangeRepositoryInterface  $exchangeRepository  교환 신청서 Repository
     */
    public function __construct(
        protected OrderAdjustmentService $adjustmentService,
        protected CurrencyConversionService $currencyService,
        protected OrderRefundRepositoryInterface $refundRepository,
        protected OrderReturnRepositoryInterface $returnRepository,
        protected OrderExchangeRepositoryInterface $exchangeRepository,
    ) {}

    /**
     * 취소 가능 금액을 산정합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array  $items  클레임 대상 아이템 [{order_option_id, cancel_quantity}]
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  OrderAdjustment|null  $adjustment  이미 배송비·차액이 동결된 변경 DTO (없으면 유형·아이템으로 구성)
     * @param  array<string, array<int>>  $excludeClaims  선점 계산에서 제외할 클레임 {type => [id, ...]}
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return CancellableAmount 산정 결과
     */
    public function calculate(
        Order $order,
        array $items,
        ClaimTypeEnum $claimType,
        ?OrderAdjustment $adjustment = null,
        array $excludeClaims = [],
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): CancellableAmount {
        $order->loadMissing(['options', 'shippings', 'payments']);

        $adjustment ??= $this->buildAdjustment($claimType, $items);

        // ① 도메인 환불 대상 — 재계산 SSoT 를 그대로 쓴다 (별도 구현 금지).
        $domainRefundable = (float) $this->adjustmentService
            ->calculate($order, $adjustment, $refundPriority)
            ->getTotalRefundAmount();

        return $this->assembleFromDomain($order, $domainRefundable, $excludeClaims, $refundPriority);
    }

    /**
     * 환불신청서 1건의 취소 가능 금액을 산정합니다. (D18 저장·환불 실행 가드 공용)
     *
     * "저장 시점과 실행 직전 두 번 같은 식으로 재계산한다" 의 그 '같은 식'이다 — 환불액 수정
     * API 와 환불 실행 API, 그리고 환불 상세 화면(`cancellable-amount?refund_id=`)이 모두 이
     * 메서드를 읽는다. 선점(③) 집계에서 자기 자신은 반드시 빠진다 — 자기 금액을 스스로 빼면
     * 한도가 0 이 되어 정당한 환불이 막힌다.
     *
     * 출처별 ① 축:
     * - `direct`: 신청 상품으로 취소 재계산 (취소·환불 신청과 같은 산식)
     * - `return`: 원 반품의 회수배송비·청구방식을 실은 반품 재계산 (완료 접수와 같은 산식)
     * - `exchange`: 교환이 동결한 차액 순액(`refund_product_amount`)에서 다른 환불신청서가 이미
     *   접수한 몫을 뺀 잔여 — 교환 차액은 재계산 산출물이 아니라 신청 시점 동결값이 정본이다
     * - 출처 소실·취소 발(`cancel`): 저장된 환불액 자체를 상한으로 삼는다 (②·③ 축은 그대로 작동)
     *
     * @param  Order  $order  대상 주문
     * @param  OrderRefund  $refund  환불신청서
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return CancellableAmount 산정 결과
     */
    public function calculateForRefund(
        Order $order,
        OrderRefund $refund,
        RefundPriorityEnum $refundPriority = RefundPriorityEnum::PG_FIRST,
    ): CancellableAmount {
        $order->loadMissing(['options', 'shippings', 'payments']);
        $refund->loadMissing('refundOptions');

        $excludeClaims = [ClaimTypeEnum::REFUND->value => [$refund->id]];

        $items = [];
        foreach ($refund->refundOptions as $option) {
            $items[] = [
                'order_option_id' => $option->order_option_id,
                'cancel_quantity' => (int) $option->quantity,
            ];
        }

        if ($refund->refund_source_type === RefundSourceTypeEnum::DIRECT && $items !== []) {
            return $this->calculate($order, $items, ClaimTypeEnum::REFUND, null, $excludeClaims, $refundPriority);
        }

        if ($refund->refund_source_type === RefundSourceTypeEnum::RETURN && $items !== []) {
            $sourceReturn = $refund->refund_source_id !== null
                ? $this->returnRepository->findById($refund->refund_source_id)
                : null;

            if ($sourceReturn !== null) {
                $excludeClaims[ClaimTypeEnum::RETURN->value] = [$sourceReturn->id];

                $adjustment = new ReturnAdjustment(
                    returnItems: $items,
                    collectionFee: (int) round((float) $sourceReturn->claim_shipping_fee),
                    chargeMethod: $sourceReturn->charge_method,
                );

                return $this->calculate($order, $items, ClaimTypeEnum::RETURN, $adjustment, $excludeClaims, $refundPriority);
            }
        }

        if ($refund->refund_source_type === RefundSourceTypeEnum::EXCHANGE && $refund->refund_source_id !== null) {
            $sourceExchange = $this->exchangeRepository->findById($refund->refund_source_id);

            if ($sourceExchange !== null) {
                $domainRefundable = max(
                    0.0,
                    (float) $sourceExchange->refund_product_amount
                        - $this->filedExchangeRefundTotal($sourceExchange, $refund->id),
                );

                return $this->assembleFromDomain($order, $domainRefundable, $excludeClaims, $refundPriority);
            }
        }

        // 출처 소실(원 신청서 삭제)·취소 발 — 재계산 근거가 없으므로 저장된 환불액을 상한으로 둔다.
        // 결제수단 잔여(②)·선점(③) 축은 그대로 작동해 초과 실행을 막는다.
        $domainRefundable = (float) $refund->refund_amount + (float) $refund->refund_points_amount;

        return $this->assembleFromDomain($order, $domainRefundable, $excludeClaims, $refundPriority);
    }

    /**
     * ① 축 값에 ②·③ 축을 얹어 산정 결과를 조립합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  float  $domainRefundable  ① 도메인 환불 대상 금액
     * @param  array<string, array<int>>  $excludeClaims  선점 계산 제외 클레임
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return CancellableAmount 산정 결과
     */
    protected function assembleFromDomain(
        Order $order,
        float $domainRefundable,
        array $excludeClaims,
        RefundPriorityEnum $refundPriority,
    ): CancellableAmount {
        // ② 결제수단 잔여 한도.
        [$paymentCapacity, $methods] = $this->resolvePaymentCapacity($order);

        // ③ 진행 중 클레임 선점액.
        [$reserved, $reservedClaims] = $this->resolveReserved($order, $excludeClaims, $refundPriority);

        $ceiling = min($domainRefundable, $paymentCapacity);
        $amount = max(0.0, $ceiling - $reserved);

        return new CancellableAmount(
            amount: $amount,
            domainRefundable: $domainRefundable,
            paymentCapacity: $paymentCapacity,
            reserved: $reserved,
            binding: $this->resolveBinding($domainRefundable, $paymentCapacity, $reserved),
            methods: $methods,
            reservedClaims: $reservedClaims,
            currency: $this->resolveBaseCurrency($order),
            mcAmount: $this->convertToMultiCurrency($order, $amount),
            // 표기 자릿수·절사 규칙은 거래 시점 스냅샷이 SSoT 다 — 운영자가 그 통화를 지우면
            // 현재 설정에서 사라져 폴백 2자리가 걸리고, 0자리 통화의 과거 금액 표기가 흔들린다.
            currencySnapshot: $this->currencySnapshotOf($order),
        );
    }

    /**
     * 클레임 유형에 맞는 변경 DTO 를 구성합니다.
     *
     * 배송비·옵션 차액이 아직 산정되지 않은 조회 시점(신청 전 프리뷰)에서 쓰며,
     * 이미 동결된 클레임은 호출자가 완성된 DTO 를 넘긴다.
     *
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @param  array  $items  대상 아이템 [{order_option_id, cancel_quantity}]
     * @return OrderAdjustment 변경 DTO
     */
    protected function buildAdjustment(ClaimTypeEnum $claimType, array $items): OrderAdjustment
    {
        return match ($claimType) {
            ClaimTypeEnum::EXCHANGE => new ExchangeAdjustment(exchangeItems: $items),
            ClaimTypeEnum::RETURN => new ReturnAdjustment(returnItems: $items),
            ClaimTypeEnum::REFUND, ClaimTypeEnum::CANCEL => CancellationAdjustment::fromArray($items),
        };
    }

    /**
     * 결제수단별 잔여 한도(② 축)를 산출합니다.
     *
     * 결제행의 한도는 결제 통화 기준이므로 기본 통화로 환산해 합산한다 — ① 축이 기본 통화라
     * 환산 없이 `min()` 을 취하면 통화가 다른 주문에서 값은 맞고 단위만 틀린 한도가 나온다.
     * 화면 표시용으로는 결제 통화 원값(`remaining_local`)도 함께 싣는다.
     *
     * 마일리지는 결제행이 없고 주문 컬럼(`total_points_used_amount`)이 원장이므로
     * 별도 항목으로 더한다.
     *
     * @param  Order  $order  대상 주문
     * @return array{0: float, 1: array<int, array>} [기본 통화 합계, 수단별 내역]
     */
    protected function resolvePaymentCapacity(Order $order): array
    {
        $methods = [];
        $total = 0.0;

        foreach ($order->payments as $payment) {
            if (! $this->isChargedPayment($payment)) {
                continue;
            }

            $local = max(0.0, (float) $payment->getCancellableAmount());
            $base = $this->toBaseCurrency($local, $payment->currency, $order);

            $total += $base;

            $methods[] = [
                'type' => CancellableAmount::METHOD_PAYMENT,
                'payment_id' => $payment->id,
                'method' => $payment->payment_method,
                'currency' => $payment->currency,
                'remaining_local' => $local,
                'remaining_base' => $base,
            ];
        }

        $mileageRemaining = max(
            0.0,
            (float) $order->total_points_used_amount - (float) $order->total_refunded_points_amount
        );

        if ($mileageRemaining > 0) {
            $total += $mileageRemaining;

            $methods[] = [
                'type' => CancellableAmount::METHOD_MILEAGE,
                'payment_id' => null,
                'method' => CancellableAmount::METHOD_MILEAGE,
                'currency' => $this->resolveBaseCurrency($order),
                'remaining_local' => $mileageRemaining,
                'remaining_base' => $mileageRemaining,
            ];
        }

        return [$total, $methods];
    }

    /**
     * 결제행이 실제로 청구가 발생한 행인지 판정합니다.
     *
     * @param  OrderPayment  $payment  결제행
     * @return bool 잔여 한도를 가질 수 있는 행이면 true
     */
    protected function isChargedPayment(OrderPayment $payment): bool
    {
        return in_array($payment->payment_status, self::CHARGED_PAYMENT_STATUSES, true);
    }

    /**
     * 진행 중 클레임 선점액(③ 축)을 산출합니다.
     *
     * 대상은 "승인은 됐거나 진행 중인데 아직 환불이 실행되지 않은" 건이다.
     *
     * - 환불신청서(`OrderRefund`): 저장된 환불액이 곧 선점액이다 (권위 있는 값)
     * - 반품(`OrderReturn`): 환불신청서가 아직 없는 건만 재계산으로 환불 예정액을 구한다.
     *   환불신청서가 생긴 건은 그쪽에서 이미 세므로 여기서 또 세면 두 배가 된다
     * - 교환(`OrderExchange`): 저가 교환 차액 환불분(`refund_product_amount`)만 선점 대상이다.
     *   교환의 배송비·차액 청구는 받는 돈이지 나가는 돈이 아니다. 교환은 완료 라운드마다
     *   환불신청서를 접수할 수 있어 진행 중에도 그 교환에서 난 환불신청서가 있을 수 있다 —
     *   그 몫(환불액 + 마일리지 + 차감 배송비, 반려·철회 제외)은 환불신청서 쪽에서 세거나 이미
     *   나갔으므로 여기서 빼야 두 번 세지 않는다
     *
     * 자기 자신은 반드시 제외해야 한다 — 환불 실행 직전 가드에서 실행 대상 클레임이
     * 선점 목록에 남아 있으면 자기 금액을 스스로 빼 한도가 0 이 되고, 정당한 환불이 막힌다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, array<int>>  $excludeClaims  제외할 클레임 {type => [id, ...]}
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return array{0: float, 1: array<int, array>} [선점액 합계, 선점 근거]
     */
    protected function resolveReserved(Order $order, array $excludeClaims, RefundPriorityEnum $refundPriority): array
    {
        $entries = [];
        $total = 0.0;

        foreach ($this->pendingRefunds($order, $excludeClaims) as $refund) {
            $amount = (float) $refund->refund_amount + (float) $refund->refund_points_amount;

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $entries[] = [
                'type' => ClaimTypeEnum::REFUND->value,
                'id' => $refund->id,
                'number' => $refund->refund_number,
                'amount' => $amount,
            ];
        }

        foreach ($this->pendingReturns($order, $excludeClaims) as $orderReturn) {
            $amount = $this->estimateReturnRefund($order, $orderReturn, $refundPriority);

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $entries[] = [
                'type' => ClaimTypeEnum::RETURN->value,
                'id' => $orderReturn->id,
                'number' => $orderReturn->return_number,
                'amount' => $amount,
            ];
        }

        foreach ($this->pendingExchanges($order, $excludeClaims) as $exchange) {
            $amount = max(0.0, (float) $exchange->refund_product_amount - $this->filedExchangeRefundTotal($exchange));

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $entries[] = [
                'type' => ClaimTypeEnum::EXCHANGE->value,
                'id' => $exchange->id,
                'number' => $exchange->exchange_number,
                'amount' => $amount,
            ];
        }

        return [$total, $entries];
    }

    /**
     * 아직 실행되지 않은 환불신청서를 조회합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, array<int>>  $excludeClaims  제외할 클레임
     * @return Collection<int, OrderRefund> 환불신청서 목록
     */
    protected function pendingRefunds(Order $order, array $excludeClaims): Collection
    {
        return $this->refundRepository->listPendingByOrderId(
            $order->id,
            $this->excludedIds($excludeClaims, ClaimTypeEnum::REFUND),
        );
    }

    /**
     * 환불신청서가 아직 없는 진행 중 반품을 조회합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, array<int>>  $excludeClaims  제외할 클레임
     * @return Collection<int, OrderReturn> 반품 목록
     */
    protected function pendingReturns(Order $order, array $excludeClaims): Collection
    {
        return $this->returnRepository->listPendingWithoutRefundByOrderId(
            $order->id,
            $this->excludedIds($excludeClaims, ClaimTypeEnum::RETURN),
            ['returnOptions'],
        );
    }

    /**
     * 진행 중 교환을 조회합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  array<string, array<int>>  $excludeClaims  제외할 클레임
     * @return Collection<int, OrderExchange> 교환 목록
     */
    protected function pendingExchanges(Order $order, array $excludeClaims): Collection
    {
        return $this->exchangeRepository->listPendingByOrderId(
            $order->id,
            $this->excludedIds($excludeClaims, ClaimTypeEnum::EXCHANGE),
        );
    }

    /**
     * 이 교환에서 이미 접수된 환불신청서의 차액 총액을 반환합니다. (반려·철회 제외)
     *
     * 환불신청서의 `refund_amount` 는 차감 배송비를 뺀 순액이므로, 교환 헤더의 차액(총액)과 같은
     * 단위로 돌리기 위해 차감 배송비(`refund_shipping_amount` 의 음수분)를 더해 **총액**으로 센다.
     * 완료된 환불도 포함한다 — 그 돈은 이미 나가 ② 축(결제수단 잔여)에서 빠져 있다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @param  int|null  $excludeRefundId  집계에서 뺄 환불신청서 ID (실행·수정 중인 자기 자신)
     * @return float 접수된 차액 총액
     */
    protected function filedExchangeRefundTotal(OrderExchange $exchange, ?int $excludeRefundId = null): float
    {
        return (float) $this->refundRepository
            ->listBySource(RefundSourceTypeEnum::EXCHANGE->value, $exchange->id)
            ->reject(fn (OrderRefund $refund) => $refund->id === $excludeRefundId || in_array(
                $refund->refund_status,
                [RefundStatusEnum::REJECTED, RefundStatusEnum::WITHDRAWN],
                true,
            ))
            ->sum(fn (OrderRefund $refund) => (float) $refund->refund_amount
                + (float) $refund->refund_points_amount
                + max(0.0, -(float) $refund->refund_shipping_amount));
    }

    /**
     * 반품 1건의 환불 예정액을 재계산으로 추정합니다.
     *
     * 반품 헤더에는 환불 예정액 컬럼이 없다 — 완료 처리 시점에 비로소 환불신청서가 만들어지기
     * 때문이다. 그래서 선점액은 그 반품이 담은 상품과 동결된 회수배송비로 ① 축과 **같은
     * 재계산기**를 돌려 구한다. 다른 식으로 추정하면 선점액과 실제 환불액이 갈린다.
     *
     * @param  Order  $order  대상 주문
     * @param  OrderReturn  $orderReturn  반품 신청서
     * @param  RefundPriorityEnum  $refundPriority  환불 우선순위
     * @return float 환불 예정액 (기본 통화)
     */
    protected function estimateReturnRefund(Order $order, OrderReturn $orderReturn, RefundPriorityEnum $refundPriority): float
    {
        $items = [];

        foreach ($orderReturn->returnOptions as $option) {
            $items[] = [
                'order_option_id' => $option->order_option_id,
                'cancel_quantity' => (int) $option->return_quantity,
            ];
        }

        if ($items === []) {
            return 0.0;
        }

        $adjustment = new ReturnAdjustment(
            returnItems: $items,
            collectionFee: (int) round((float) $orderReturn->claim_shipping_fee),
            chargeMethod: $orderReturn->charge_method,
        );

        return (float) $this->adjustmentService
            ->calculate($order, $adjustment, $refundPriority)
            ->getTotalRefundAmount();
    }

    /**
     * 한도를 정한 축을 판정합니다.
     *
     * 선점액이 실제로 한도를 깎았을 때만 선점 축으로 본다 — 선점이 0 이면
     * 두 상한 중 작은 쪽이 한도를 정한 것이고, 동률이면 도메인 축으로 표기한다.
     *
     * @param  float  $domainRefundable  ① 도메인 환불 대상
     * @param  float  $paymentCapacity  ② 결제수단 잔여 한도
     * @param  float  $reserved  ③ 선점액
     * @return string 한도를 정한 축 (BINDING_* 상수)
     */
    protected function resolveBinding(float $domainRefundable, float $paymentCapacity, float $reserved): string
    {
        if ($reserved > 0) {
            return CancellableAmount::BINDING_RESERVED;
        }

        if ($paymentCapacity < $domainRefundable) {
            return CancellableAmount::BINDING_PAYMENT;
        }

        return CancellableAmount::BINDING_DOMAIN;
    }

    /**
     * 결제 통화 금액을 주문 기본 통화로 환산합니다.
     *
     * 주문 시점 `currency_snapshot` 의 동결 환율을 쓴다 — 현재 환율로 환산하면 운영자가
     * 환율을 갱신할 때마다 과거 주문의 한도가 움직인다.
     *
     * 환산식은 `CurrencyConversionService` 의 정방향(`base ÷ base_unit × rate`)을 뒤집은
     * 것이다: `base = local ÷ rate × base_unit`.
     *
     * @param  float  $localAmount  결제 통화 금액
     * @param  string|null  $currency  결제 통화 코드
     * @param  Order  $order  대상 주문
     * @return float 기본 통화 금액
     */
    protected function toBaseCurrency(float $localAmount, ?string $currency, Order $order): float
    {
        $snapshot = $order->currency_snapshot ?? [];
        $baseCurrency = $this->resolveBaseCurrency($order);

        if ($currency === null || $currency === $baseCurrency || $snapshot === []) {
            return $localAmount;
        }

        $rateData = $snapshot['exchange_rates'][$currency] ?? null;

        if ($rateData === null) {
            return $localAmount;
        }

        $rate = is_numeric($rateData) ? (float) $rateData : (float) ($rateData['rate'] ?? 0);

        if ($rate <= 0) {
            return $localAmount;
        }

        return ($localAmount / $rate) * $this->currencyService->getSnapshotBaseUnit($snapshot);
    }

    /**
     * 주문의 기본 통화 코드를 반환합니다.
     *
     * @param  Order  $order  대상 주문
     * @return string 기본 통화 코드
     */
    /**
     * 되돌릴 대상이 없는 주문의 0 상한 결과를 반환합니다.
     *
     * 통화 해석은 이 클래스가 소유한다 — 호출부가 `currency_snapshot` 을 직접 읽으면
     * 스냅샷 없는 주문에서만 `currency: null` 이 나가, 같은 엔드포인트가 조건에 따라
     * 통화 코드를 주기도 안 주기도 하는 비대칭이 된다.
     *
     * @param  Order  $order  대상 주문
     * @return CancellableAmount 0 상한 결과
     */
    public function emptyFor(Order $order): CancellableAmount
    {
        return new CancellableAmount(
            currency: $this->resolveBaseCurrency($order),
            currencySnapshot: $this->currencySnapshotOf($order),
        );
    }

    /**
     * 주문 시점 통화 스냅샷을 반환합니다. (없으면 null)
     *
     * @param  Order  $order  대상 주문
     * @return array<string, mixed>|null 통화 스냅샷
     */
    protected function currencySnapshotOf(Order $order): ?array
    {
        $snapshot = $order->currency_snapshot ?? null;

        return is_array($snapshot) && $snapshot !== [] ? $snapshot : null;
    }

    protected function resolveBaseCurrency(Order $order): string
    {
        $snapshot = $order->currency_snapshot ?? [];

        return $snapshot['base_currency'] ?? $this->currencyService->getDefaultCurrency();
    }

    /**
     * 취소 가능 금액을 주문 시점 환율로 다통화 변환합니다.
     *
     * @param  Order  $order  대상 주문
     * @param  float  $amount  기본 통화 금액
     * @return array<string, float|int> 통화코드 → 금액
     */
    protected function convertToMultiCurrency(Order $order, float $amount): array
    {
        $snapshot = $order->currency_snapshot ?? [];

        if ($snapshot === []) {
            return [];
        }

        return $this->currencyService->convertToMultiCurrencyWithSnapshot($amount, $snapshot);
    }

    /**
     * 제외 대상 클레임 ID 목록을 반환합니다.
     *
     * @param  array<string, array<int>>  $excludeClaims  제외 목록 {type => [id, ...]}
     * @param  ClaimTypeEnum  $claimType  클레임 유형
     * @return array<int> ID 목록
     */
    protected function excludedIds(array $excludeClaims, ClaimTypeEnum $claimType): array
    {
        return array_values(array_map('intval', $excludeClaims[$claimType->value] ?? []));
    }
}
