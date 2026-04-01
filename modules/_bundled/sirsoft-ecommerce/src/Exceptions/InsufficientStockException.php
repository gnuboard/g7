<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 재고 부족 예외
 *
 * 주문 생성 시 재고가 부족할 때 발생합니다.
 */
class InsufficientStockException extends Exception
{
    /**
     * @param string $message 예외 메시지
     * @param array $insufficientItems 재고 부족 상품 목록
     */
    public function __construct(
        string $message,
        private array $insufficientItems = []
    ) {
        parent::__construct($message);
    }

    /**
     * 재고 부족 상품 목록 반환
     *
     * @return array 재고 부족 상품 배열
     * [
     *   [
     *     'product_option_id' => int,
     *     'product_name' => string,
     *     'option_name' => string|null,
     *     'requested_quantity' => int,
     *     'available_quantity' => int,
     *     'shortage' => int,
     *   ],
     *   ...
     * ]
     */
    public function getInsufficientItems(): array
    {
        return $this->insufficientItems;
    }

    /**
     * 재고 부족 상품이 있는지 확인
     *
     * @return bool
     */
    public function hasInsufficientItems(): bool
    {
        return ! empty($this->insufficientItems);
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
            'insufficient_items' => $this->insufficientItems,
            'item_count' => count($this->insufficientItems),
        ];
    }

    /**
     * 재고 부족 상품 목록으로 예외 생성 (팩토리 메서드)
     *
     * @param array $items 재고 부족 상품 목록
     * @return static
     */
    public static function fromItems(array $items): static
    {
        $count = count($items);
        $message = __('sirsoft-ecommerce::exceptions.insufficient_stock', ['count' => $count]);

        return new static($message, $items);
    }
}
