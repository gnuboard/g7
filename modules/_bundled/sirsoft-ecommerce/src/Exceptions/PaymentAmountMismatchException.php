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
     * @param float $expectedAmount 프론트엔드 결제예정금액
     * @param float $actualAmount 서버 재계산 금액
     * @param array $context 추가 컨텍스트 정보 (주문번호, 사용자ID 등)
     */
    public function __construct(
        private float $expectedAmount,
        private float $actualAmount,
        private array $context = []
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.payment_amount_mismatch', [
                'expected' => number_format($expectedAmount),
                'actual' => number_format($actualAmount),
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
