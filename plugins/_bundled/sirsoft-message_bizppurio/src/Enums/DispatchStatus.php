<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Enums;

/**
 * 발송 상태 — bizppurio_dispatches.status 컬럼의 값 집합.
 *
 * pending(대기) → sent(발송요청 성공, 리포트 대기) → success/failed(webhook 리포트 확정).
 * webhook 결과 수신(Phase 4) 전까지는 sent 상태로 머문다.
 */
enum DispatchStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Success = 'success';
    case Failed = 'failed';

    /**
     * 현재 locale 에 맞는 상태 라벨을 반환합니다.
     *
     * @return string 다국어 상태 라벨 (예: "대기", "발송중", "성공", "실패")
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('sirsoft-message_bizppurio::messages.status.pending'),
            self::Sent => __('sirsoft-message_bizppurio::messages.status.sent'),
            self::Success => __('sirsoft-message_bizppurio::messages.status.success'),
            self::Failed => __('sirsoft-message_bizppurio::messages.status.failed'),
        };
    }

    /**
     * 리포트 수신으로 상태가 확정되었는지(성공/실패) 여부를 반환합니다.
     *
     * @return bool 성공 또는 실패이면 true, 대기/발송중이면 false
     */
    public function isFinal(): bool
    {
        return $this === self::Success || $this === self::Failed;
    }

    /**
     * 모든 상태 값 목록을 반환합니다.
     *
     * @return array<int, string> 상태 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
