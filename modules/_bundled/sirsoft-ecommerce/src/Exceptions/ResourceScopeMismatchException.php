<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 하위 리소스가 요청 경로의 상위 리소스에 속하지 않을 때 발생하는 예외
 *
 * 주문 옵션이 다른 주문에 속하거나, 상품 이미지가 다른 상품에 속하는 등
 * 중첩 리소스의 소유권이 어긋난 경우에 사용합니다.
 * 컨트롤러의 기존 catch (\Exception) 흐름에서 400 응답으로 처리됩니다.
 */
class ResourceScopeMismatchException extends RuntimeException
{
    /**
     * ResourceScopeMismatchException 생성자
     *
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-ecommerce::exceptions.*)
     */
    public function __construct(string $messageKey)
    {
        parent::__construct(__($messageKey));
    }
}
