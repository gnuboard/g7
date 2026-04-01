<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

/**
 * 장바구니 단건 삭제 요청
 *
 * X-Cart-Key 헤더 검증을 포함합니다.
 */
class DeleteCartItemRequest extends CartKeyRequest
{
    /**
     * 훅 필터 이름
     *
     * @return string
     */
    protected function hookFilterName(): string
    {
        return 'sirsoft-ecommerce.cart.delete_item_validation_rules';
    }
}
