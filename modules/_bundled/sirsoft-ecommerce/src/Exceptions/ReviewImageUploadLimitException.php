<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 리뷰 이미지 업로드 최대 개수 초과 예외
 *
 * 리뷰당 허용된 최대 이미지 수를 초과하여 업로드를 시도할 때 발생합니다.
 * 컨트롤러의 기존 catch (\RuntimeException) 흐름을 유지하기 위해 RuntimeException 을 상속합니다.
 */
class ReviewImageUploadLimitException extends RuntimeException
{
    /**
     * @param  int  $maxImages  허용된 최대 이미지 수
     */
    public function __construct(int $maxImages)
    {
        parent::__construct(
            __('sirsoft-ecommerce::review.image_upload_limit_exceeded', ['max' => $maxImages])
        );
    }
}
