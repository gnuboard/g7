<?php

namespace App\Rules;

use App\Support\TrustedProxySetting;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 신뢰 프록시 태그 하나가 적용 가능한 주소인지 검증하는 Custom Rule.
 *
 * 판정식은 {@see TrustedProxySetting::isValidToken()} 이 소유한다 — 저장 검증과 실제 적용이
 * 서로 다른 기준을 보면 "저장은 됐는데 아무 것도 매칭되지 않는" 목록이 조용히 남는다.
 * 이 설정은 틀려도 오류를 내지 않고 방문자 IP 만 잘못 기록되므로, 그 상태를 알아차릴
 * 단서가 없다.
 *
 * 빈 값은 통과시킨다 — 목록 자체가 비는 것(「신뢰하지 않음」)은 정상 상태이며, 빈 태그는
 * {@see TrustedProxySetting::toList()} 가 저장 전에 걷어낸다.
 *
 * @since 7.1.0
 */
class ValidTrustedProxyAddress implements ValidationRule
{
    /**
     * 값이 신뢰 프록시로 쓸 수 있는 주소인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! TrustedProxySetting::isValidToken($value)) {
            $fail(__('validation.settings.trusted_proxy_address_invalid'));
        }
    }
}
