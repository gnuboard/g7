<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use InvalidArgumentException;

/**
 * 통화 환산 불가 예외
 *
 * 환산에 필요한 값이 설정에 없거나 쓸 수 없을 때 발생합니다.
 * - 대상 통화가 통화 설정에 없는 경우
 * - 환율이 미설정이거나 0 이하라 환산식이 성립하지 않는 경우
 *
 * `InvalidArgumentException` 을 상속한다 — 이 예외를 던지던 자리는 종전에 베이스
 * `InvalidArgumentException` 을 직접 던졌고, 호출부 세 곳
 * (`ClaimNotificationDataListener` · `EcommerceNotificationDataListener` ·
 * `OrderProcessingService`)과 결제 플러그인 네 곳이 그 타입으로 잡고 있다. 상속을
 * 유지해야 그 catch 와 기존 계약이 그대로 성립한다.
 *
 * 번역문이 아니라 **메시지 키와 치환 파라미터**를 보관한다(`BrandOperationException` 등
 * 형제 예외와 동일). 이미 번역된 문장을 응답의 메시지 키 자리로 넘기면 키 해석에 실패해
 * 원문이 그대로 화면에 나가므로, 응답을 만드는 쪽이 키로 다시 구성할 수 있어야 한다.
 */
class CurrencyConversionException extends InvalidArgumentException
{
    /**
     * CurrencyConversionException 생성자
     *
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-ecommerce::exceptions.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터 (통화 코드는 `currency` 키)
     */
    public function __construct(
        private string $messageKey,
        private array $replace = []
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }

    /**
     * 환산 대상 통화 코드를 반환합니다.
     *
     * @return string 통화 코드 (치환 파라미터에 없으면 빈 문자열)
     */
    public function getCurrency(): string
    {
        return (string) ($this->replace['currency'] ?? '');
    }
}
