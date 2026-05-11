<?php

namespace App\Enums;

/**
 * 본인인증 정책 출처 Enum.
 *
 * `identity_policies.source_type` 에 저장되어 정책이 코어/모듈/플러그인/관리자
 * 중 어디서 등록되었는지 분류합니다. AdminIdentityLogIndexRequest 의 source_type
 * 필터에도 동일 분류를 사용합니다.
 *
 * @since 7.0.0-beta.5
 */
enum IdentityPolicySourceType: string
{
    /** 코어 */
    case Core = 'core';

    /** 모듈 */
    case Module = 'module';

    /** 플러그인 */
    case Plugin = 'plugin';

    /** 관리자 직접 등록 */
    case Admin = 'admin';

    /**
     * 모든 source_type 값 배열.
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
     * @return string 번역된 source_type 라벨
     */
    public function label(): string
    {
        return __('identity.policy.source_type.'.$this->value);
    }
}
