<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CSP `script-src` 에 덧붙일 운영자 추가 호스트 형식을 검증하는 Custom Rule.
 *
 * 허용 형태는 호스트 소스 한 가지다 — `cdn.example.com`, `*.example.com`, `https://cdn.example.com`,
 * 포트 `cdn.example.com:8443`. 정책 문자열에 그대로 이어 붙이므로 따옴표·세미콜론·공백·키워드
 * (`'unsafe-inline'` 등)·스킴 소스(`data:`·`https:`)·`*` 단독·`http://` 는 받지 않는다 — 하나라도
 * 들어가면 정책이 무력해지거나(`'unsafe-inline'`, `*`) 다른 지시어가 끼어든다(`;`).
 *
 * @since 7.1.4
 */
class CspSourceHost implements ValidationRule
{
    /**
     * 허용 형식 (소문자화 후 대조).
     */
    private const PATTERN = '/^(https:\/\/)?(\*\.)?[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+(:\d{1,5})?$/';

    /**
     * 값이 CSP 호스트 소스 형식인지 판정합니다.
     *
     * @param  mixed  $value  검사 값
     * @return bool 허용 형식이면 true
     */
    public static function isValid(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 253) {
            return false;
        }

        return preg_match(self::PATTERN, strtolower($value)) === 1;
    }

    /**
     * 값이 CSP 호스트 소스 형식인지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('validation.settings.csp_source_host_invalid', [
                'value' => is_scalar($value) ? (string) $value : '',
            ]));
        }
    }
}
