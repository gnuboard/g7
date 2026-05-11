<?php

/**
 * 이커머스 환경설정 카탈로그 라벨 (Korean)
 *
 * `config/settings/defaults.json` 의 다국어 JSON 데이터(`name` 필드 등) 가
 * 활성 locale 키 부재 시 fallback 으로 참조하는 lang 키.
 *
 * 사용처(레이아웃 JSON):
 *   {{$localized(country.name, 'sirsoft-ecommerce::settings.countries.' + country.code + '.name')}}
 *   {{$localized(currency.name, 'sirsoft-ecommerce::settings.currencies.' + currency.code + '.name')}}
 *
 * 활성 언어팩(예: ja) 이 동일 키를 보유하면 자동으로 활성 locale 라벨이 표시된다.
 */
return [
    // 배송 가능 국가 (defaults.json shipping.available_countries)
    'countries' => [
        'KR' => ['name' => '대한민국'],
        'US' => ['name' => '미국'],
        'JP' => ['name' => '일본'],
        'CN' => ['name' => '중국'],
        'SG' => ['name' => '싱가포르'],
        'HK' => ['name' => '홍콩'],
        'TW' => ['name' => '대만'],
        'VN' => ['name' => '베트남'],
        'TH' => ['name' => '태국'],
        'MY' => ['name' => '말레이시아'],
    ],

    // 통화 (defaults.json language_currency.currencies)
    'currencies' => [
        'KRW' => ['name' => 'KRW (원)'],
        'USD' => ['name' => 'USD (달러)'],
        'JPY' => ['name' => 'JPY (엔)'],
        'CNY' => ['name' => 'CNY (위안)'],
        'EUR' => ['name' => 'EUR (유로)'],
    ],

    // 결제수단 (defaults.json order_settings.payment_methods 의 id 기준)
    'payment_methods' => [
        'card' => [
            'name' => '신용카드',
            'description' => '신용카드로 안전하게 결제',
        ],
        'vbank' => [
            'name' => '가상계좌',
            'description' => '가상계좌로 입금',
        ],
        'dbank' => [
            'name' => '무통장입금',
            'description' => '지정 계좌로 직접 입금',
        ],
        'bank' => [
            'name' => '계좌이체',
            'description' => '실시간 계좌이체',
        ],
        'phone' => [
            'name' => '휴대폰결제',
            'description' => '휴대폰 소액결제',
        ],
        'point' => [
            'name' => '포인트결제',
            'description' => '적립 포인트로 결제',
        ],
        'deposit' => [
            'name' => '예치금결제',
            'description' => '예치금으로 결제',
        ],
        'free' => [
            'name' => '무료',
            'description' => '결제 없이 주문 완료',
        ],
    ],

    // 은행 (defaults.json order_settings.bank_codes — 무통장입금 시 사용)
    'banks' => [
        '004' => ['name' => '국민은행'],
        '088' => ['name' => '신한은행'],
        '020' => ['name' => '우리은행'],
        '081' => ['name' => '하나은행'],
        '003' => ['name' => 'IBK기업은행'],
        '011' => ['name' => 'NH농협은행'],
        '071' => ['name' => '우체국'],
    ],
];
