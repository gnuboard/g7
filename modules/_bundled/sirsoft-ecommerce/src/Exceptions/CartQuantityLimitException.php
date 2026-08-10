<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 장바구니 수량 상한 초과 예외
 *
 * `config('sirsoft-ecommerce.cart.max_quantity')` 를 초과하는 라인 수량을 만들려 할 때 발생합니다.
 * 필드 규칙(`max:`)이 이번 요청분만 검사하는 것과 달리, 기존 수량과 합산되는 경로를 막습니다.
 */
class CartQuantityLimitException extends Exception
{
    /**
     * @param  int  $limit  허용 최대 수량
     * @param  int  $attempted  합산 후 수량
     */
    public function __construct(
        private int $limit,
        private int $attempted,
    ) {
        parent::__construct(__('sirsoft-ecommerce::validation.cart.quantity_limit_exceeded', [
            'limit' => $limit,
            'attempted' => $attempted,
        ]));
    }

    /**
     * 허용 최대 수량을 반환합니다.
     *
     * @return int 최대 수량
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * 합산 후 수량을 반환합니다.
     *
     * @return int 시도 수량
     */
    public function getAttempted(): int
    {
        return $this->attempted;
    }
}
