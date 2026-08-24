<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 반품 클레임 상태 Enum
 *
 * 반품 신청서(`ecommerce_order_returns.return_status`)와 신청 상품
 * (`ecommerce_order_return_options.option_status`)이 공유하는 세밀 상태 11종이다.
 * 교환 상태 14종에서 재발송 3종(redelivery_ready / redelivering / redelivered)을
 * 제외한 형태이며, 회수 도착(COLLECTED)에서 곧바로 완료(COMPLETED)로 이어진다.
 *
 * 라벨은 유형 접두를 포함한 정확 명칭("반품신청" 등)이며, 유저·관리자 화면이
 * `enums.return_status.*` 단일 출처를 공유한다.
 */
enum ReturnStatusEnum: string
{
    case REQUESTED = 'requested';                  // 반품신청
    case PAYMENT_PENDING = 'payment_pending';      // 반품결제대기
    case PAID = 'paid';                            // 반품결제완료
    case APPROVED = 'approved';                    // 반품승인
    case ON_HOLD = 'on_hold';                      // 반품보류
    case REJECTED = 'rejected';                    // 반품반려
    case COLLECTION_READY = 'collection_ready';    // 반품회수준비중
    case COLLECTING = 'collecting';                // 반품회수중
    case COLLECTED = 'collected';                  // 반품회수도착
    case COMPLETED = 'completed';                  // 반품완료
    case WITHDRAWN = 'withdrawn';                  // 반품철회

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.return_status.'.$this->value);
    }

    /**
     * 프론트엔드용 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    /**
     * 활동 로그용 다국어 라벨 키를 반환합니다.
     *
     * @return string 다국어 라벨 키
     */
    public function labelKey(): string
    {
        return 'sirsoft-ecommerce::enums.return_status.'.$this->value;
    }

    /**
     * 상태 뱃지 variant를 반환합니다.
     *
     * @return string 뱃지 variant
     */
    public function variant(): string
    {
        return match ($this) {
            self::REQUESTED => 'secondary',
            self::PAYMENT_PENDING => 'warning',
            self::PAID => 'success',
            self::APPROVED => 'info',
            self::ON_HOLD => 'dark',
            self::REJECTED => 'danger',
            self::COLLECTION_READY, self::COLLECTING, self::COLLECTED => 'primary',
            self::COMPLETED => 'success',
            self::WITHDRAWN => 'secondary',
        };
    }

    /**
     * 최종(터미널) 상태 여부를 반환합니다.
     *
     * @return bool 최종 상태이면 true
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
     * @return array<self> 철회 가능 상태 목록
     */
    public static function withdrawableStatuses(): array
    {
        return [self::REQUESTED, self::PAYMENT_PENDING, self::PAID, self::ON_HOLD];
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
     * @return array<self> 전이 가능 상태 목록
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::REQUESTED => [
                self::PAYMENT_PENDING,
                self::APPROVED,
                self::ON_HOLD,
                self::REJECTED,
                self::WITHDRAWN,
            ],
            self::PAYMENT_PENDING => [self::PAID, self::APPROVED, self::REJECTED, self::WITHDRAWN],
            self::PAID => [self::APPROVED, self::ON_HOLD, self::REJECTED, self::WITHDRAWN],
            self::ON_HOLD => [self::APPROVED, self::REJECTED, self::WITHDRAWN],
            self::APPROVED => [self::COLLECTION_READY],
            self::COLLECTION_READY => [self::COLLECTING],
            self::COLLECTING => [self::COLLECTED],
            self::COLLECTED => [self::COMPLETED],
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
     * @return array<string> 상태 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<array{value: string, label: string}> 셀렉트 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
