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
     * 소수 자릿수 통화(기본 통화가 USD 등)의 회수비를 담을 수 있도록 `float` 도 받는다.
     *
     * @return int|float 추가 비용
     */
    public function getAdditionalCharges(): int|float
    {
        return 0;
    }

    /**
     * 표시용 재계산에 넣을 옵션 치환 입력을 반환합니다. (교환 전용 — 기본은 없음)
     *
     * 교환은 상품이 주문에 남되 **다른 옵션으로 바뀐다**. 환불 산식은 그 차액을
     * `getAdditionalCharges()` 로 이미 다루므로, 치환은 "교환 후 주문금액" 열을 그리는
     * 두 번째 재계산(패스 B)에만 쓰인다. 비어 있으면 `OrderAdjustmentService` 는 두 번째
     * 패스를 돌리지 않고 첫 패스 결과를 그대로 표시 스냅샷으로 쓴다.
     *
     * @return array<int, array{quantity: int, product_option_id: int|null, unit_price: int, option_snapshot: array, additional_options_snapshot: array}> 주문옵션 ID → 치환 입력
     */
    public function getSubstitutions(): array
    {
        return [];
    }
}
