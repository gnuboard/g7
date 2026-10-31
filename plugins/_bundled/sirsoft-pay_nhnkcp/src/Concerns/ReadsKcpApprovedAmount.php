<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNhnkcp\Concerns;

/**
 * KCP 서버 승인 응답에서 PG 가 실제로 승인한 금액을 읽습니다.
 *
 * 결제완료·가상계좌 발급 기록의 금액 근거는 서버-서버 CLI 승인 응답의 `amount` 하나다.
 * 브라우저 콜백의 `good_mny` 는 요청자가 고른 값이라 여기서 읽지 않고, 호출부도 그 값을
 * 대체값으로 쓰지 않는다. 주문 승인·가상계좌 발급·클레임 추가결제가 같은 판정을 쓰도록
 * 한 곳에 둔다.
 */
trait ReadsKcpApprovedAmount
{
    /**
     * CLI 승인 응답의 승인 금액을 정수로 돌려줍니다.
     *
     * 값이 없거나, 숫자 문자열이 아니거나, 0 이하이면 null 이다. 호출부는 null 을 "승인 금액을
     * 확인할 수 없음" 으로 보고 금액 불일치와 똑같이 승인분을 취소하고 거절한다.
     *
     * @param  array  $pgResponse  KCP CLI 승인 응답
     * @return int|null 승인 금액 (확인할 수 없으면 null)
     */
    protected function kcpApprovedAmount(array $pgResponse): ?int
    {
        $raw = $pgResponse['amount'] ?? null;

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        // KCP 는 금액을 원 단위 정수 문자열로 준다 — 소수점·부호·공백이 섞이면 신뢰하지 않는다.
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        $amount = (int) $raw;

        return $amount > 0 ? $amount : null;
    }
}
