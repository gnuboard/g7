<?php

namespace App\Rules;

use App\DisplayName\DisplayNameChoicePolicy;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * 회원이 고른 표시 이름 종류를 검증합니다 (가입, 내 정보 수정의 `display_name_kind`).
 *
 * | 조건 | 결과 |
 * | --- | --- |
 * | 표시 이름을 직접 고를 권한이 없다 | 실패 (`display_name.validation.not_permitted`) |
 * | 운영자가 허용한 종류가 아니다 | 실패 (`display_name.validation.not_selectable`) |
 *
 * 판정은 {@see DisplayNameChoicePolicy} 한 곳이 한다. 저장 서비스도 같은 판정을 쓴다.
 *
 * @since 7.2.0
 */
class DisplayNameKindChoice implements ValidationRule
{
    /**
     * @param  User|null  $user  고르는 회원 (가입이면 null)
     */
    public function __construct(
        protected ?User $user = null,
    ) {}

    /**
     * 검증을 실행합니다.
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  고른 종류 키
     * @param  Closure(string): PotentiallyTranslatedString  $fail  실패 콜백
     * @return void 반환값 없음
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $reason = app(DisplayNameChoicePolicy::class)->rejection($this->user, $value);
        if ($reason !== null) {
            $fail(__('display_name.validation.'.$reason));
        }
    }
}
