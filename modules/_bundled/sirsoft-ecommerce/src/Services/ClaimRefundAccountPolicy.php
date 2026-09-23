<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;

/**
 * 클레임 환불 계좌 요건 판정 (접수·수정·실행·표시 공용 단일 출처)
 *
 * "이 환불은 보낼 계좌가 있어야 끝나는가" 를 네 자리가 묻는다 — 구매자 신청(`Store*Request`)·
 * 수정(`UpdateClaimRequest`)·운영자 환불 실행(`ExecuteRefundRequest`)·상세 표시
 * (`ClaimResource::refund_account_required`). 한 자리만 판정이 달라지면 「칸은 없는데 서버가 막는다」
 * 또는 「칸은 있는데 빈 채로 완료된다」가 오류 없이 생긴다.
 *
 * 주문 축의 술어는 환불 도메인이 소유한다(`OrderRefundRequestService::refundsToBankAccount()` —
 * 사람이 송금하는 수단 + 입금된 가상계좌). 여기에 **실행 수단 축**을 더한다: 카드 주문이라도
 * 운영자가 계좌 수동 환불(`BANK`)을 고르면 그 돈은 계좌로 나가므로 계좌가 필요하다.
 */
class ClaimRefundAccountPolicy
{
    /**
     * @param  OrderRefundRequestService  $refundRequestService  환불 도메인 서비스 (주문 축 술어)
     */
    public function __construct(
        protected OrderRefundRequestService $refundRequestService,
    ) {}

    /**
     * 주문의 환불이 계좌로 돌아가는지 반환합니다. (주문 축 — 구매자 신청 화면과 같은 술어)
     *
     * @param  Order  $order  주문
     * @return bool 계좌로 돌아가면 true
     */
    public function orderRefundsToBankAccount(Order $order): bool
    {
        return $this->refundRequestService->refundsToBankAccount($order);
    }

    /**
     * 주문 시점에 등록해 둔 환불 계좌 원문을 반환합니다. (세 값이 모두 있을 때만)
     *
     * @param  Order  $order  주문
     * @return array{bank_code: string, account_number: string, holder: string}|null 등록 계좌
     */
    public function registeredAccount(Order $order): ?array
    {
        $order->loadMissing('payment');
        $payment = $order->payment;

        if ($payment === null) {
            return null;
        }

        $bankCode = trim((string) ($payment->refund_bank_code ?? ''));
        $account = trim((string) ($payment->refund_bank_account ?? ''));
        $holder = trim((string) ($payment->refund_bank_holder ?? ''));

        if ($bankCode === '' || $account === '' || $holder === '') {
            return null;
        }

        return ['bank_code' => $bankCode, 'account_number' => $account, 'holder' => $holder];
    }

    /**
     * 구매자 신청에 계좌가 필요한데 비어 있는지 반환합니다. (반품·교환·취소·환불 접수 공용)
     *
     * 돈이 계좌로 돌아가는 주문인데 요청에도 계좌가 없고, 주문 시점 등록 계좌도 없으면 true 다.
     * 돌려줄 돈이 있는지는 호출자가 판정한다(교환은 차액이 있을 때만 돈이 돌아간다).
     *
     * @param  Order  $order  주문
     * @param  array  $submitted  요청에 실린 계좌 (`bank_code` / `account_number` / `holder`)
     * @return bool 계좌가 필요한데 없으면 true
     */
    public function missingForRequest(Order $order, array $submitted): bool
    {
        if (! $this->orderRefundsToBankAccount($order)) {
            return false;
        }

        if (filled($submitted['bank_code'] ?? null)) {
            return false;
        }

        $order->loadMissing('payment');

        return ! filled($order->payment?->refund_bank_code);
    }

    /**
     * 이 환불신청서를 실행하려면 보낼 계좌가 있어야 하는지 반환합니다. (실행·표시 공용)
     *
     * 세 조건을 함께 본다.
     *  ⓐ 이미 신청서에 계좌가 적혀 있으면 요구하지 않는다.
     *  ⓑ 계좌로 나갈 **현금**이 있는가 — 마일리지로만 돌려주는 환불은 회원 원장으로 들어간다.
     *  ⓒ 그 현금이 계좌로 나가는가 — 실행 수단이 `BANK` 면(카드 주문의 계좌 수동 환불 포함) 그렇고,
     *     `POINTS` 면 아니며, 그 밖(PG·미지정)은 주문 축 술어(입금된 가상계좌 등)가 정한다.
     *
     * @param  OrderRefund  $refund  환불신청서
     * @param  float|null  $cashOverride  실행 본문의 현금 환불액 (없으면 저장값)
     * @param  RefundMethodEnum|null  $methodOverride  실행 본문의 환불 수단 (없으면 저장값)
     * @return bool 계좌가 있어야 실행되면 true
     */
    public function requiredForRefund(OrderRefund $refund, ?float $cashOverride = null, ?RefundMethodEnum $methodOverride = null): bool
    {
        if (filled($refund->refund_bank_code)) {
            return false;
        }

        $cash = $cashOverride ?? (float) $refund->refund_amount;

        if ($cash <= 0) {
            return false;
        }

        $method = $methodOverride ?? $refund->refund_method;

        if ($method === RefundMethodEnum::BANK) {
            return true;
        }

        if ($method === RefundMethodEnum::POINTS) {
            return false;
        }

        $order = $refund->order;

        return $order !== null && $this->orderRefundsToBankAccount($order);
    }
}
