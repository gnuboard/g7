<?php

namespace App\Enums;

/**
 * 본인인증 코어 purpose Enum.
 *
 * 코어가 계약으로 보장하는 4종 — `MailIdentityProvider` 가 모두 지원합니다.
 * 모듈/플러그인은 `AbstractModule::getIdentityPurposes()` / `AbstractPlugin::getIdentityPurposes()`
 * 로 추가 purpose 를 선언할 수 있으며, 그 값은 `IdentityVerificationManager::declaredPurposes`
 * 레지스트리에 string 으로 머지됩니다 — 본 enum 에는 코어 4종만 정의합니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityVerificationPurpose: string
{
    /** 회원가입 */
    case Signup = 'signup';

    /** 비밀번호 재설정 */
    case PasswordReset = 'password_reset';

    /** 본인 정보 변경 */
    case SelfUpdate = 'self_update';

    /** 민감 작업 (결제 등) */
    case SensitiveAction = 'sensitive_action';

    /**
     * 코어 purpose 값 배열.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 코어 정의 purpose 인지 확인합니다 (모듈 declared 는 false).
     *
     * @param  string  $value  검증할 purpose 값
     * @return bool 코어 purpose 여부
     */
    public static function isCore(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 purpose 라벨
     */
    public function label(): string
    {
        return __('identity.purposes.'.$this->value.'.label');
    }
}
