<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 상품 이미지 업로드 최대 개수 초과 예외
 *
 * 상품당 허용된 최대 이미지 수를 초과하여 업로드를 시도할 때 발생합니다.
 * 컨트롤러의 기존 catch (\Exception) 흐름에서 분기하여 422 응답을 반환합니다.
 */
class ProductImageUploadLimitException extends RuntimeException
{
    /**
     * @param  int  $maxImages  허용된 최대 이미지 수
     */
    public function __construct(public readonly int $maxImages)
    {
        parent::__construct(
            __('sirsoft-ecommerce::exceptions.product_image_limit_exceeded', ['max' => $maxImages])
        );
    }
}
