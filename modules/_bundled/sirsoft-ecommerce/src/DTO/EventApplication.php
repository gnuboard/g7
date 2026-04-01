<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 이벤트 적용 상세 DTO
 */
class EventApplication
{
    /**
     * @param  int  $eventId  이벤트 ID
     * @param  string  $name  이벤트명
     * @param  string  $type  이벤트 타입
     * @param  string  $discountType  할인 타입 (fixed, rate)
     * @param  float  $discountValue  할인값
     * @param  int  $totalDiscount  총 할인금액
     * @param  array|null  $appliedItems  적용 상품 [{product_option_id, discount_amount}]
     */
    public function __construct(
        public int $eventId = 0,
        public string $name = '',
        public string $type = '',
        public string $discountType = '',
        public float $discountValue = 0,
        public int $totalDiscount = 0,
        public ?array $appliedItems = null,
    ) {}

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'name' => $this->name,
            'type' => $this->type,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'total_discount' => $this->totalDiscount,
            'applied_items' => $this->appliedItems,
        ];
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: $data['event_id'] ?? 0,
            name: $data['name'] ?? '',
            type: $data['type'] ?? '',
            discountType: $data['discount_type'] ?? '',
            discountValue: $data['discount_value'] ?? 0,
            totalDiscount: $data['total_discount'] ?? 0,
            appliedItems: $data['applied_items'] ?? null,
        );
    }
}
