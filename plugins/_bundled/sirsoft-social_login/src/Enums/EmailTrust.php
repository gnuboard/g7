<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 제공자가 준 이메일을 얼마나 믿는지
 *
 * flag = 제공자의 검증 표시를 따른다, trust = 검증된 것으로 본다, verify = 늘 메일 인증번호로 다시 확인한다.
 *
 * @since 1.0.0
 */
enum EmailTrust: string
{
    case Flag = 'flag';
    case Trust = 'trust';
    case Verify = 'verify';

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
