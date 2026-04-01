<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

/**
 * 장바구니 전체 삭제 요청
 *
 * X-Cart-Key 헤더 검증을 포함합니다.
 */
class DeleteAllCartItemsRequest extends CartKeyRequest
{
    /**
     * 훅 필터 이름
     *
     * @return string
     */
    protected function hookFilterName(): string
    {
        return 'sirsoft-ecommerce.cart.delete_all_validation_rules';
    }
}
