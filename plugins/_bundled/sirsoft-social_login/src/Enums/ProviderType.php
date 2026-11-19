<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 제공자 행 종류
 *
 * builtin = 설치 때 시딩하는 내장 4종(삭제 불가), custom = 관리자가 추가한 OIDC 제공자.
 *
 * @since 1.0.0
 */
enum ProviderType: string
{
    case Builtin = 'builtin';
    case Custom = 'custom';

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
