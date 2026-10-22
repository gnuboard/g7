<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 가입 때 추가 정보 화면을 띄우는 시점
 *
 * when_missing = 필수 정보가 비었을 때만, always = 늘.
 *
 * @since 1.0.0
 */
enum ExtraInfoMode: string
{
    case WhenMissing = 'when_missing';
    case Always = 'always';

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
