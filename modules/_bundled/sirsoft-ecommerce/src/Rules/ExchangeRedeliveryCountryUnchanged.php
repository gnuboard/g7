<?php

namespace Modules\Sirsoft\Ecommerce\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;

/**
 * 교환 재발송지의 국가가 주문 배송지와 같은지 검증하는 규칙
 *
 * 교환은 해외배송을 지원하지 않는다(왕복 실익 없음 — 이슈 원문 고정 요구). 재발송지는
 * 우편번호·상세주소만 바꿀 수 있고(D14 도서산간 재판정), 국가가 바뀌면 배송정책 자체가
 * 달라져 왕복 배송비를 원 정책으로 재산정할 근거가 사라진다.
 *
 * 기준 국가는 서비스(`OrderExchangeService`)와 같은 출처 — 주문 생성 시점에 보존된 배송정책
 * 스냅샷의 배송지, 없으면 주문 주소 행 — 에서 읽는다. 두 판정이 다른 출처를 보면 검증은
 * 통과하고 서비스만 거절하는(혹은 그 반대) 비대칭이 생긴다.
 *
 * 같은 검증을 서비스가 한 번 더 수행한다(§4-10 "FormRequest+Service"). FormRequest 는 화면
 * 요청의 1차 방어이고, 서비스 가드는 검증을 거치지 않는 내부·훅 호출까지 막는다.
 *
 * 주문은 모델 또는 **해석 클로저**로 받는다. 클로저로 받으면 조회는 규칙 목록을 만드는 시점이
 * 아니라 이 규칙이 실제로 실행되는 검증 시점에 일어난다 — 다른 FormRequest 들이 주문을
 * 검증 후 단계에서만 조회하는 것과 시점을 맞추고, 국가 필드가 비어 규칙이 실행되지 않으면
 * 조회 자체가 없다.
 */
class ExchangeRedeliveryCountryUnchanged implements ValidationRule
{
    /**
     * @param  Order|Closure|null  $order  대상 주문, 또는 주문을 돌려주는 클로저 (`fn (): ?Order`).
     *                                     해석 결과가 null 이면 판정하지 않는다 — 주문 부재는 상위가 404 로 판정한다
     */
    public function __construct(
        private Order|Closure|null $order = null,
    ) {}

    /**
     * 검증을 수행합니다.
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  재발송지 국가 코드
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // 국가를 비우면 주문 배송지 국가를 그대로 쓴다 — 변경이 아니다.
        if ($value === null || $value === '') {
            return;
        }

        $order = $this->resolveOrder();

        if ($order === null) {
            return;
        }

        $expected = self::orderShippingCountry($order);

        if (strtoupper(trim((string) $value)) !== $expected) {
            $fail(__('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_unchanged'));
        }
    }

    /**
     * 대상 주문을 해석합니다. (클로저면 지금 호출)
     *
     * @return Order|null 주문
     */
    protected function resolveOrder(): ?Order
    {
        if ($this->order instanceof Closure) {
            $this->order = ($this->order)();
        }

        return $this->order;
    }

    /**
     * 주문 배송지 국가 코드를 반환합니다. (서비스 가드와 공유하는 단일 판정)
     *
     * @param  Order  $order  주문
     * @return string 대문자 국가 코드 (미상이면 KR)
     */
    public static function orderShippingCountry(Order $order): string
    {
        $address = ShippingPolicySnapshot::deliveryAddress(
            $order->shipping_policy_applied_snapshot ?? [],
            function () use ($order) {
                $shippingAddress = $order->shippingAddress;

                return [
                    'country_code' => $shippingAddress?->recipient_country_code,
                    'zipcode' => $shippingAddress?->zipcode ?: $shippingAddress?->intl_postal_code,
                ];
            }
        );

        return $address['country_code'];
    }
}
