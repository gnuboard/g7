<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Enums;

/**
 * 발송 채널 — bizppurio_dispatches.channel 컬럼의 값 집합.
 *
 * SMS/LMS 는 문자, 알림톡은 카카오 채널. 실제 발송 유형(SMS/LMS 자동 분기)은
 * SmsTypeResolver(Phase 2)가 byte 판별로 확정한다.
 */
enum DispatchChannel: string
{
    case Sms = 'sms';
    case Lms = 'lms';
    case Alimtalk = 'alimtalk';

    /**
     * 현재 locale 에 맞는 채널 라벨을 반환합니다.
     *
     * @return string 다국어 채널 라벨 (예: "SMS", "LMS", "알림톡")
     */
    public function label(): string
    {
        return match ($this) {
            self::Sms => __('sirsoft-message_bizppurio::messages.channel.sms'),
            self::Lms => __('sirsoft-message_bizppurio::messages.channel.lms'),
            self::Alimtalk => __('sirsoft-message_bizppurio::messages.channel.alimtalk'),
        };
    }

    /**
     * 문자(SMS/LMS) 채널 여부를 반환합니다.
     *
     * @return bool 문자 채널이면 true, 알림톡이면 false
     */
    public function isText(): bool
    {
        return $this === self::Sms || $this === self::Lms;
    }

    /**
     * 모든 채널 값 목록을 반환합니다.
     *
     * @return array<int, string> 채널 문자열 배열 (예: ["sms", "lms", "alimtalk"])
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
