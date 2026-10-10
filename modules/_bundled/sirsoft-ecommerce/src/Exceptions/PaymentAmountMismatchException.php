<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 결제 금액 불일치 예외
 *
 * 프론트엔드 결제예정금액과 서버 재계산 금액이 불일치할 때 발생합니다.
 */
class PaymentAmountMismatchException extends Exception
{
    /**
     * @param  float  $expectedAmount  프론트엔드 결제예정금액 (결제 승인 검증에서는 결제예정 청구액)
     * @param  float  $actualAmount  서버 재계산 금액 (결제 승인 검증에서는 받은 금액)
     * @param  array  $context  추가 컨텍스트 정보 (주문번호, 사용자ID 등)
     * @param  string|null  $currencyCode  두 금액의 통화 (null 이면 기본 통화) — 결제 통화로 비교한 금액을 기본 통화로 찍지 않도록 넘긴다
     */
    public function __construct(
        private float $expectedAmount,
        private float $actualAmount,
        private array $context = [],
        ?string $currencyCode = null
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.payment_amount_mismatch', [
                'expected' => ecommerce_format_price($expectedAmount, $currencyCode),
                'actual' => ecommerce_format_price($actualAmount, $currencyCode),
            ])
        );
    }

    /**
     * 프론트엔드 결제예정금액 반환
     *
     * @return float
     */
    public function getExpectedAmount(): float
    {
        return $this->expectedAmount;
    }

    /**
     * 서버 재계산 금액 반환
     *
     * @return float
     */
    public function getActualAmount(): float
    {
        return $this->actualAmount;
    }

    /**
     * 금액 차이 반환
     *
     * @return float
     */
    public function getDifference(): float
    {
        return abs($this->expectedAmount - $this->actualAmount);
    }

    /**
     * 컨텍스트 정보 반환
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 로깅용 전체 데이터 반환
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'expected_amount' => $this->expectedAmount,
            'actual_amount' => $this->actualAmount,
            'difference' => $this->getDifference(),
            'context' => $this->context,
        ];
    }
}
