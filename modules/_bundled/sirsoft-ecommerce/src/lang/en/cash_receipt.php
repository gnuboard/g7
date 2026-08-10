<?php

return [
    /*
     * 발급 용도
     */
    'type' => [
        'income' => 'Income Deduction',
        'expense' => 'Expenditure Proof',
    ],

    /*
     * 식별번호 종류
     */
    'identifier_type' => [
        'phone' => 'Mobile Number',
        'card' => 'Cash Receipt Card Number',
        'business' => 'Business Registration Number',
    ],

    /*
     * 이력 거래 유형
     */
    'transaction_type' => [
        'issue' => 'Issue',
        'cancel' => 'Cancel',
    ],

    /*
     * 발급 상태
     */
    'issue_status' => [
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    /*
     * Ledger result column — issue and cancel rows share this column,
     * so the wording must be neutral with respect to the transaction type.
     */
    'result_status' => [
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ],

    /*
     * 배송비 과세 정책
     */
    'shipping_fee_tax_policy' => [
        'proportional' => 'Proportional (by taxable item ratio)',
        'taxable' => 'Fully Taxable',
        'follow_main_item' => 'Follow Main Item',
    ],

    /*
     * 입력 필드 표시명
     */
    'attributes' => [
        'receipt_type' => 'Receipt Purpose',
        'identifier_type' => 'Identifier Type',
        'identifier' => 'Identifier',
    ],

    /*
     * 검증 메시지
     */
    'validation' => [
        'identifier_invalid' => 'The identifier format is invalid.',
        'identifier_type_not_allowed' => ':type cannot be issued with a :identifier_type.',
        'self_issue_income_only' => 'The self-issuance number can only be used for income deduction receipts.',
        'identifier_format' => [
            'phone' => 'The mobile number must be 10 to 11 digits starting with 010, 011, 016, 017, 018 or 019.',
            'card' => 'The cash receipt card number must be 13 to 19 digits.',
            'business' => 'The business registration number is invalid. Please check the 10 digits.',
        ],
    ],

    /*
     * 오류 메시지
     */
    'errors' => [
        'provider_not_configured' => 'No cash receipt provider is configured.',
        'no_provider_handled' => 'No provider handled the cash receipt request.',
        'no_issuable_amount' => 'There is no issuable cash-equivalent amount.',
        'identifier_unavailable' => 'The identifier required for re-issuance could not be decrypted. An administrator must re-enter the identifier to issue the receipt.',
        'payment_not_found' => 'Payment information could not be found.',
        'not_cash_payment' => 'Only bank transfer (dbank) orders can be issued a cash receipt.',
        'payment_not_paid' => 'Only orders with a confirmed deposit can be issued a cash receipt.',
        'already_issued' => 'A cash receipt has already been issued for this order.',
        'issue_failed' => 'Failed to issue the cash receipt.',
        'cancel_failed' => 'Failed to cancel the cash receipt.',
        'no_active_receipt' => 'There is no cash receipt to cancel.',
    ],

    /*
     * 성공 메시지
     */
    'messages' => [
        'issued' => 'The cash receipt has been issued.',
        'cancelled' => 'The cash receipt has been cancelled.',
        'reissued' => 'The cash receipt has been re-issued.',
        'status_retrieved' => 'The cash receipt information has been retrieved.',
    ],
];
