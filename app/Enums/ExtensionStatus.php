<?php

namespace App\Enums;

/**
 * 확장(모듈, 플러그인, 템플릿) 상태 Enum
 *
 * 모듈, 플러그인, 템플릿에서 공통으로 사용하는 상태 값을 정의합니다.
 */
enum ExtensionStatus: string
{
    /**
     * 활성화됨
     */
    case Active = 'active';

    /**
     * 비활성화됨
     */
    case Inactive = 'inactive';

    /**
     * 설치 중
     */
    case Installing = 'installing';

    /**
     * 제거 중
     */
    case Uninstalling = 'uninstalling';

    /**
     * 미설치 또는 삭제됨
     */
    case Uninstalled = 'uninstalled';

    /**
     * 업데이트 중
     */
    case Updating = 'updating';

    /**
     * 모든 상태 값을 문자열 배열로 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
