<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 마일리지 검증 예외
 *
 * 사용/차감 시 조건(잔액, 최소금액, 사용단위, 최대한도) 미충족 시 발생합니다.
 * 메시지는 다국어 키로 해석된 문자열을 전달받습니다.
 */
class MileageValidationException extends Exception
{
    /**
     * @param  string  $message  검증 실패 메시지 (다국어 해석 완료된 문자열)
     */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
