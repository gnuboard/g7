<?php

namespace App\Enums;

/**
 * 본인인증 정책 적용 범위 Enum.
 *
 * `identity_policies.scope` 에 저장되어 정책이 어떤 단위에 적용되는지 결정합니다.
 * 이슈 #297 에서 scope=route 분기가 강화되었으므로 enum 화로 회귀 방지.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityPolicyScope: string
{
    /** 라우트 패턴 매칭 */
    case Route = 'route';

    /** Service 훅 매칭 */
    case Hook = 'hook';

    /** 모듈/플러그인 커스텀 키 */
    case Custom = 'custom';

    /**
     * 모든 scope 값 배열.
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
     * @return string 번역된 scope 라벨
     */
    public function label(): string
    {
        return __('identity.policy.scope.'.$this->value);
    }
}
