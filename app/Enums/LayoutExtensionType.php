<?php

namespace App\Enums;

/**
 * 레이아웃 확장 타입 Enum
 *
 * 확장이 적용되는 방식을 구분합니다.
 */
enum LayoutExtensionType: string
{
    /**
     * Extension Point 방식
     * 템플릿에서 명시적으로 정의한 확장점에 컴포넌트 주입
     */
    case ExtensionPoint = 'extension_point';

    /**
     * Overlay 방식
     * 기존 레이아웃의 특정 컴포넌트 ID를 타겟으로 주입
     */
    case Overlay = 'overlay';

    /**
     * 모든 확장 타입 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 확장 타입 값인지 확인
     *
     * @param string $value 확인할 값
     * @return bool
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
