<?php

namespace App\Enums;

/**
 * 확장 소유권 타입 Enum
 *
 * 권한, 역할, 메뉴, 스케줄 등의 엔티티를 생성한 확장의 유형을 구분합니다.
 */
enum ExtensionOwnerType: string
{
    /** 코어 시스템에서 생성 */
    case Core = 'core';

    /** 모듈에서 생성 */
    case Module = 'module';

    /** 플러그인에서 생성 */
    case Plugin = 'plugin';

    /**
     * 모든 값 배열 반환
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 값이 유효한지 확인
     *
     * @param string $value 확인할 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 라벨 반환
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return match ($this) {
            self::Core => __('extension_owner_type.core'),
            self::Module => __('extension_owner_type.module'),
            self::Plugin => __('extension_owner_type.plugin'),
        };
    }
}
