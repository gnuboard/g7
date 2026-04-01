<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;

/**
 * 결제 서비스 (더미 클래스)
 *
 * 추후 PG 연동 시 구현될 서비스입니다.
 * 현재는 인터페이스만 정의되어 있습니다.
 *
 * 책임:
 * - PG 결제 요청/승인/취소
 * - 결제 완료 시 금액 검증 (validatePaymentAmount)
 * - 가상계좌 입금 콜백 처리
 * - 무통장 수동 입금확인 처리
 *
 * @see OrderProcessingService::validateOrderAmount() 주문 생성 시 금액 검증은 여기서 처리
 */
class PaymentService
{
    /**
     * 결제 완료 시 금액 검증 (PG/가상계좌 콜백용)
     *
     * PG에서 콜백으로 받은 실제 결제금액과 주문의 결제예정금액을 비교합니다.
     *
     * @param Order $order 주문 모델
     * @param float $paidAmount PG에서 콜백으로 받은 실제 결제금액
     * @return bool 검증 성공 여부
     * @throws PaymentAmountMismatchException 금액 불일치 시
     *
     * @todo PG 연동 시 구현 필요
     */
    public function validatePaymentAmount(Order $order, float $paidAmount): bool
    {
        $expectedAmount = $order->total_due_amount;

        // 허용 오차 (소수점 반올림 오차 허용)
        $tolerance = 1.0;

        if (abs($expectedAmount - $paidAmount) > $tolerance) {
            throw new PaymentAmountMismatchException($expectedAmount, $paidAmount);
        }

        return true;
    }

    /**
     * PG 결제 요청
     *
     * @param Order $order 주문 모델
     * @param array $paymentData 결제 요청 데이터
     * @return array PG 응답 데이터
     *
     * @todo PG 연동 시 구현 필요
     */
    public function requestPayment(Order $order, array $paymentData): array
    {
        // TODO: PG 연동 시 구현
        return [];
    }

    /**
     * PG 결제 승인
     *
     * @param Order $order 주문 모델
     * @param string $transactionId PG 거래 ID
     * @return OrderPayment 결제 정보
     *
     * @todo PG 연동 시 구현 필요
     */
    public function approvePayment(Order $order, string $transactionId): OrderPayment
    {
        // TODO: PG 연동 시 구현
        return $order->payment;
    }

    /**
     * PG 결제 취소
     *
     * @param OrderPayment $payment 결제 모델
     * @param float $cancelAmount 취소 금액
     * @param string $reason 취소 사유
     * @return bool 취소 성공 여부
     *
     * @todo PG 연동 시 구현 필요
     */
    public function cancelPayment(OrderPayment $payment, float $cancelAmount, string $reason): bool
    {
        // TODO: PG 연동 시 구현
        return false;
    }

    /**
     * 가상계좌 입금 콜백 처리
     *
     * @param string $transactionId PG 거래 ID
     * @param float $amount 입금 금액
     * @return bool 처리 성공 여부
     *
     * @todo PG 연동 시 구현 필요
     */
    public function handleVbankCallback(string $transactionId, float $amount): bool
    {
        // TODO: PG 연동 시 구현
        return false;
    }

    /**
     * 무통장 수동 입금확인 처리
     *
     * @param Order $order 주문 모델
     * @param float $amount 입금 금액
     * @param string|null $depositorName 입금자명
     * @return bool 처리 성공 여부
     *
     * @todo PG 연동 시 구현 필요
     */
    public function confirmManualDeposit(Order $order, float $amount, ?string $depositorName = null): bool
    {
        // TODO: PG 연동 시 구현
        return false;
    }
}
