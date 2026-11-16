<?php

namespace Modules\Sirsoft\Ecommerce\Contracts\Payment\Data;

/**
 * 재결제 복구 요청 (공개 계약 DTO)
 *
 * 복구할지는 결제사가 판정한다. 이커머스는 결제 완료와 부분 취소 이력만 막고 상태를 정상 전이로 되돌린다.
 * 주문 meta 에는 `{metaPrefix}_retry_failures`(최근 5건), `{metaPrefix}_retry_count`, `{metaPrefix}_retry_started_at` 를,
 * 결제 meta 에는 `retry_count`, `retry_started_at` 을 남기고 실패 키를 지운다.
 *
 * @since 1.4.0
 */
final readonly class RetryRestoreRequest
{
    /**
     * RetryRestoreRequest 생성자
     *
     * @param  string  $metaPrefix  주문 meta 키 접두(호출 결제사 id 와 같아야 한다)
     * @param  int|null  $expectedAmount  잠금 안에서 다시 대조할 청구액(최소 화폐 단위, null 이면 대조 안 함)
     * @param  bool  $clearTransaction  거래번호와 카드 승인번호도 비울지
     * @param  bool  $recordFailureStage  실패 이력 항목에 결제 meta 의 `failure_stage` 를 함께 남기고 그 키도 지울지
     */
    public function __construct(
        public string $metaPrefix,
        public ?int $expectedAmount = null,
        public bool $clearTransaction = false,
        public bool $recordFailureStage = false,
    ) {}
}
