<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;

/**
 * 현재 로그인된 사용자를 ID 목록에서 제외하는 Custom Rule
 *
 * 일괄 상태 변경 등 자기 자신에 대한 작업을 방지합니다.
 */
class ExcludeCurrentUser implements ValidationRule
{
    /**
     * 검증 수행
     *
     * @param  string  $attribute  검증 대상 속성명
     * @param  mixed  $value  검증 대상 값 (사용자 ID 배열)
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $currentUserUuid = Auth::user()?->uuid;

        if ($currentUserUuid === null) {
            return;
        }

        if (in_array($currentUserUuid, $value, true)) {
            $fail(__('validation.exclude_current_user'));
        }
    }
}
