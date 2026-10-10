<?php

namespace Modules\Sirsoft\Ecommerce\Exceptions;

use LogicException;

/**
 * 환불 장부 준비를 트랜잭션 밖에서 부른 예외 (P-D5)
 *
 * `RefundExecutionService::prepareLedger()` 는 결제행·주문의 환불 누적을 PG 호출 **앞에서 저장**한다.
 * 그 저장을 되돌릴 수 있는 것은 트랜잭션 롤백뿐이라, 트랜잭션 없이 부르면 PG 가 실패해도 누적이
 * 남아 잔여 환불 한도가 실제보다 좁아진다. 호출 계약 위반이므로 사용자 응답이 아니라 개발 단계에서
 * 드러나야 하는 프로그래밍 오류다.
 */
class RefundLedgerOutsideTransactionException extends LogicException
{
    /**
     * 다국어 메시지로 예외를 만듭니다.
     */
    public function __construct()
    {
        parent::__construct(__('sirsoft-ecommerce::exceptions.refund_ledger_outside_transaction'));
    }
}
