<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * 새 비밀번호가 현재 비밀번호와 달라야 한다는 규칙 (로그인 변경 단계 전용)
 *
 * 재사용 금지 설정과 무관하게 항상 적용한다 — 같은 값으로 "변경" 하면 만료·임시 비밀번호
 * 강제 변경이 무력화된다. 재사용 규칙과 같은 이유로 본인 증명(challenge) 뒤에만 평가한다.
 *
 * @since 7.1.0
 */
class PasswordDifferentFromCurrent implements ValidationRule
{
    /**
     * @param  User  $user  비밀번호를 바꾸는 회원
     */
    public function __construct(private readonly User $user) {}

    /**
     * 새 비밀번호가 현재 비밀번호와 같은지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $current = $this->user->getRawOriginal('password');

        if (is_string($current) && $current !== '' && Hash::check($value, $current)) {
            $fail(__('validation.password_policy.same_as_current'));
        }
    }
}
