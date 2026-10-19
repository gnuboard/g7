<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * OAuth 왕복을 시작한 의도
 *
 * login = 로그인 화면, link = 마이페이지 연결 추가, test = 관리자 연결 테스트(결과 화면만 보여 주고 로그인하지 않는다).
 *
 * @since 1.0.0
 */
enum AttemptIntent: string
{
    case Login = 'login';
    case Link = 'link';
    case Test = 'test';

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
