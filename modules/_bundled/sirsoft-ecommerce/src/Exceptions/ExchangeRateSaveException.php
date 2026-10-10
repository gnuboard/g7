<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/** 환율 저장 실패를 호출자에게 전달하여 성공 이력 생성을 방지합니다. */
class ExchangeRateSaveException extends RuntimeException
{
    /** 번역된 저장 실패 메시지로 예외를 생성합니다. */
    public function __construct()
    {
        parent::__construct(__($this->getMessageKey()));
    }

    /** @return string 저장 실패 번역 키 */
    public function getMessageKey(): string
    {
        return 'sirsoft-ecommerce::exceptions.exchange_rate_save_failed';
    }
}
