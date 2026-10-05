<?php

namespace App\Enums;

/**
 * 알림 정의 분류 Enum (notification_definitions.category)
 *
 * 일반 알림은 분류 값이 없다(null). 분류 값이 있는 정의만 이 Enum 의 case 를 가진다.
 */
enum NotificationDefinitionCategory: string
{
    /** 본인인증 알림 — 인증번호·검증 링크 메일 (수신자 고정, 발송 로그 마스킹) */
    case Identity = 'identity';
}
