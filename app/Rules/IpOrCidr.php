<?php

namespace App\Rules;

use App\Support\IpAccessMatcher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * IP 접근 제한 허용 목록의 항목이 IP 또는 CIDR 인지 검증하는 Custom Rule.
 *
 * 형식 판정은 {@see IpAccessMatcher::isValidEntry()} 가 소유한다 — 저장 시점 검증과 실제 차단
 * 판정이 서로 다른 기준을 보면 "저장은 됐는데 아무도 통과시키지 못하는" 항목이 조용히 생긴다.
 *
 * @since 7.1.0
 */
class IpOrCidr implements ValidationRule
{
    /**
     * 값이 IP(IPv4·IPv6) 또는 CIDR 표기인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! IpAccessMatcher::isValidEntry($value)) {
            $fail(__('validation.settings.ip_or_cidr_invalid', [
                'value' => is_scalar($value) ? (string) $value : '',
            ]));
        }
    }
}
