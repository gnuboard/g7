<?php

namespace App\Enums;

/**
 * 본인인증 메시지 정의 스코프 Enum.
 *
 * `identity_message_definitions.scope_type` 에 저장되어 메시지 정의가
 * provider 기본 / purpose 별 / policy 별 중 어느 계층에 속하는지 분류합니다.
 *
 * 기존 `IdentityMessageDefinition::SCOPE_*` 상수를 대체합니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityMessageScopeType: string
{
    /** Provider 기본 메시지 */
    case ProviderDefault = 'provider_default';

    /** Purpose 별 메시지 */
    case Purpose = 'purpose';

    /** Policy 별 메시지 */
    case Policy = 'policy';

    /**
     * 모든 scope_type 값 배열.
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
     * @return string 번역된 scope_type 라벨
     */
    public function label(): string
    {
        return __('identity.message.scope_type.'.$this->value);
    }
}
