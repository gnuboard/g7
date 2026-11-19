<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 소셜 이메일이 기존 회원 이메일과 같을 때의 정책
 *
 * verify_link = 기존 계정 확인 뒤 연결, auto_link_verified = 제공자가 검증한 이메일이면 바로 연결, never_link = 연결하지 않는다.
 *
 * @since 1.0.0
 */
enum EmailConflictPolicy: string
{
    case VerifyLink = 'verify_link';
    case AutoLinkVerified = 'auto_link_verified';
    case NeverLink = 'never_link';

    /**
     * 모든 케이스의 문자열 값 목록을 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
