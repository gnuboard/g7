<?php

namespace App\Enums;

/**
 * 본인인증 정책 적용 대상 사용자 Enum.
 *
 * `identity_policies.applies_to` 에 저장되어 일반 사용자/관리자 중 어느 쪽에
 * 정책을 강제할지 결정합니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityPolicyAppliesTo: string
{
    /** 일반 사용자 본인 */
    case Self_ = 'self';

    /** 관리자 */
    case Admin = 'admin';

    /** 모두 */
    case Both = 'both';

    /**
     * 모든 applies_to 값 배열.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 applies_to 라벨
     */
    public function label(): string
    {
        return __('identity.policy.applies_to.'.$this->value);
    }
}
