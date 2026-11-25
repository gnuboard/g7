<?php

return [
    'toss_card' => [
        'name' => '신용카드 (토스페이먼츠)',
        'description' => '신용·체크카드로 결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_virtual_account' => [
        'name' => '가상계좌 (토스페이먼츠)',
        'description' => '발급된 가상계좌로 입금 — 토스페이먼츠를 통해 처리',
    ],
    'toss_transfer' => [
        'name' => '계좌이체 (토스페이먼츠)',
        'description' => '실시간 계좌이체로 결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_mobile_phone' => [
        'name' => '휴대폰결제 (토스페이먼츠)',
        'description' => '휴대폰 소액결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_tosspay' => [
        'name' => '토스페이 (토스페이먼츠)',
        'description' => '토스페이 간편결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_kakaopay' => [
        'name' => '카카오페이 (토스페이먼츠)',
        'description' => '카카오페이 간편결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_naverpay' => [
        'name' => '네이버페이 (토스페이먼츠)',
        'description' => '네이버페이 간편결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_payco' => [
        'name' => '페이코 (토스페이먼츠)',
        'description' => '페이코 간편결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_samsungpay' => [
        'name' => '삼성페이 (토스페이먼츠)',
        'description' => '삼성페이 간편결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_applepay' => [
        'name' => '애플페이 (토스페이먼츠)',
        'description' => '애플페이 간편결제 — 토스페이먼츠를 통해 처리 · 아이폰·아이패드에서만 보입니다',
    ],
    'toss_lpay' => [
        // 폭 없는 공백(U+200B)은 「L.pay」 가 주소로 자동 링크되지 않게 한다 (나이스페이먼츠 표기와 같다)
        'name' => 'L.'."\u{200B}".'pay (토스페이먼츠)',
        'description' => 'L.'."\u{200B}".'pay 간편결제 — 토스페이먼츠를 통해 처리',
    ],
    'toss_ssgpay' => [
        'name' => 'SSG페이 (토스페이먼츠)',
        'description' => 'SSG페이 간편결제 — 토스페이먼츠를 통해 처리',
    ],
];
