<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 연결 해제 사유
 *
 * 훅 sirsoft-social_login.account.before_unlink / after_unlink 의 세 번째 인자.
 *
 * @since 1.0.0
 */
enum UnlinkReason: string
{
    case User = 'user';
    case Admin = 'admin';
    case Webhook = 'webhook';
    case Withdraw = 'withdraw';
    case Delete = 'delete';

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
