<?php

namespace App\Rules;

use Illuminate\Validation\Rules\Password;

/**
 * 비밀번호 정책 팩토리
 *
 * 보안 환경설정(`security.password_min_length`, `security.require_password_special_char`)을
 * 실제 검증 규칙으로 변환합니다.
 *
 * 기존에는 7개 FormRequest 가 각자 `min:8` / `min:6` 리터럴을 들고 있어 값이 서로 어긋났고,
 * 관리자가 설정한 정책은 어디에도 적용되지 않는 완전 고아였습니다.
 *
 * 로그인(`LoginRequest`)에는 적용하지 않습니다 — 로그인은 기존 비밀번호를 *대조* 하는
 * 행위이지 새 정책을 강제하는 지점이 아닙니다. 정책을 올리면 기존 사용자가 자기 계정에
 * 접근조차 못 하고 재설정 흐름도 로그인 뒤에 있어 데드락이 되며, 길이 규칙은 미인증
 * 공격자에게 "이 비밀번호는 정책 미달" 이라는 사실을 누설합니다.
 */
class PasswordPolicy
{
    /**
     * 기본 최소 길이 (설정 미지정 시)
     */
    public const DEFAULT_MIN_LENGTH = 8;

    /**
     * 현재 보안 설정을 반영한 비밀번호 규칙을 반환합니다.
     *
     * @return Password 비밀번호 검증 규칙
     */
    public static function rule(): Password
    {
        $minLength = (int) g7_core_settings('security.password_min_length', self::DEFAULT_MIN_LENGTH);
        $minLength = $minLength > 0 ? $minLength : self::DEFAULT_MIN_LENGTH;

        $rule = Password::min($minLength);

        if ((bool) g7_core_settings('security.require_password_special_char', false)) {
            $rule = $rule->symbols();
        }

        return $rule;
    }
}
