<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 환불 상태 Enum
 */
enum RefundStatusEnum: string
{
    case REQUESTED = 'requested';      // 환불 신청
    case APPROVED = 'approved';        // 환불 승인
    case PROCESSING = 'processing';    // 환불 처리 중 (PG 요청 중)
    case ON_HOLD = 'on_hold';          // 환불 보류
    case COMPLETED = 'completed';      // 환불 완료
    case REJECTED = 'rejected';        // 환불 반려
    case WITHDRAWN = 'withdrawn';      // 환불 철회 (신청자 주체)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.refund_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string
     */
    public function variant(): string
    {
        return match ($this) {
            self::REQUESTED => 'secondary',
            self::APPROVED => 'info',
            self::PROCESSING => 'warning',
            self::ON_HOLD => 'dark',
            self::COMPLETED => 'success',
            self::REJECTED => 'danger',
            self::WITHDRAWN => 'secondary',
        };
    }

    /**
     * 최종 상태 여부를 반환합니다.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return in_array($this, self::finalStatuses(), true);
    }

    /**
     * 최종(터미널) 상태 목록을 반환합니다.
     *
     * @return array<self> 최종 상태 목록
     */
    public static function finalStatuses(): array
    {
        return [self::COMPLETED, self::REJECTED, self::WITHDRAWN];
    }

    /**
     * 신청자(유저)가 철회할 수 있는 상태 목록을 반환합니다.
     *
     * 승인(APPROVED) 이후는 환불 실행 절차가 시작되므로 철회 경계 밖이다.
     *
     * @return array<self> 철회 가능 상태 목록
     */
    public static function withdrawableStatuses(): array
    {
        return [self::REQUESTED, self::ON_HOLD];
    }

    /**
     * 신청자(유저)가 철회할 수 있는 상태인지 반환합니다.
     *
     * @return bool 철회 가능하면 true
     */
    public function isWithdrawable(): bool
    {
        return in_array($this, self::withdrawableStatuses(), true);
    }

    /**
     * 현재 상태에서 전이 가능한 목표 상태 목록을 반환합니다.
     *
     * 교환(ExchangeStatusEnum)·반품(ReturnStatusEnum)과 동형인 전이 게이트로,
     * 관리자 일괄 전환이 세 유형을 같은 방식으로 검증한다.
     *
     * @return array<self> 전이 가능 상태 목록
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REQUESTED => [self::APPROVED, self::ON_HOLD, self::REJECTED, self::WITHDRAWN],
            self::ON_HOLD => [self::APPROVED, self::REJECTED, self::WITHDRAWN],
            self::APPROVED => [self::PROCESSING, self::COMPLETED, self::ON_HOLD],
            self::PROCESSING => [self::COMPLETED, self::ON_HOLD],
            self::COMPLETED, self::REJECTED, self::WITHDRAWN => [],
        };
    }

    /**
     * 현재 상태에서 목표 상태로의 전이가 허용되는지 판정합니다.
     *
     * @param  self  $target  목표 상태
     * @return bool 전이 허용 여부
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
