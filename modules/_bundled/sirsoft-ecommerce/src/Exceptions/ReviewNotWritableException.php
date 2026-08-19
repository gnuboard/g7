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

    /**
     * 작성 불가 사유의 번역 라벨을 반환합니다.
     *
     * 사유 식별자를 그대로 :reason 치환자에 실으면 최종 사용자 화면에 원시
     * 식별자(already_written 등)가 노출된다. 라벨 키가 없는 미지의 사유는
     * 식별자를 그대로 반환한다 (라벨 미정의가 표시 자체를 깨면 안 된다).
     *
     * @return string 번역된 사유 라벨
     */
    public function getReasonLabel(): string
    {
        $key = 'sirsoft-ecommerce::messages.reviews.reasons.'.$this->reason;
        $label = __($key);

        return $label === $key ? $this->reason : $label;
    }
}
