<?php

namespace App\Enums;

/**
 * 사용자 상태 Enum
 *
 * 사용자 계정의 상태 값을 정의합니다.
 */
enum UserStatus: string
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
     * 차단됨
     */
    case Blocked = 'blocked';

    /**
     * 탈퇴됨
     */
    case Withdrawn = 'withdrawn';

    /**
     * 모든 상태 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 유효한 상태 값인지 확인합니다.
     *
     * @param string $value 검증할 상태 값
     * @return bool 유효 여부
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values(), true);
    }

    /**
     * 상태의 번역 키를 반환합니다.
     *
     * @return string 번역 키
     */
    public function labelKey(): string
    {
        return 'user.status.' . $this->value;
    }

    /**
     * 상태의 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 상태 라벨
     */
    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * 상태별 CSS 클래스(variant)를 반환합니다.
     *
     * @return string 스타일 variant (success, secondary, danger, warning)
     */
    public function variant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Inactive => 'secondary',
            self::Blocked => 'danger',
            self::Withdrawn => 'warning',
        };
    }
}
