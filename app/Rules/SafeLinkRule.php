<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 운영자가 입력하는 링크 주소 검증 (#434 템플릿 설정 `link` 칸)
 *
 * 받는 것: 사이트 안 경로(`/` 로 시작, `//` 로 시작하는 프로토콜 상대 주소 제외) 또는 `http`·`https` 주소.
 * 거부: `javascript:`·`data:` 등 다른 스킴, 공백·제어 문자, 2048자 초과. 빈 문자열은 `required` 가 판정한다.
 */
class SafeLinkRule implements ValidationRule
{
    /**
     * 최대 길이
     */
    private const MAX_LENGTH = 2048;

    /**
     * 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || mb_strlen($value) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/u', $value) === 1) {
            $fail(__('validation.safe_link'));

            return;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail(__('validation.safe_link'));
        }
    }
}
