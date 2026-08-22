<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 장바구니 단건 조작 예외 (항목없음/권한없음/옵션없음/타상품옵션)
 *
 * reason 에 따라 HTTP 상태코드와 메시지 키를 결정한다.
 * 컨트롤러가 catch 하여 generic 500 대신 사유별 4xx(404/403/422)로 매핑한다.
 * (체크아웃 전용 CartUnavailableException 과는 의미가 다른 별도 클래스 — MP07 §1-c)
 */
class CartOperationException extends Exception
{
    /**
     * @param  string  $reason  사유 (item_not_found|access_denied|option_not_found|invalid_option)
     */
    public function __construct(private string $reason)
    {
        parent::__construct(__($this->getMessageKey()));
    }

    /**
     * 사유 문자열을 반환합니다.
     *
     * @return string 사유 코드
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * 사유별 HTTP 상태코드를 반환합니다.
     *
     * @return int 404(항목없음) / 403(권한없음) / 422(옵션없음·타상품옵션)
     */
    public function getStatusCode(): int
    {
        return match ($this->reason) {
            'item_not_found' => 404,
            'access_denied' => 403,
            default => 422, // option_not_found, invalid_option
        };
    }

    /**
     * 사유별 다국어 메시지 키를 반환합니다. (전부 기존 키 — 신규 추가 없음)
     *
     * @return string lang 키
     */
    public function getMessageKey(): string
    {
        return match ($this->reason) {
            'item_not_found' => 'sirsoft-ecommerce::exceptions.cart_item_not_found',
            'access_denied' => 'sirsoft-ecommerce::exceptions.cart_access_denied',
            'invalid_option' => 'sirsoft-ecommerce::exceptions.invalid_option_for_product',
            default => 'sirsoft-ecommerce::exceptions.option_not_found',
        };
    }
}
