<?php

namespace App\Enums;

/**
 * 비밀번호가 바뀐(또는 처음 정해진) 경로 Enum — 변경 기록·활동 로그의 출처.
 *
 * @since 7.1.0
 */
enum PasswordChangeSource: string
{
    /** 본인 변경 (마이페이지·정보수정·관리자 본인) */
    case Self_ = 'self';

    /** 메일 재설정 */
    case Reset = 'reset';

    /** 관리자가 다른 회원의 비밀번호를 지정 */
    case Admin = 'admin';

    /** 로그인 관문의 변경 단계 */
    case LoginRequired = 'login_required';

    /** 설치 마법사 최고관리자 */
    case Install = 'install';

    /** 회원 가입 */
    case Register = 'register';

    /** 관리자 회원 등록 */
    case AdminCreate = 'admin_create';

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
        return __('user.password_change_source.'.$this->value);
    }
}
