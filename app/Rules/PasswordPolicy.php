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
 *
 * 7.1.0 부터 작성 규칙(조합·추측 방지)이 더해졌다. `rule()` 은 확장 호환을 위해 반환 타입
 * (`Password`)을 그대로 두고, 새 규칙까지 묶은 목록은 `rules()` 가 돌려준다. 재사용 금지·현재값
 * 비교는 본인 증명 뒤에만 평가해야 하므로(추측 오라클 차단) 여기에 넣지 않는다 — 각 FormRequest 의
 * `withValidator()->after()` 가 증명 통과 뒤에 붙인다.
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

    /**
     * 길이·특수문자 + 조합·추측 방지 규칙을 한 목록으로 반환합니다.
     *
     * 요청·회원 문맥 없이도 만들 수 있어야 한다 — 식별자가 없으면 아이디 포함 검사만 건너뛴다.
     *
     * @param  string|null  $identifier  아이디 판정에 쓸 이메일 (없으면 아이디 포함 검사 생략)
     * @return array<int, mixed> 검증 규칙 목록
     */
    public static function rules(?string $identifier = null): array
    {
        return [
            self::rule(),
            new PasswordComposition,
            new PasswordNotGuessable($identifier),
        ];
    }

    /**
     * 보안 설정의 정수 값을 한계값(`core.settings_limits.security_{key}_min|_max`)으로 양쪽 클램프해 읽습니다.
     *
     * 단건 설정 저장 경로는 범위를 검사하지 않으므로 판독부가 최종 방어선이다.
     *
     * @param  string  $key  security 카테고리 안의 키
     * @param  int  $default  설정이 없을 때 값
     * @return int 클램프된 값
     */
    public static function clampedSetting(string $key, int $default): int
    {
        $value = g7_core_settings("security.{$key}", $default);
        $value = is_numeric($value) ? (int) $value : $default;

        $min = config("core.settings_limits.security_{$key}_min");
        $max = config("core.settings_limits.security_{$key}_max");

        if (is_numeric($min)) {
            $value = max((int) $min, $value);
        }

        if (is_numeric($max)) {
            $value = min((int) $max, $value);
        }

        return $value;
    }

    /**
     * 보안 설정의 불리언 값을 느슨하게 읽습니다 (true·"1"·"true").
     *
     * @param  string  $key  security 카테고리 안의 키
     * @return bool 켜져 있으면 true
     */
    public static function enabled(string $key): bool
    {
        return filter_var(g7_core_settings("security.{$key}", false), FILTER_VALIDATE_BOOLEAN);
    }
}
