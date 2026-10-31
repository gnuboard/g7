<?php

namespace App\Enums;

/**
 * 비밀번호 만료 사이트 적용 범위 Enum (`security.password_expiry_scope`).
 *
 * @since 7.1.0
 */
enum PasswordExpiryScope: string
{
    /** 전체 회원 */
    case All = 'all';

    /** 관리자만 */
    case Admin = 'admin';

    /** 지정한 역할·회원만 (역할·회원의 '적용' 선택분) */
    case Selected = 'selected';

    /**
     * 모든 값을 문자열 배열로 반환합니다.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 번역된 라벨
     */
    public function label(): string
    {
        return __('user.password_expiry_scope.'.$this->value);
    }
}
