<?php

namespace App\Enums;

/**
 * 비밀번호 만료 개별 적용 설정 Enum (회원·역할 공용).
 *
 * 우선순위는 회원 개별 > 역할 > 사이트 범위다. 한 회원의 역할끼리 Apply·Exempt 가 부딪치면 Apply(보안 우선).
 *
 * @since 7.1.0
 */
enum PasswordExpiryPolicy: string
{
    /** 상위 설정 따름 (회원: 역할·사이트, 역할: 사이트) */
    case Inherit = 'inherit';

    /** 적용 */
    case Apply = 'apply';

    /** 제외 */
    case Exempt = 'exempt';

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
        return __('user.password_expiry_policy.'.$this->value);
    }
}
