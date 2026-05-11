<?php

namespace App\Enums;

/**
 * 본인인증 정책 실패 시 동작 Enum.
 *
 * `identity_policies.fail_mode` 에 저장되어 정책 위반 시 요청을 차단할지
 * 감사 로그만 남길지 결정합니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityPolicyFailMode: string
{
    /** HTTP 428 차단 (정책 강제) */
    case Block = 'block';

    /** 감사 로그만 기록하고 요청 통과 */
    case LogOnly = 'log_only';

    /**
     * 모든 fail_mode 값 배열.
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
     * @return string 번역된 fail_mode 라벨
     */
    public function label(): string
    {
        return __('identity.policy.fail_mode.'.$this->value);
    }
}
