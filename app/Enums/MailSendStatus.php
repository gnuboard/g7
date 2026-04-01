<?php

namespace App\Enums;

/**
 * 메일 발송 상태 Enum
 */
enum MailSendStatus: string
{
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
