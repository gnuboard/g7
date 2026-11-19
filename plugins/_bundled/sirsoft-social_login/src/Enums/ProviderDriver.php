<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 제공자 드라이버 종류
 *
 * social_login_providers.driver 컬럼 값. 내장 4종은 각 제공자 전용 드라이버, 그 밖의 OpenID Connect 제공자는 oidc 드라이버를 쓴다.
 *
 * @since 1.0.0
 */
enum ProviderDriver: string
{
    case Kakao = 'kakao';
    case Naver = 'naver';
    case Google = 'google';
    case Facebook = 'facebook';
    case Oidc = 'oidc';

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
