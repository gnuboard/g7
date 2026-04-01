<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

/**
 * 체크아웃 연장 요청
 *
 * X-Cart-Key 헤더 검증을 포함합니다.
 */
class ExtendCheckoutRequest extends CartKeyRequest
{
    /**
     * 훅 필터 이름
     *
     * @return string
     */
    protected function hookFilterName(): string
    {
        return 'sirsoft-ecommerce.checkout.extend_validation_rules';
    }
}
