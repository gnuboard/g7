<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 주문 재계산 금액 변동 예외
 *
 * 체크아웃 시점과 주문 생성 시점 사이에 가격/쿠폰/배송비가 변경되어
 * 최종 결제금액이 달라졌을 때 발생합니다.
 */
class OrderAmountChangedException extends Exception
{
    /**
     * @param  int  $storedAmount  체크아웃 시점 저장 금액
     * @param  int  $recalculatedAmount  주문 시점 재계산 금액
     */
    public function __construct(
        private int $storedAmount,
        private int $recalculatedAmount
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.order_amount_changed', [
                'stored' => number_format($storedAmount),
                'recalculated' => number_format($recalculatedAmount),
            ])
        );
    }

    /**
     * 체크아웃 시점 저장 금액 반환
     *
     * @return int
     */
    public function getStoredAmount(): int
    {
        return $this->storedAmount;
    }

    /**
     * 주문 시점 재계산 금액 반환
     *
     * @return int
     */
    public function getRecalculatedAmount(): int
    {
        return $this->recalculatedAmount;
    }

    /**
     * 금액 차이 반환
     *
     * @return int
     */
    public function getDifference(): int
    {
        return abs($this->storedAmount - $this->recalculatedAmount);
    }

    /**
     * 로깅용 전체 데이터 반환
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'stored_amount' => $this->storedAmount,
            'recalculated_amount' => $this->recalculatedAmount,
            'difference' => $this->getDifference(),
        ];
    }
}
