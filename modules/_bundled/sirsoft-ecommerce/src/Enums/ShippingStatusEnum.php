<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 배송 상태 Enum
 */
enum ShippingStatusEnum: string
{
    case PENDING = 'pending';                       // 배송대기
    case PREPARING = 'preparing';                   // 상품준비중
    case READY = 'ready';                           // 배송준비완료
    case SHIPPED = 'shipped';                       // 배송중
    case IN_TRANSIT = 'in_transit';                 // 이동중
    case OUT_FOR_DELIVERY = 'out_for_delivery';     // 배송출발
    case DELIVERED = 'delivered';                   // 배송완료
    case FAILED = 'failed';                         // 배송실패
    case RETURNED = 'returned';                     // 반송
    case PICKUP_READY = 'pickup_ready';             // 방문수령대기
    case PICKUP_COMPLETE = 'pickup_complete';       // 방문수령완료

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.shipping_status.'.$this->value);
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
            self::PENDING => 'secondary',
            self::PREPARING => 'info',
            self::READY => 'info',
            self::SHIPPED => 'primary',
            self::IN_TRANSIT => 'primary',
            self::OUT_FOR_DELIVERY => 'primary',
            self::DELIVERED => 'success',
            self::FAILED => 'danger',
            self::RETURNED => 'warning',
            self::PICKUP_READY => 'info',
            self::PICKUP_COMPLETE => 'success',
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
