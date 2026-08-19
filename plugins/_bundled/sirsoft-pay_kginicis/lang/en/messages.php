<?php

declare(strict_types=1);

return [
    'errors' => [
        'cash_receipt_issue_failed' => 'Failed to issue cash receipt.',
        'order_not_found' => 'Order not found.',
        'cbt_failed' => 'Failed to process the cross-border payment.',
        'payment_failed' => 'Payment could not be completed. Please try again in a moment.',
    ],
    'cash_receipt' => [
        'provider_name' => 'KG Inicis',
    ],
    'escrow' => [
        'invoice_required' => 'Please enter the tracking number.',
        'courier_required' => 'Please select a courier.',
        'default_confirmer' => 'Administrator',
    ],
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction ID (TID) is missing.',
        'default_reason' => 'Buyer refund request',
    ],
    'cbt_connectivity' => [
        'checked' => 'Connectivity diagnostic completed.',
        'check_failed' => 'Connectivity diagnostic failed.',
    ],
    'cbt_reconciliation' => [
        'not_retryable' => 'This CBT refund item is not retryable.',
        'retry_success' => 'CBT refund retry completed.',
        'retry_failed' => 'CBT refund retry failed.',
    ],
    'settings_validation' => [
        'test_japan_sign_key_required' => 'The test Japan CBT hash key is required to use Japan payment (CBT).',
        'live_japan_mid_required' => 'The live Japan MID is required to use Japan payment (CBT) in live mode.',
        'live_japan_sign_key_required' => 'The live Japan CBT hash key is required to use Japan payment (CBT) in live mode.',
        'japan_merchant_name_required' => 'Merchant name for the live Japan payment window is required.',
        'japan_merchant_name_kana_required' => 'Merchant name Kana for the live Japan payment window is required.',
        'japan_merchant_name_alphabet_required' => 'Alphabet merchant name for the live Japan payment window is required.',
        'japan_merchant_name_short_required' => 'Short merchant name for the live Japan payment window is required.',
        'japan_contact_name_required' => 'Contact name for the live Japan payment window is required.',
        'japan_contact_email_required' => 'Contact email for the live Japan payment window is required.',
        'japan_contact_phone_required' => 'Contact phone for the live Japan payment window is required.',
        'japan_contact_opening_hours_required' => 'Contact opening hours for the live Japan payment window are required.',
        'replace_sample_value' => 'Replace the sample Japan merchant display information with your real contract information in live mode.',
    ],
    'defaults' => [
        'good_name' => 'Goods',
    ],
    'cbt_cvs' => [
        'simulate_success' => 'Deposit simulation completed.',
        'simulate_failed' => 'Deposit simulation failed.',
        'expire_success' => 'The virtual account deposit deadline has been expired.',
        'recheck_success' => 'Deposit status has been rechecked.',
        'not_test_mode' => 'This is available in test mode only.',
        'not_waiting_deposit' => 'Only payments awaiting deposit can be processed.',
        'not_expirable' => 'This payment cannot be expired.',
        'not_cvs' => 'This is not a convenience store or virtual account payment.',
    ],
];
