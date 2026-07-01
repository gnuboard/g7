<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 상품 주문 이력 존재 예외
 *
 * 삭제하려는 상품에 주문 이력이 있을 때 발생합니다.
 */
class ProductHasOrderHistoryException extends Exception
{
    /**
     * @param  int  $count  주문 이력 건수
     * @param  string|null  $message  예외 메시지
     */
    public function __construct(int $count = 0, ?string $message = null)
    {
        parent::__construct(
            $message ?? __('sirsoft-ecommerce::messages.products.has_order_history', ['count' => $count])
        );
    }
}
