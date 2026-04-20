<?php

namespace App\Enums;

/**
 * 알림 발송 상태 Enum
 */
enum NotificationLogStatus: string
{
    /** 발송 성공 */
    case Sent = 'sent';

    /** 발송 실패 */
    case Failed = 'failed';

    /** 발송 건너뜀 (템플릿 비활성 등) */
    case Skipped = 'skipped';
}
