<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use App\Rules\PasswordDifferentFromCurrent;
use App\Rules\PasswordNotReused;
use Illuminate\Contracts\Validation\Validator;

/**
 * 재사용 금지·현재값 비교 규칙을 본인 증명 뒤에만 붙이는 FormRequest 공용 동작
 *
 * 두 규칙은 저장된 비밀번호와 대조한다. 증명(재설정 토큰·현재 비밀번호·로그인 변경 단계 식별자)이
 * 통과하기 전에 돌리면 "재사용" 오류의 유무로 현재·이전 비밀번호를 맞혀 보는 추측 오라클이 된다.
 * 그래서 `rules()` 가 아니라 `withValidator()->after()` 에서, 앞선 오류가 없을 때만 평가한다.
 *
 * @since 7.1.0
 */
trait ChecksPasswordReuse
{
    /**
     * 검증기에 재사용 금지(·현재값 비교) 검사를 after 콜백으로 붙입니다.
     *
     * @param  Validator  $validator  검증기
     * @param  callable(): (User|null)  $resolveUser  비밀번호를 바꾸는 회원을 돌려주는 콜백 (증명 뒤에 호출)
     * @param  array<int, string>  $prerequisiteFields  이 필드들에 오류가 있으면 검사하지 않는다(본인 증명)
     * @param  bool  $requireDifferentFromCurrent  현재 비밀번호와 같은 값도 거부할지 (로그인 변경 단계)
     * @param  (callable(): bool)|null  $proof  필드 오류 외에 추가로 통과해야 하는 증명 (없으면 생략)
     * @return void
     */
    protected function appendPasswordReuseCheck(
        Validator $validator,
        callable $resolveUser,
        array $prerequisiteFields = [],
        bool $requireDifferentFromCurrent = false,
        ?callable $proof = null,
    ): void {
        $validator->after(function (Validator $validator) use ($resolveUser, $prerequisiteFields, $requireDifferentFromCurrent, $proof) {
            $errors = $validator->errors();

            // 비밀번호 자체가 이미 규칙에 걸렸으면 대조(bcrypt 최대 10회)를 하지 않는다.
            if ($errors->has('password')) {
                return;
            }

            foreach ($prerequisiteFields as $field) {
                if ($errors->has($field)) {
                    return;
                }
            }

            if ($proof !== null && ! $proof()) {
                return;
            }

            $password = $this->input('password');

            if (! is_string($password) || $password === '') {
                return;
            }

            $user = $resolveUser();

            if (! $user instanceof User) {
                return;
            }

            $rules = [new PasswordNotReused($user)];

            if ($requireDifferentFromCurrent) {
                array_unshift($rules, new PasswordDifferentFromCurrent($user));
            }

            foreach ($rules as $rule) {
                $failed = false;
                $rule->validate('password', $password, function (string $message) use ($errors, &$failed) {
                    $errors->add('password', $message);
                    $failed = true;
                });

                if ($failed) {
                    return;
                }
            }
        });
    }
}
