<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 팝업 링크 주소 검증 — 사이트 내부 경로(`/…`)와 http(s) 절대 주소만 받는다.
 *
 * `//` 로 시작하는 프로토콜 상대 주소와 브라우저가 같은 뜻으로 읽는 `/\`·`\\` 시작은 `/` 로 시작해도 외부 사이트로
 * 새므로 거부한다. `javascript:`·`data:` 등 다른 스킴, 공백·제어문자도 거부한다.
 */
class SafeLinkUrl implements ValidationRule
{
    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! self::isSafe($value)) {
            $fail(__('sirsoft-popup::validation.link_url.unsafe'));
        }
    }

    /**
     * 주소가 허용 형식인지 판정합니다.
     *
     * @param  string  $url  링크 주소
     * @return bool 허용이면 true
     */
    public static function isSafe(string $url): bool
    {
        // 공백·제어문자(탭·줄바꿈 포함)는 스킴 우회에 쓰이므로 통째로 거부
        if (preg_match('/[\s\x00-\x1F\x7F]/u', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            // `//host`·`/\host` 는 외부로 샌다
            return ! str_starts_with($url, '//') && ! str_starts_with($url, '/\\');
        }

        if (str_starts_with($url, '\\')) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_HOST) !== null;
    }
}
