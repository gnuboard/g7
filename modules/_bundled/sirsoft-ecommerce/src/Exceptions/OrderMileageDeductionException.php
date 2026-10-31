<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Throwable;

/**
 * 수동 결제 처리·재활성에서 회원 마일리지 잔액이 차감액보다 적을 때 (Q4·Q13·Q14)
 *
 * 상태 변경을 막고 사유를 안내한다. 일괄 변경은 한 건이라도 모자라면 전체를 바꾸지 않고 모자란
 * 주문번호를 모두 알린다. `MileageValidationException` 을 상속하므로 기존 `catch` 와도 호환된다.
 * 컨트롤러는 `getMessageKey()`·`getMessageParams()` 로 응답을 만든다(번역된 원문을 키 자리에 넣지 않는다).
 */
class OrderMileageDeductionException extends MileageValidationException
{
    /**
     * @param  string  $messageKey  다국어 키
     * @param  array<string, mixed>  $replace  치환값
     * @param  array<int, string>  $orderNumbers  모자란 주문번호
     */
    private function __construct(
        private string $messageKey,
        private array $replace = [],
        private array $orderNumbers = [],
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 단건 — 한 주문의 차감액이 잔액보다 클 때
     *
     * @param  Order  $order  주문
     * @param  int  $amount  차감할 마일리지
     * @param  string  $currency  기준 통화
     * @param  Throwable|null  $previous  원 예외 (기록용)
     * @return self 예외
     */
    public static function insufficientFor(Order $order, int $amount, string $currency, ?Throwable $previous = null): self
    {
        return new self(
            'sirsoft-ecommerce::exceptions.mileage.insufficient_for_payment_complete',
            ['order_number' => (string) $order->order_number, 'amount' => ecommerce_format_price($amount, $currency)],
            [(string) $order->order_number],
        );
    }

    /**
     * 일괄 — 모자란 주문이 하나 이상일 때
     *
     * @param  array<int, string>  $orderNumbers  모자란 주문번호
     * @return self 예외
     */
    public static function insufficientForBulk(array $orderNumbers): self
    {
        return new self(
            'sirsoft-ecommerce::exceptions.mileage.insufficient_for_payment_complete_bulk',
            ['order_numbers' => implode(', ', $orderNumbers), 'count' => count($orderNumbers)],
            array_values($orderNumbers),
        );
    }

    /**
     * 다국어 키를 돌려줍니다.
     *
     * @return string 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 치환값을 돌려줍니다.
     *
     * @return array<string, mixed> 치환값
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }

    /**
     * 모자란 주문번호를 돌려줍니다.
     *
     * @return array<int, string> 주문번호
     */
    public function getOrderNumbers(): array
    {
        return $this->orderNumbers;
    }
}
