<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use Exception;

/**
 * 상품정보제공고시 템플릿을 찾을 수 없을 때 발생하는 예외
 */
class ProductNoticeTemplateNotFoundException extends Exception
{
    /**
     * @param  int|null  $templateId  템플릿 ID
     */
    public function __construct(?int $templateId = null)
    {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.product_notice_template_not_found', [
                'id' => $templateId ?? '',
            ])
        );
    }
}
