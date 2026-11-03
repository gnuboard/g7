<?php

namespace Plugins\Sirsoft\Popup\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Plugins\Sirsoft\Popup\Support\RoutePath;

/**
 * 노출 페이지 경로 한 개를 검증한다 — 규칙은 `RoutePath::isValid()`(사용자 레이어 판정과 같은 정규화).
 */
class PopupRoute implements ValidationRule
{
    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  필드명
     * @param  mixed  $value  검증 값 (정규화된 경로)
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! RoutePath::isValid($value)) {
            $fail(__('sirsoft-popup::validation.routes.invalid', ['route' => is_string($value) ? $value : '']));
        }
    }
}
