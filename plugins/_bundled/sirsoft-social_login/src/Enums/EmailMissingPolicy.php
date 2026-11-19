<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 제공자가 이메일을 주지 않을 때의 가입 정책
 *
 * ask = 가입 화면에서 이메일을 받는다, deny = 가입을 막는다, placeholder = 받을 수 없는 주소(.invalid)로 가입시킨다.
 *
 * @since 1.0.0
 */
enum EmailMissingPolicy: string
{
    case Ask = 'ask';
    case Deny = 'deny';
    case Placeholder = 'placeholder';

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
