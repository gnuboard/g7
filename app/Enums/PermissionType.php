<?php

namespace App\Enums;

/**
 * 권한 타입 Enum
 *
 * 권한이 사용되는 컨텍스트를 정의합니다.
 * - Admin: 관리자 화면에서 사용되는 권한
 * - User: 사용자(프론트엔드) 화면에서 사용되는 권한
 */
enum PermissionType: string
{
    /**
     * 관리자 화면용 권한
     */
    case Admin = 'admin';

    /**
     * 사용자 화면용 권한
     */
    case User = 'user';

    /**
     * 모든 타입 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 타입 값인지 확인합니다.
     *
     * @param string $value 검증할 타입 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 타입의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 타입 라벨
     */
    public function label(): string
    {
        return __('permission.type.'.$this->value);
    }

    /**
     * 타입의 아이콘 이름을 반환합니다.
     *
     * @return string 아이콘 이름
     */
    public function icon(): string
    {
        return match ($this) {
            self::Admin => 'cog',
            self::User => 'user',
        };
    }
}
