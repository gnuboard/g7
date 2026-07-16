<?php

declare(strict_types=1);

return [
    'errors' => [
        'cash_receipt_invalid_issue_type' => '발행 유형이 올바르지 않습니다.',
        'cash_receipt_missing_issue_number' => '식별번호를 입력해주세요.',
        'cash_receipt_already_issued' => '이미 현금영수증이 발행된 결제입니다.',
        'cash_receipt_issue_failed' => '현금영수증 발행에 실패했습니다.',
    ],
    'refund' => [
        'missing_tid' => '거래 ID(TID)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
    ],
    'cbt_reconciliation' => [
        'not_retryable' => '재시도 가능한 CBT 환불 대기 건이 아닙니다.',
        'retry_success' => 'CBT 환불 재시도가 완료되었습니다.',
        'retry_failed' => 'CBT 환불 재시도에 실패했습니다.',
    ],
    'settings_validation' => [
        'test_japan_sign_key_required' => '일본 결제(CBT)를 사용하려면 테스트 일본 CBT 해시키가 필요합니다.',
        'live_japan_mid_required' => '운영 모드에서 일본 결제(CBT)를 사용하려면 라이브 일본 MID가 필요합니다.',
        'live_japan_sign_key_required' => '운영 모드에서 일본 결제(CBT)를 사용하려면 라이브 일본 CBT 해시키가 필요합니다.',
        'japan_merchant_name_required' => '운영 일본 결제창에 표시할 가맹점명이 필요합니다.',
        'japan_merchant_name_kana_required' => '운영 일본 결제창에 표시할 가맹점명 Kana가 필요합니다.',
        'japan_merchant_name_alphabet_required' => '운영 일본 결제창에 표시할 영문 가맹점명이 필요합니다.',
        'japan_merchant_name_short_required' => '운영 일본 결제창에 표시할 가맹점 약칭이 필요합니다.',
        'japan_contact_name_required' => '운영 일본 결제창에 표시할 문의처명이 필요합니다.',
        'japan_contact_email_required' => '운영 일본 결제창에 표시할 문의 이메일이 필요합니다.',
        'japan_contact_phone_required' => '운영 일본 결제창에 표시할 문의 전화번호가 필요합니다.',
        'japan_contact_opening_hours_required' => '운영 일본 결제창에 표시할 문의 영업시간이 필요합니다.',
        'replace_sample_value' => '운영 모드에서는 일본 가맹점 표시 정보의 샘플값을 실제 계약 정보로 변경하세요.',
    ],
    'defaults' => [
        'good_name' => '상품',
    ],
];
