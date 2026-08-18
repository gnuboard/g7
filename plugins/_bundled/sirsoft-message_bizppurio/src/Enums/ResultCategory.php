<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Enums;

/**
 * 결과코드 분류 — 비즈뿌리오 결과코드를 처리 방침으로 분류한 값 집합.
 *
 * ResultCodeResolver(Phase 4)가 코드 → 이 분류로 매핑하고, 발송 재시도 정책
 * (SendMessageJob, Phase 2)과 잔액부족 자체알림(Phase 4)의 판단 기준이 된다.
 *
 * - success: 발송/리포트 성공
 * - retry: 일시 오류 → 재시도 대상
 * - permanent_failure: 영구 실패 → 즉시 실패 처리
 * - balance_low: 지갑 잔액 부족(9070 문자 / 7436 알림톡) → 실패 + 관리자 자체알림
 */
enum ResultCategory: string
{
    case Success = 'success';
    case Retry = 'retry';
    case PermanentFailure = 'permanent_failure';
    case BalanceLow = 'balance_low';

    /**
     * 현재 locale 에 맞는 분류 라벨을 반환합니다.
     *
     * @return string 다국어 분류 라벨
     */
    public function label(): string
    {
        return match ($this) {
            self::Success => __('sirsoft-message_bizppurio::messages.result_category.success'),
            self::Retry => __('sirsoft-message_bizppurio::messages.result_category.retry'),
            self::PermanentFailure => __('sirsoft-message_bizppurio::messages.result_category.permanent_failure'),
            self::BalanceLow => __('sirsoft-message_bizppurio::messages.result_category.balance_low'),
        };
    }

    /**
     * 재시도 대상 분류 여부를 반환합니다.
     *
     * @return bool 일시 오류(retry)이면 true
     */
    public function isRetryable(): bool
    {
        return $this === self::Retry;
    }

    /**
     * 발송 실패로 확정되는 분류 여부를 반환합니다.
     *
     * @return bool 영구 실패 또는 잔액 부족이면 true
     */
    public function isFailure(): bool
    {
        return $this === self::PermanentFailure || $this === self::BalanceLow;
    }

    /**
     * 모든 분류 값 목록을 반환합니다.
     *
     * @return array<int, string> 분류 문자열 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
