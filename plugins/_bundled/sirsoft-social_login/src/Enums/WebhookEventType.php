<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 제공자 웹훅 이벤트 종류
 *
 * social_login_webhook_events.event_type 컬럼 값.
 *
 * @since 1.0.0
 */
enum WebhookEventType: string
{
    case Unlink = 'unlink';
    case AccountDelete = 'account_delete';
    case Deauthorize = 'deauthorize';
    case DataDeletion = 'data_deletion';
    case IncompleteSignup = 'incomplete_signup';

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
