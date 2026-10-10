<?php

namespace App\Enums;

/**
 * 로그인 관문이 비밀번호 변경을 요구하는 사유 Enum.
 *
 * @since 7.1.0
 */
enum PasswordChangeReason: string
{
    /** 사용 기간 만료 */
    case Expired = 'expired';

    /** 관리자가 정해 준 비밀번호 */
    case AdminAssigned = 'admin_assigned';

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
        return __('user.password_change_reason.'.$this->value);
    }
}
