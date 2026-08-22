<?php

namespace App\Rules;

use App\Support\OutboundProxy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 아웃바운드 프록시 URL 이 적용 가능한 형태인지 검증하는 Custom Rule.
 *
 * 허용 스킴 목록은 {@see OutboundProxy::ALLOWED_SCHEMES} 가 소유한다 — 저장 시점 검증과
 * 실제 적용 판정이 서로 다른 목록을 보면 "저장은 됐는데 적용되지 않는" 상태가 조용히 생긴다.
 *
 * 빈 값은 통과시킨다. 프록시를 쓰지 않는 것이 기본 상태이며, 필수 여부는 FormRequest 의
 * nullable 규칙이 정한다.
 *
 * @since 7.0.8
 */
class ValidOutboundProxyUrl implements ValidationRule
{
    /**
     * 값이 적용 가능한 프록시 URL 인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail(__('validation.settings.outbound_proxy_invalid', [
                'schemes' => implode(', ', OutboundProxy::ALLOWED_SCHEMES),
            ]));

            return;
        }

        if (! OutboundProxy::isValidUrl($value)) {
            $fail(__('validation.settings.outbound_proxy_invalid', [
                'schemes' => implode(', ', OutboundProxy::ALLOWED_SCHEMES),
            ]));
        }
    }
}
