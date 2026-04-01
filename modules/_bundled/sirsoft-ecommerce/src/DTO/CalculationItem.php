<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

/**
 * 계산 대상 아이템 DTO
 */
class CalculationItem
{
    /**
     * @param  int  $productId  상품 ID
     * @param  int  $productOptionId  상품 옵션 ID
     * @param  int  $quantity  수량
     * @param  int|null  $cartId  장바구니 아이템 ID (선택)
     * @param  array|null  $productSnapshot  상품 스냅샷 (환불 재계산용)
     * @param  array|null  $optionSnapshot  옵션 스냅샷 (환불 재계산용)
     */
    public function __construct(
        public int $productId = 0,
        public int $productOptionId = 0,
        public int $quantity = 0,
        public ?int $cartId = null,
        public ?array $productSnapshot = null,
        public ?array $optionSnapshot = null,
    ) {}

    /**
     * 배열에서 DTO를 생성합니다.
     *
     * @param  array  $data  배열 데이터
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: $data['product_id'] ?? 0,
            productOptionId: $data['product_option_id'] ?? 0,
            quantity: $data['quantity'] ?? 0,
            cartId: $data['cart_id'] ?? null,
            productSnapshot: $data['product_snapshot'] ?? null,
            optionSnapshot: $data['option_snapshot'] ?? null,
        );
    }

    /**
     * 배열로 변환합니다.
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_option_id' => $this->productOptionId,
            'quantity' => $this->quantity,
            'cart_id' => $this->cartId,
            'product_snapshot' => $this->productSnapshot,
            'option_snapshot' => $this->optionSnapshot,
        ];
    }
}
