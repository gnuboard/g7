<?php

/**
 * 토스페이먼츠 플러그인 메시지 (Korean)
 */
return [
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
];
