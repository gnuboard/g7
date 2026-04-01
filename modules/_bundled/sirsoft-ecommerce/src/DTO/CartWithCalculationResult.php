<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Illuminate\Database\Eloquent\Collection;

/**
 * 장바구니 목록과 주문 계산 결과를 함께 담는 DTO
 */
class CartWithCalculationResult
{
    public function __construct(
        /**
         * 장바구니 아이템 컬렉션
         */
        public readonly Collection $items,

        /**
         * 주문 계산 결과 (가격, 할인, 배송비 등)
         */
        public readonly OrderCalculationResult $calculation
    ) {}

    /**
     * 장바구니가 비어있는지 확인
     *
     * @return bool 비어있으면 true
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * 장바구니 아이템 수 조회
     *
     * @return int 아이템 수
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * 배열로 변환
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items->toArray(),
            'calculation' => $this->calculation->toArray(),
        ];
    }
}
