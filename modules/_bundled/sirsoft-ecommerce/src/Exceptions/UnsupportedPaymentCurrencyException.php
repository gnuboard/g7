<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 미지원 결제 통화 예외
 *
 * 주문의 결제 통화(order_currency)가 PG 가 청구할 수 없는 통화일 때 발생합니다.
 * - 통화의 환율이 미설정/0 이하라 base 통화로 환산할 수 없는 경우
 * - 환산 결과가 0 이하라 PG 최소 청구금액을 만족하지 못하는 경우
 *
 * 결제 통화 결정은 정상(유저 영속 통화 등)이나 그 통화로 결제할 수 없는 상황을
 * 주문 생성 전에 명확한 422 로 차단하기 위한 예외입니다(서버 우회 방지).
 */
class UnsupportedPaymentCurrencyException extends Exception
{
    /**
     * 생성자
     *
     * @param  string  $currency  결제 통화 코드
     * @param  string  $message  다국어 처리된 예외 메시지
     */
    public function __construct(
        protected string $currency,
        string $message
    ) {
        parent::__construct($message);
    }

    /**
     * 결제 통화 코드를 반환합니다.
     *
     * @return string 결제 통화 코드
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }
}
