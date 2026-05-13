<?php

namespace App\Enums;

/**
 * 본인인증 전송 채널 Enum.
 *
 * `identity_verification_logs.channel` 에 저장되는 채널 분류.
 * 현재 코어는 email 만 제공하며 — sms / ipin / kakao 등은 모듈/플러그인 provider 가
 * 자체 식별자로 추가합니다. 모듈 채널은 enum 외부의 string 으로 유지되며,
 * 본 enum 은 코어 분류만 보장합니다.
 *
 * 참고: `identity_message_templates.channel` 컬럼은 메시지 템플릿 도메인 분류로
 *       (`mail` 등) 별개 의미를 가지므로 본 enum 과 매핑되지 않습니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityVerificationChannel: string
{
    /** 이메일 */
    case Email = 'email';

    /**
     * 코어 채널 값 배열.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 코어 채널 여부.
     *
     * @param  string  $value  검증할 채널 값
     * @return bool 코어 채널 여부
     */
    public static function isCore(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 채널 라벨
     */
    public function label(): string
    {
        return __('identity.channels.'.$this->value);
    }
}
