<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 비밀번호 조합 규칙 — 영문·숫자·특수문자 중 2종류 이상 (ISMS-P 2.5.4)
 *
 * `security.password_require_char_types` 가 꺼져 있으면 통과한다. 특수문자 집합은 Laravel
 * `Password::symbols()` 와 같은 `\p{Z}\p{S}\p{P}` 다 — 특수문자 필수 규칙과 판정이 갈리지 않게 한다.
 *
 * @since 7.1.0
 */
class PasswordComposition implements ValidationRule
{
    /**
     * 비밀번호가 2종류 이상의 문자를 섞었는지 검증합니다.
     *
     * @param  string  $attribute  검증 대상 필드명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! PasswordPolicy::enabled('password_require_char_types') || ! is_string($value)) {
            return;
        }

        if (self::countTypes($value) < 2) {
            $fail(__('validation.password_policy.char_types'));
        }
    }

    /**
     * 비밀번호에 들어 있는 문자 종류 수(영문·숫자·특수문자)를 셉니다.
     *
     * @param  string  $value  비밀번호
     * @return int 0~3
     */
    public static function countTypes(string $value): int
    {
        $types = 0;
        $types += preg_match('/[a-zA-Z]/', $value) === 1 ? 1 : 0;
        $types += preg_match('/[0-9]/', $value) === 1 ? 1 : 0;
        $types += preg_match('/[\p{Z}\p{S}\p{P}]/u', $value) === 1 ? 1 : 0;

        return $types;
    }
}
