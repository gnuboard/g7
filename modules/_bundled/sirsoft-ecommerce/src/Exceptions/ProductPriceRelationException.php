<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 상품 가격 관계 위반 예외
 *
 * 판매가가 정가를 초과하는 조합으로 저장이 시도될 때 발생합니다.
 *
 * 일괄 수정은 정가/판매가 중 한쪽만 보내는 부분 전송이 흔해 FormRequest 는 두 값이 모두
 * 전송된 경우에만 비교합니다. 한쪽만 온 경우 나머지는 DB 에 남아 있던 값이 그대로 적용되므로,
 * 실제로 적용될 조합은 저장 직전에만 확정됩니다. 이 예외는 그 지점의 판정 결과입니다.
 */
class ProductPriceRelationException extends RuntimeException
{
    /**
     * @param  int  $productId  대상 상품 ID
     */
    public function __construct(public readonly int $productId)
    {
        parent::__construct(
            __('sirsoft-ecommerce::validation.product.selling_price_lte_list')
        );
    }
}
