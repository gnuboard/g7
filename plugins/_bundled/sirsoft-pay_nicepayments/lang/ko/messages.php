<?php

declare(strict_types=1);

return [
    'errors' => [
        'tid_required' => 'TID를 입력하세요.',
        'order_not_found' => '주문을 찾을 수 없습니다.',
        'invalid_request' => '잘못된 요청입니다.',
        'invalid_amount' => '요청 금액이 유효하지 않습니다.',
        'vbank_refund_required_fields' => 'TID, 주문번호, 취소금액, 환불계좌 정보(계좌번호·은행코드·예금주)를 모두 입력해주세요.',
        'vbank_refund_order_not_found' => '환불할 주문을 찾을 수 없습니다.',
        'vbank_refund_invalid_payment' => '환불 가능한 나이스페이먼츠 가상계좌 입금완료 결제가 아닙니다.',
        'vbank_refund_amount_mismatch' => '요청 환불 금액이 DB의 취소 가능 금액과 일치하지 않습니다.',
        'vbank_refund_already_processing' => '이미 가상계좌 환불 처리가 진행 중입니다.',
        'vbank_completed_requires_bank_info' => '가상계좌 입금 완료 건은 환불계좌 정보가 필요합니다. 관리자 API를 통해 환불을 진행해주세요.',
        'invalid_refund_amount' => '환불 금액이 유효하지 않습니다. (요청: :amount원)',
    ],
    'refund' => [
        'missing_tid' => '거래 ID(TID)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
    ],
    'defaults' => [
        'vbank_refund_msg' => '가상계좌 환불',
    ],
];
