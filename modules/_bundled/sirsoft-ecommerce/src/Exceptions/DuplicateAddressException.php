<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 중복 배송지명 예외
 *
 * 동일한 이름의 배송지가 이미 존재할 때 발생합니다.
 */
class DuplicateAddressException extends Exception
{
    /**
     * @param int $duplicateAddressId 중복된 기존 배송지 ID
     */
    public function __construct(
        private int $duplicateAddressId
    ) {
        parent::__construct(__('sirsoft-ecommerce::messages.address.name_duplicate'));
    }

    /**
     * 중복된 기존 배송지 ID를 반환합니다.
     *
     * @return int 중복 배송지 ID
     */
    public function getDuplicateAddressId(): int
    {
        return $this->duplicateAddressId;
    }
}
