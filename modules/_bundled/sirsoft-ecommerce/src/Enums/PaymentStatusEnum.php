<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 결제 상태 Enum
 */
enum PaymentStatusEnum: string
{
    case READY = 'ready';                           // 결제대기
    case IN_PROGRESS = 'in_progress';               // 결제진행중
    case WAITING_DEPOSIT = 'waiting_deposit';       // 입금대기 (가상계좌)
    case PAID = 'paid';                             // 결제완료
    case PARTIAL_CANCELLED = 'partial_cancelled';   // 부분취소
    case CANCELLED = 'cancelled';                   // 전체취소
    case FAILED = 'failed';                         // 결제실패
    case EXPIRED = 'expired';                       // 기한만료 (가상계좌)

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.payment_status.'.$this->value);
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
            self::READY => 'secondary',
            self::IN_PROGRESS => 'info',
            self::WAITING_DEPOSIT => 'warning',
            self::PAID => 'success',
            self::PARTIAL_CANCELLED => 'warning',
            self::CANCELLED => 'danger',
            self::FAILED => 'danger',
            self::EXPIRED => 'secondary',
        };
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
