<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Concerns;

/**
 * 토스 응답에서 실제 승인 금액을 읽는 공통 절차.
 *
 * 결제 성립의 금액 근거는 토스가 승인(또는 발급)한 `totalAmount` 와 주문에 저장된 청구액
 * 두 가지뿐이다. 브라우저가 쿼리스트링으로 보낸 `amount` 는 누구나 바꿀 수 있으므로 어느
 * 쪽의 대체값으로도 쓰지 않는다 — 응답에 금액이 없을 때 콜백 금액으로 채우면 대조가 항등식이 된다.
 */
trait VerifiesTossApprovedAmount
{
    /**
     * 토스 응답의 `totalAmount` 를 정수로 읽습니다.
     *
     * 값이 없거나 숫자가 아니거나 정수로 떨어지지 않으면 null 을 반환한다. null 은 「검증할 수
     * 없음」이므로 호출자는 불일치와 같게 거절해야 한다.
     *
     * @param  array<string, mixed>  $response  토스 승인·조회 응답
     * @return int|null 승인 금액, 읽을 수 없으면 null
     */
    protected function tossApprovedAmount(array $response): ?int
    {
        $total = $response['totalAmount'] ?? null;

        if (! is_numeric($total)) {
            return null;
        }

        $amount = (int) $total;

        return (float) $amount === (float) $total ? $amount : null;
    }
}
