<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

/**
 * 주문 변경 유형 Enum
 */
enum AdjustmentType: string
{
    case CANCEL = 'cancel';              // 취소
    case EXCHANGE = 'exchange';          // 교환
    // 반품 — 값이 `return` 이 아니라 `order_return` 인 것은 라벨 키(`enums.adjustment_type.order_return`)와
    // 채번 유형(`SequenceType::ORDER_RETURN`)이 이 문자열을 쓰기 때문이다. `return` 이 예약어라서가
    // 아니다: PHP 는 case 이름·값 모두 `return` 을 허용한다(`ClaimTypeEnum::RETURN` 이 그 예).
    case ORDER_RETURN = 'order_return';

    /**
     * 다국어 라벨을 반환합니다.
     *
     * @return string
     */
    public function label(): string
    {
        return __('sirsoft-ecommerce::enums.adjustment_type.'.$this->value);
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
            self::CANCEL => 'danger',
            self::EXCHANGE => 'info',
            self::ORDER_RETURN => 'warning',
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
