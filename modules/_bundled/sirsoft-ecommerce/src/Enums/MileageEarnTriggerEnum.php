<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 마일리지 적립 시점 Enum
 *
 * 기본값은 CONFIRMED(구매확정)이며, 관리자가 설정에서 DELIVERED(배송완료)로 전환할 수 있습니다.
 * 이 값은 리스너 구독 분기 및 스케줄러 조회 조건의 기준이 됩니다.
 */
enum MileageEarnTriggerEnum: string
{
    case DELIVERED = 'delivered';     // 배송완료
    case CONFIRMED = 'confirmed';     // 구매확정

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string 다국어 라벨
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.mileage_earn_trigger.'.$this->value);
    }

    /**
     * 트리거 시점 컬럼명을 반환합니다.
     *
     * 스케줄러 조회 조건의 `{trigger}_at` 치환에 사용됩니다.
     *
     * @return string 시점 컬럼명 (confirmed_at|delivered_at)
     */
    public function timestampColumn(): string
    {
        return match ($this) {
            self::CONFIRMED => 'confirmed_at',
            self::DELIVERED => 'delivered_at',
        };
    }

    /**
     * 모든 값 배열을 반환합니다.
     *
     * @return array<int, string> 값 배열
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * 프론트엔드용 옵션 배열을 반환합니다.
     *
     * @return array<int, array{value: string, label: string}> 옵션 배열
     */
    public static function toSelectOptions(): array
    {
        return array_map(fn ($case) => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
