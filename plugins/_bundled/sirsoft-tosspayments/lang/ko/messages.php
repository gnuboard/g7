<?php

/**
 * 토스페이먼츠 플러그인 메시지 (Korean)
 */
return [
    'errors' => [
        'payment_failed' => '결제 처리에 실패했습니다. 잠시 후 다시 시도해 주세요.',
        'approval_verification_cancel_reason' => '결제 승인 검증 실패에 따른 자동 취소',
    ],
    'claim' => [
        'invalid_callback' => '클레임 결제 승인에 필요한 콜백 파라미터(paymentKey, orderId, amount)가 없습니다.',
        'order_id_mismatch' => '콜백의 주문번호가 승인 대상 클레임 결제번호와 일치하지 않습니다.',
        'deposit_not_supported' => '클레임 추가결제는 입금 대기 방식(가상계좌)을 지원하지 않습니다.',
        'amount_mismatch' => '결제창을 연 뒤 청구액이 변경되었습니다. 신청서에서 결제를 다시 시작해 주세요.',
        'amount_mismatch_cancel_reason' => '클레임 결제 승인 금액 불일치에 따른 자동 취소',
    ],
    'refund' => [
        'missing_payment_key' => '토스페이먼츠 결제 키가 존재하지 않아 환불 처리할 수 없습니다.',
        'default_reason' => '고객 요청에 의한 취소',
        'escrow_partial_not_allowed' => '에스크로 결제는 부분 취소를 지원하지 않습니다. 전체 취소만 가능합니다.',
        'missing_refund_account' => '가상계좌 결제를 취소하려면 환불받을 계좌 정보(은행, 계좌번호, 예금주)가 필요합니다.',
    ],
    'cash_receipt' => [
        'provider_name' => '토스페이먼츠',
        'invalid_order_id' => '현금영수증 발급 식별자가 토스페이먼츠 형식(영문·숫자·-·_ 6~64자)에 맞지 않습니다.',
        'cancel_reason' => '주문 금액 변경에 따른 재발급',
    ],
    'settings_validation' => [
        'vbank_valid_hours_range' => '가상계좌 입금기한은 :min~:max시간(최대 90일) 사이여야 합니다.',
        'use_escrow_invalid' => '에스크로 사용 설정값이 올바르지 않습니다.',
    ],
    'settings' => [
        'redirect_url_format' => '결제 후 이동 주소는 {shopBase}, / 또는 http(s):// 로 시작해야 하며 공백을 넣을 수 없습니다. 다른 사이트로 보내는 //주소 와 javascript: 는 쓸 수 없습니다.',
    ],
];
