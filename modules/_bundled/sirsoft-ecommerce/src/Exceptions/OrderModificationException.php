<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 주문 수정 예외
 *
 * 배송 전이 아니어서 배송지를 변경할 수 없거나 대상 주소를 찾을 수 없는 등
 * 주문 수정 과정에서 발생하는 비즈니스 예외입니다.
 */
class OrderModificationException extends Exception
{
    /**
     * 생성자
     *
     * @param  string  $message  다국어 처리된 예외 메시지
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
