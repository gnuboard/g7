<?php

return [
    'toss_card' => [
        'name' => 'Credit Card (TossPayments)',
        'description' => 'Pay with a credit or check card — processed via TossPayments',
    ],
    'toss_virtual_account' => [
        'name' => 'Virtual Account (TossPayments)',
        'description' => 'Deposit to an issued virtual account — processed via TossPayments',
    ],
    'toss_transfer' => [
        'name' => 'Bank Transfer (TossPayments)',
        'description' => 'Pay by real-time bank transfer — processed via TossPayments',
    ],
    'toss_mobile_phone' => [
        'name' => 'Mobile Phone (TossPayments)',
        'description' => 'Mobile carrier billing — processed via TossPayments',
    ],
    'toss_tosspay' => [
        'name' => 'TossPay (TossPayments)',
        'description' => 'TossPay easy payment — processed via TossPayments',
    ],
    'toss_kakaopay' => [
        'name' => 'KakaoPay (TossPayments)',
        'description' => 'KakaoPay easy payment — processed via TossPayments',
    ],
    'toss_naverpay' => [
        'name' => 'NaverPay (TossPayments)',
        'description' => 'NaverPay easy payment — processed via TossPayments',
    ],
    'toss_payco' => [
        'name' => 'PAYCO (TossPayments)',
        'description' => 'PAYCO easy payment — processed via TossPayments',
    ],
    'toss_samsungpay' => [
        'name' => 'Samsung Pay (TossPayments)',
        'description' => 'Samsung Pay easy payment — processed via TossPayments',
    ],
    'toss_applepay' => [
        'name' => 'Apple Pay (TossPayments)',
        'description' => 'Apple Pay easy payment — processed via TossPayments · shown on iPhone/iPad only',
    ],
    'toss_lpay' => [
        // A zero-width space (U+200B) keeps "L.pay" from being auto-linked as a URL (same as NicePayments)
        'name' => 'L.'."\u{200B}".'pay (TossPayments)',
        'description' => 'L.'."\u{200B}".'pay easy payment — processed via TossPayments',
    ],
    'toss_ssgpay' => [
        'name' => 'SSG Pay (TossPayments)',
        'description' => 'SSG Pay easy payment — processed via TossPayments',
    ],
];
