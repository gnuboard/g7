<?php

declare(strict_types=1);

return [
    'errors' => [
        'cash_receipt_issue_failed' => '현금영수증 발행에 실패했습니다.',
        'order_not_found' => '주문을 찾을 수 없습니다.',
        'cbt_failed' => '해외결제 처리에 실패했습니다.',
    ],
    'cash_receipt' => [
        'provider_name' => 'KG이니시스',
    ],
    'refund' => [
        'missing_tid' => '거래 ID(TID)가 없어 환불을 진행할 수 없습니다.',
        'default_reason' => '구매자 환불 요청',
    ],
    'cbt_connectivity' => [
        'checked' => '연결 진단이 완료되었습니다.',
        'check_failed' => '연결 진단에 실패했습니다.',
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
    'cbt_cvs' => [
        'simulate_success' => '입금 시뮬레이션이 완료되었습니다.',
        'simulate_failed' => '입금 시뮬레이션에 실패했습니다.',
        'expire_success' => '가상계좌 입금 기한이 만료 처리되었습니다.',
        'recheck_success' => '입금 상태를 다시 확인했습니다.',
        'not_test_mode' => '테스트 모드에서만 사용할 수 있습니다.',
        'not_waiting_deposit' => '입금 대기 상태의 결제만 처리할 수 있습니다.',
        'not_expirable' => '만료 처리할 수 없는 결제입니다.',
        'not_cvs' => '편의점·가상계좌 결제가 아닙니다.',
    ],
];
