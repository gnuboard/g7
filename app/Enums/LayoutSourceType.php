<?php

namespace App\Enums;

/**
 * 레이아웃 소스 타입 Enum
 *
 * 레이아웃이 제공되는 출처를 구분합니다.
 */
enum LayoutSourceType: string
{
    /**
     * 템플릿에서 제공하는 레이아웃
     */
    case Template = 'template';

    /**
     * 모듈에서 제공하는 레이아웃
     */
    case Module = 'module';

    /**
     * 플러그인에서 제공하는 레이아웃
     */
    case Plugin = 'plugin';

    /**
     * 모든 소스 타입 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 소스 타입 값인지 확인
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
