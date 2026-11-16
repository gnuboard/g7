<?php

declare(strict_types=1);

return [
    'errors' => [
        'tid_required' => 'TID를 입력하세요.',
        'order_not_found' => '주문을 찾을 수 없습니다.',
        'invalid_request' => '잘못된 요청입니다.',
        'invalid_amount' => '요청 금액이 유효하지 않습니다.',
        'vbank_refund_order_not_found' => '환불할 주문을 찾을 수 없습니다.',
        'vbank_refund_invalid_payment' => '환불 가능한 나이스페이먼츠 가상계좌 입금완료 결제가 아닙니다.',
        'vbank_refund_amount_mismatch' => '요청 환불 금액이 DB의 취소 가능 금액과 일치하지 않습니다.',
        'vbank_refund_already_processing' => '이미 가상계좌 환불 처리가 진행 중입니다.',
        'vbank_completed_requires_bank_info' => '가상계좌 입금 완료 건은 환불계좌 정보가 필요합니다. 관리자 API를 통해 환불을 진행해주세요.',
        'invalid_refund_amount' => '환불 금액이 유효하지 않습니다. (요청: :amount)',
        'escrow_delivery_rejected' => '나이스페이먼츠가 에스크로 배송 등록을 거절했습니다.',
        'cancel_rejected' => '나이스페이먼츠가 결제 취소를 거절했습니다.',
        'escrow_delivery_failed' => '에스크로 배송 등록 중 오류가 발생해 등록을 마치지 못했습니다. 잠시 후 다시 시도하고, 계속 실패하면 서버 로그를 확인해 주세요.',
    ],
    'claim' => [
        'invalid_callback' => '클레임 결제 승인에 필요한 콜백 파라미터가 없습니다.',
        'window_failed' => '결제창 인증이 완료되지 않았습니다.',
        'mid_mismatch' => '콜백의 상점 ID(MID)가 설정과 일치하지 않습니다.',
        'signature_mismatch' => '콜백 서명 검증에 실패했습니다.',
        'deposit_not_supported' => '클레임 추가결제는 입금 대기 방식(가상계좌)을 지원하지 않습니다.',
        'invalid_refund_amount' => '환급 금액이 올바르지 않습니다.',
        'amount_mismatch' => '결제 금액이 청구액과 맞지 않거나 청구액을 확인할 수 없어 결제를 확정하지 않았습니다(승인된 금액은 취소됩니다). 신청서에서 결제를 다시 시작해 주세요.',
        'order_id_mismatch' => '승인 응답의 주문번호가 승인 대상 클레임 결제번호와 일치하지 않습니다.',
        'compensation_cancel_reason' => '클레임 결제 승인 거절에 따른 자동 취소',
    ],
    'refund' => [
        'missing_tid' => '거래 ID(TID)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
        'pg_failed' => '결제대행사와 통신하는 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',
    ],
    'defaults' => [
        'vbank_refund_msg' => '가상계좌 환불',
    ],
    // 입금 전 취소된 주문의 가상계좌 발급 취소 사유 (나이스페이 취소 API 에 싣는다)
    'vbank_close_reason' => '입금 전 주문 취소',
    'fields' => [
        'tid' => '거래 ID(TID)',
        'moid' => '주문번호',
        'cancel_amt' => '취소 금액',
        'cancel_msg' => '취소 사유',
        'refund_acct_no' => '환불 계좌번호',
        'refund_bank_cd' => '환불 은행코드',
        'refund_acct_nm' => '환불 예금주',
    ],
    'settings' => [
        'redirect_url_format' => '결제 후 이동 주소는 {shopBase}, / 또는 http(s):// 로 시작해야 하며 공백을 넣을 수 없습니다. 다른 사이트로 보내는 //주소 와 javascript: 는 쓸 수 없습니다.',
    ],
    // 결제 화면·결제대행사 연동 API 의 오류 응답 문구
    'responses' => [
        'amount_mismatch' => '결제 금액이 주문 금액과 일치하지 않습니다.',
        'currency_not_chargeable' => '결제 통화로 청구액을 계산할 수 없습니다.',
        'escrow_payment_not_found' => '요청한 거래번호의 에스크로 결제를 찾을 수 없습니다.',
        'order_not_found' => '주문을 찾을 수 없습니다.',
        'ownership_verification_failed' => '주문 소유자 확인에 실패했습니다.',
        'too_many_requests' => '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.',
    ],
];
