<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\AdjustmentType;

/**
 * 주문 변경 추상 DTO (취소/반품/교환 공통)
 *
 * 교환/반품 확장 시 이 클래스를 상속하여 구현합니다.
 */
abstract class OrderAdjustment
{
    /**
     * 변경 유형을 반환합니다.
     *
     * @return AdjustmentType
     */
    abstract public function getType(): AdjustmentType;

    /**
     * 제외 대상 아이템 목록을 반환합니다.
     *
     * @return array [{order_option_id, cancel_quantity}]
     */
    abstract public function getExcludedItems(): array;

    /**
     * 추가 비용을 반환합니다. (교환배송비, 반품배송비 등)
     *
     * @return int
     */
    public function getAdditionalCharges(): int
    {
        return 0;
    }
}
