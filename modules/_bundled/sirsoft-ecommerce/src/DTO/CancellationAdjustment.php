<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;

/**
 * 취소 변경 DTO (OrderAdjustment 구현)
 *
 * 취소 대상 아이템과 수량 정보를 담습니다.
 */
class CancellationAdjustment extends OrderAdjustment
{
    /**
     * @param  array  $cancelItems  취소 대상 [{order_option_id, cancel_quantity}]
     */
    public function __construct(
        public array $cancelItems = [],
    ) {}

    /**
     * 변경 유형을 반환합니다.
     *
     * @return AdjustmentType
     */
    public function getType(): AdjustmentType
    {
        return AdjustmentType::CANCEL;
    }

    /**
     * 제외 대상 아이템 목록을 반환합니다.
     *
     * @return array [{order_option_id, cancel_quantity}]
     */
    public function getExcludedItems(): array
    {
        return $this->cancelItems;
    }

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $items  [{order_option_id, cancel_quantity}]
     * @return self
     */
    public static function fromArray(array $items): self
    {
        return new self(cancelItems: $items);
    }
}
