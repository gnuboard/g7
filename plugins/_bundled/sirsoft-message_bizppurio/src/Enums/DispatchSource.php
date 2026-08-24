<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Enums;

/**
 * 발송 출처 — bizppurio_dispatches.source 컬럼의 값 집합.
 *
 * 1차 범위는 auto(코어 알림 이벤트 자동발송)만 사용한다.
 * manual(수동발송)·bulk(대량발송)은 후속(D15).
 */
enum DispatchSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Bulk = 'bulk';

    /**
     * 현재 locale 에 맞는 출처 라벨을 반환합니다.
     *
     * @return string 다국어 출처 라벨 (예: "자동", "수동", "대량")
     */
    public function label(): string
    {
        return match ($this) {
            self::Auto => __('sirsoft-message_bizppurio::messages.source.auto'),
            self::Manual => __('sirsoft-message_bizppurio::messages.source.manual'),
            self::Bulk => __('sirsoft-message_bizppurio::messages.source.bulk'),
        };
    }

    /**
     * 모든 출처 값 목록을 반환합니다.
     *
     * @return array<int, string> 출처 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
