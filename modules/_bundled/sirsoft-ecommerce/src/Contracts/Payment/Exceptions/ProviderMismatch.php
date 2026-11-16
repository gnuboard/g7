<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Exceptions;

use RuntimeException;

/**
 * 다른 결제사가 이미 결제(발급)한 결제 행을 쓰려 할 때 던지는 공개 예외
 *
 * 결제 전 상태의 행은 호출 결제사가 넘겨받을 수 있으므로 이 예외가 나지 않는다.
 *
 * @since 1.4.0
 */
class ProviderMismatch extends RuntimeException
{
    /**
     * 결제 행 소유 결제사와 호출 결제사가 다를 때의 예외를 만듭니다.
     *
     * @param  string|null  $owner  결제 행의 결제사 id
     * @param  string  $caller  호출 결제사 id
     * @return self 예외
     */
    public static function between(?string $owner, string $caller): self
    {
        return new self(__('sirsoft-ecommerce::exceptions.payment_contract.provider_mismatch', ['owner' => (string) $owner, 'caller' => $caller]));
    }
}
