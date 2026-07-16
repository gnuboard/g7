<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use RuntimeException;

/**
 * 리뷰 작성 불가 예외
 *
 * 리뷰 작성 자격 검증(canWrite)에 실패한 상태에서 리뷰 생성을 시도할 때 발생합니다.
 * 컨트롤러의 기존 catch (\RuntimeException) 흐름을 유지하기 위해 RuntimeException 을 상속합니다.
 */
class ReviewNotWritableException extends RuntimeException
{
    /**
     * @param  string  $reason  작성 불가 사유 식별자 (예: already_written, deadline_passed)
     */
    public function __construct(
        private string $reason
    ) {
        parent::__construct(
            __('sirsoft-ecommerce::messages.reviews.cannot_write', ['reason' => $reason])
        );
    }

    /**
     * 작성 불가 사유 식별자 반환
     *
     * @return string 사유 식별자
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
