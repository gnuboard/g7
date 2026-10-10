<?php

namespace App\Enums;

/**
 * 비밀번호 만료 시 로그인 동작 Enum (`security.password_expiry_action`).
 *
 * @since 7.1.0
 */
enum PasswordExpiryAction: string
{
    /** 변경해야 로그인 (만료일에 로그인 토큰도 폐기) */
    case Force = 'force';

    /** 다음에 변경 허용 (N일 뒤 다시 안내) */
    case Remind = 'remind';

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
        return __('user.password_expiry_action.'.$this->value);
    }
}
