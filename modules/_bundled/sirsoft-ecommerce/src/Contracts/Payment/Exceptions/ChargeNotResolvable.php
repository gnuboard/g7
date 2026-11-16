<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions;

use InvalidArgumentException;
use Throwable;

/**
 * 주문의 결제 통화로 청구액을 계산할 수 없을 때(환율 없음 등) 던지는 공개 예외
 *
 * `InvalidArgumentException` 하위라서 그 형으로 잡던 기존 코드도 그대로 잡힌다.
 *
 * @since 1.4.0
 */
class ChargeNotResolvable extends InvalidArgumentException
{
    /**
     * 내부 통화 환산 예외를 감싸 만듭니다.
     *
     * @param  Throwable  $previous  원래 예외
     * @return self 예외
     */
    public static function from(Throwable $previous): self
    {
        return new self($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
