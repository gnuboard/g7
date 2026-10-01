<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 브라우저 콜백으로 받은 PG 승인을 콜백이 가리키는 주문에 묶어도 되는지 판정한다.
 *
 * 결제 인증 콜백(PC `authCallback`·모바일 `mobile/callback`)은 PG 서명이 없는 브라우저 POST 이고,
 * 주문번호(MOID·P_OID·orderId)와 금액(TotPrice)도 요청자가 고른 값이다. 서버 승인 호출에 쓰는
 * 인증 토큰(authToken·P_TID)은 주문번호를 묶지 않으므로, 자기 결제창에서 얻은 승인 토큰에 남의
 * 주문번호를 붙여 보내면 그 승인이 남의 주문에 반영될 수 있다 (KVE-2026-2434 전수조사).
 *
 * - 승인 응답이 돌려준 주문번호가 콜백 주문번호와 다르면 반영하지 않는다.
 * - 금액은 PG 가 승인한 값이 기준이다 — 브라우저가 보낸 금액은 대조에 쓰지 않는다. 승인 응답에 금액이
 *   없거나 주문 청구액과 다르면 반영하지 않는다.
 * - 가상계좌 발급은 결제 전(결제대기) 주문에만 기록한다. 이미 결제됐거나 다른 가상계좌가 붙은
 *   결제 레코드를 덮어쓰면 환불 대상 거래번호가 오염된다.
 */
trait BindsApprovalToOrder
{
    /**
     * 승인 응답의 주문번호가 콜백 주문번호와 같은지 확인합니다.
     *
     * 응답에 주문번호가 없으면(모바일 표준 응답 등) 대조할 수 없으므로 통과시키고, 금액 대조가 방어한다.
     *
     * @param  string|null  $pgOrderNumber  승인 응답이 돌려준 주문번호
     * @param  string  $callbackOrderNumber  콜백이 가리키는 주문번호
     * @return bool 같거나 응답에 없으면 true
     */
    protected function approvalOrderNumberMatches(?string $pgOrderNumber, string $callbackOrderNumber): bool
    {
        if ($pgOrderNumber === null || $pgOrderNumber === '') {
            return true;
        }

        return hash_equals($pgOrderNumber, $callbackOrderNumber);
    }

    /**
     * 가상계좌 발급 결과를 이 주문에 기록해도 되는지 판정합니다.
     *
     * @param  Order  $order  콜백이 가리키는 주문
     * @param  int|null  $approvedAmount  PG 가 승인(발급)한 금액 (응답에 없으면 null — 기록 금지로 판정)
     * @param  string  $tid  발급 거래번호
     * @return string 'issue'(기록) · 'replay'(같은 발급의 중복 콜백 — 성공 처리만) · 'reject'(기록 금지)
     */
    protected function vbankIssueDecision(Order $order, ?int $approvedAmount, string $tid): string
    {
        $payment = $order->payment()->first();
        if (! $payment) {
            return 'reject';
        }

        if ($payment->payment_status === PaymentStatusEnum::WAITING_DEPOSIT
            && $tid !== ''
            && hash_equals((string) $payment->transaction_id, $tid)) {
            return 'replay';
        }

        if (! $order->order_status->isBeforePayment() || $payment->payment_status !== PaymentStatusEnum::READY) {
            return 'reject';
        }

        // 발급 금액은 PG 승인 응답의 값으로만 판정한다. 응답에 금액이 없으면 대조할 근거가 없으므로
        // 브라우저가 보낸 금액으로 채우지 않고 발급을 기록하지 않는다. 청구액이 0 이어도 대조를 건너뛰지
        // 않는다 — PG 결제가 필요 없는 주문에 발급이 들어온 것 자체가 불일치다.
        if ($approvedAmount === null) {
            return 'reject';
        }

        $expectedAmount = $this->resolveExpectedPaymentPriceOrNull($order, 'vbank_issue', [
            'tid' => $tid,
            'received_amount' => $approvedAmount,
        ]);
        if ($expectedAmount === null || $approvedAmount !== $expectedAmount) {
            return 'reject';
        }

        return 'issue';
    }
}
