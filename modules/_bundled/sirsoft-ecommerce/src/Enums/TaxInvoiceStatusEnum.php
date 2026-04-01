<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 세금계산서 발급 상태 Enum
 */
enum TaxInvoiceStatusEnum: string
{
    case PENDING = 'pending';           // 발급대기
    case PROCESSING = 'processing';     // 발급처리중
    case ISSUED = 'issued';             // 발급완료
    case FAILED = 'failed';             // 발급실패
    case CANCELLED = 'cancelled';       // 발급취소

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.tax_invoice_status.'.$this->value);
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
            self::PENDING => 'warning',
            self::PROCESSING => 'info',
            self::ISSUED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'secondary',
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
