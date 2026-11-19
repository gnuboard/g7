<?php

namespace Plugins\Sirsoft\SocialLogin\Enums;

/**
 * 웹훅 수신 행 처리 상태
 *
 * received = 저장만 함, processed = 처리 끝, ignored = 대상 없음 또는 검증 실패, failed = 재시도 소진.
 *
 * @since 1.0.0
 */
enum WebhookStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';

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
