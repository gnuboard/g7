<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\Popup\Services\PopupHtmlSanitizer;

/**
 * 직접 작성형 본문 검증 — 내용을 적은 언어의 본문이 저장 때 정화를 거치고 나서도 남는지 본다.
 *
 * 허용하지 않는 요소(`iframe`·`script`·`form` 등)만 있는 본문은 필수값 검사를 통과해도 정화 뒤 빈 문자열이 되어
 * 그 언어 방문자에게 빈 팝업이 뜬다. 저장 때와 같은 정화기로 미리 걸러 본다.
 */
class SanitizedBodyNotEmpty implements ValidationRule
{
    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  언어별 본문 (언어 => HTML)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $sanitizer = app(PopupHtmlSanitizer::class);
        foreach ($value as $locale => $html) {
            if (is_string($html) && trim($html) !== '' && $sanitizer->sanitize($html) === '') {
                $fail(__('sirsoft-popup::validation.body_html.emptied_by_sanitize', ['locale' => $locale]));

                return;
            }
        }
    }
}
