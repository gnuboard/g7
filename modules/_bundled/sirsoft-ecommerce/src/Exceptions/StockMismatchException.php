<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 재고 불일치 예외
 *
 * 실제 재고와 입력된 재고가 일치하지 않을 때 발생합니다.
 */
class StockMismatchException extends Exception
{
    /**
     * @param  int  $productId  상품 ID
     * @param  int  $expectedStock  예상 재고
     * @param  int  $actualStock  실제 재고
     */
    public function __construct(
        private int $productId,
        private int $expectedStock,
        private int $actualStock
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.stock_mismatch', [
                'product_id' => $productId,
                'expected' => $expectedStock,
                'actual' => $actualStock,
            ])
        );
    }

    /**
     * 상품 ID를 반환합니다.
     *
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }

    /**
     * 예상 재고를 반환합니다.
     *
     * @return int
     */
    public function getExpectedStock(): int
    {
        return $this->expectedStock;
    }

    /**
     * 실제 재고를 반환합니다.
     *
     * @return int
     */
    public function getActualStock(): int
    {
        return $this->actualStock;
    }
}
