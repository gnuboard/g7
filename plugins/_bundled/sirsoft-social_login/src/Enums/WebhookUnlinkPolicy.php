<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 제공자가 연결 해제를 알렸을 때의 처리
 *
 * unlink_only = 연결만 지운다, withdraw_user = 회원을 탈퇴 처리한다(관리자는 코어가 거부).
 *
 * @since 1.0.0
 */
enum WebhookUnlinkPolicy: string
{
    case UnlinkOnly = 'unlink_only';
    case WithdrawUser = 'withdraw_user';

    /**
     * 모든 케이스의 문자열 값 목록을 반환합니다.
     *
     * @return array<int, string> 값 목록
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
