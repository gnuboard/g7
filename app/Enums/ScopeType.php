<?php

namespace App\Enums;

/**
 * 권한 스코프 타입 Enum
 *
 * 역할별 권한의 접근 스코프를 정의합니다.
 * - Self: 본인 리소스만 접근
 * - Role: 소유 역할을 공유하는 사용자의 리소스 접근
 * - null(피벗 기본값): 전체 접근 (제한 없음)
 */
enum ScopeType: string
{
    /** 본인 리소스만 접근 */
    case Self = 'self';

    /** 소유 역할 공유 사용자의 리소스 접근 */
    case Role = 'role';

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 스코프 라벨
     */
    public function label(): string
    {
        return match ($this) {
            self::Self => __('auth.scope_type_self'),
            self::Role => __('auth.scope_type_role'),
        };
    }

    /**
     * 모든 케이스 값 배열을 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 값의 유효성을 검증합니다.
     *
     * @param  string  $value  검증할 스코프 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }
}
