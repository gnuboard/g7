<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 결제창 닫힘 기록 (공개 계약 DTO)
 *
 * 이커머스는 「결제 실패 처리 → 결제 취소 이력 → (있으면) 결제 실패 기록」 순서로 처리한다.
 *
 * @since 1.4.0
 */
final readonly class WindowClosure
{
    /**
     * WindowClosure 생성자
     *
     * @param  string  $code  실패 코드(예: USER_CANCEL)
     * @param  string  $message  실패 메시지(주문 meta 에 남는다)
     * @param  string|null  $cancelMessage  결제 취소 이력 메시지(넘긴 값 그대로 남는다)
     * @param  PaymentFailure|null  $failure  이어서 남길 결제 실패 기록
     */
    public function __construct(
        public string $code,
        public string $message,
        public ?string $cancelMessage = null,
        public ?PaymentFailure $failure = null,
    ) {}
}
