<?php

return [
    /*
     * 발급 용도
     */
    'type' => [
        'income' => '소득공제용',
        'expense' => '지출증빙용',
    ],

    /*
     * 식별번호 종류
     */
    'identifier_type' => [
        'phone' => '휴대폰번호',
        'card' => '현금영수증카드번호',
        'business' => '사업자등록번호',
    ],

    /*
     * 이력 거래 유형
     */
    'transaction_type' => [
        'issue' => '발급',
        'cancel' => '취소',
    ],

    /*
     * 발급 상태
     */
    'issue_status' => [
        'in_progress' => '처리 중',
        'completed' => '발급 완료',
        'failed' => '발급 실패',
    ],

    /*
     * 이력 표의 결과 열 — 발급 행과 취소 행이 같은 열을 공유하므로 거래유형과 무관한 중립 표현을 쓴다.
     * (취소 행에 "발급 완료" 라고 쓰면 관리자가 오해한다)
     */
    'result_status' => [
        'in_progress' => '처리 중',
        'completed' => '완료',
        'failed' => '실패',
    ],

    /*
     * 배송비 과세 정책
     */
    'shipping_fee_tax_policy' => [
        'proportional' => '안분 (과세상품 비율만큼 과세)',
        'taxable' => '전액 과세',
        'follow_main_item' => '주된 재화를 따름',
    ],

    /*
     * 입력 필드 표시명
     */
    'attributes' => [
        'receipt_type' => '발급 용도',
        'identifier_type' => '발급 수단',
        'identifier' => '식별번호',
    ],

    /*
     * 검증 메시지
     */
    'validation' => [
        'identifier_invalid' => '식별번호 형식이 올바르지 않습니다.',
        'identifier_type_not_allowed' => ':type 은(는) :identifier_type 으로 발급할 수 없습니다.',
        'self_issue_income_only' => '자진발급 지정번호는 소득공제용으로만 발급할 수 있습니다.',
        'identifier_format' => [
            'phone' => '휴대폰번호는 010, 011, 016, 017, 018, 019 로 시작하는 10~11자리 숫자여야 합니다.',
            'card' => '현금영수증 카드번호는 13~19자리 숫자여야 합니다.',
            'business' => '사업자등록번호가 올바르지 않습니다. 10자리 숫자를 확인해 주세요.',
        ],
    ],

    /*
     * 오류 메시지
     */
    'errors' => [
        'provider_not_configured' => '현금영수증 발급 프로바이더가 설정되지 않았습니다.',
        'no_provider_handled' => '현금영수증 발급 요청을 처리한 프로바이더가 없습니다.',
        'no_issuable_amount' => '발급 가능한 현금성 금액이 없습니다.',
        'identifier_unavailable' => '재발급에 필요한 식별번호를 복호화할 수 없습니다. 관리자가 식별번호를 다시 입력해 발급해야 합니다.',
        'payment_not_found' => '결제 정보를 찾을 수 없습니다.',
        'not_cash_payment' => '무통장입금 주문만 현금영수증을 발급할 수 있습니다.',
        'payment_not_paid' => '입금이 확인된 주문만 현금영수증을 발급할 수 있습니다.',
        'already_issued' => '이미 현금영수증이 발급된 주문입니다.',
        'issue_failed' => '현금영수증 발급에 실패했습니다.',
        'cancel_failed' => '현금영수증 취소에 실패했습니다.',
        'no_active_receipt' => '취소할 현금영수증이 없습니다.',
    ],

    /*
     * 성공 메시지
     */
    'messages' => [
        'issued' => '현금영수증이 발급되었습니다.',
        'cancelled' => '현금영수증이 취소되었습니다.',
        'reissued' => '현금영수증이 재발급되었습니다.',
        'status_retrieved' => '현금영수증 정보를 조회했습니다.',
    ],
];
