<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 장바구니 상품 구매불가 예외
 *
 * 체크아웃 시 재고부족 또는 판매상태 문제가 있는 상품이 있을 때 발생합니다.
 */
class CartUnavailableException extends Exception
{
    /**
     * @param string $message 예외 메시지
     * @param array $unavailableItems 구매불가 상품 목록
     */
    public function __construct(
        string $message,
        private array $unavailableItems = []
    ) {
        parent::__construct($message);
    }

    /**
     * 구매불가 상품 목록 반환
     *
     * @return array 구매불가 상품 배열
     * [
     *   [
     *     'cart_id' => int,
     *     'product_id' => int,
     *     'product_option_id' => int,
     *     'name' => string,
     *     'option' => string|null,
     *     'thumbnail' => string|null,
     *     'quantity' => int,
     *     'stock' => int,
     *     'reason' => 'stock'|'status',  // 재고부족 or 판매상태
     *   ],
     *   ...
     * ]
     */
    public function getUnavailableItems(): array
    {
        return $this->unavailableItems;
    }

    /**
     * 재고 부족 상품이 있는지 확인
     *
     * @return bool
     */
    public function hasStockIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'stock') {
                return true;
            }
        }

        return false;
    }

    /**
     * 판매상태 문제 상품이 있는지 확인
     *
     * @return bool
     */
    public function hasStatusIssue(): bool
    {
        foreach ($this->unavailableItems as $item) {
            if (($item['reason'] ?? '') === 'status') {
                return true;
            }
        }

        return false;
    }

    /**
     * 로깅용 전체 데이터 반환
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'unavailable_items' => $this->unavailableItems,
            'item_count' => count($this->unavailableItems),
            'has_stock_issue' => $this->hasStockIssue(),
            'has_status_issue' => $this->hasStatusIssue(),
        ];
    }

    /**
     * 구매불가 상품 목록으로 예외 생성 (팩토리 메서드)
     *
     * @param array $items 구매불가 상품 목록
     * @return static
     */
    public static function fromItems(array $items): static
    {
        $message = __('sirsoft-ecommerce::exceptions.cart_unavailable');

        return new static($message, $items);
    }
}
