<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 옵션 주문 이력 존재 예외
 *
 * 삭제하려는 옵션에 주문 이력이 있을 때 발생합니다.
 */
class OptionHasOrderHistoryException extends Exception
{
    /**
     * @param  string|null  $message  예외 메시지
     */
    public function __construct(?string $message = null)
    {
        parent::__construct(
            $message ?? __('sirsoft-ecommerce::messages.options.has_order_history')
        );
    }
}
