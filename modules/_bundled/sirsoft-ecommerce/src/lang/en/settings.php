<?php

/**
 * Ecommerce settings catalog labels (English)
 *
 * Lang keys referenced by multilingual JSON data in `config/settings/defaults.json`
 * (e.g. `name` field) as a fallback when the active locale key is missing.
 *
 * Usage (layout JSON):
 *   {{$localized(country.name, 'sirsoft-ecommerce::settings.countries.' + country.code + '.name')}}
 *   {{$localized(currency.name, 'sirsoft-ecommerce::settings.currencies.' + currency.code + '.name')}}
 *
 * When an active language pack (e.g. ja) carries the same keys, the active locale label
 * is shown automatically.
 */
return [
    // Available shipping countries (defaults.json shipping.available_countries)
    'countries' => [
        'KR' => ['name' => 'South Korea'],
        'US' => ['name' => 'United States'],
        'JP' => ['name' => 'Japan'],
        'CN' => ['name' => 'China'],
        'SG' => ['name' => 'Singapore'],
        'HK' => ['name' => 'Hong Kong'],
        'TW' => ['name' => 'Taiwan'],
        'VN' => ['name' => 'Vietnam'],
        'TH' => ['name' => 'Thailand'],
        'MY' => ['name' => 'Malaysia'],
    ],

    // Currencies (defaults.json language_currency.currencies)
    'currencies' => [
        'KRW' => ['name' => 'KRW (Won)'],
        'USD' => ['name' => 'USD (Dollar)'],
        'JPY' => ['name' => 'JPY (Yen)'],
        'CNY' => ['name' => 'CNY (Yuan)'],
        'EUR' => ['name' => 'EUR (Euro)'],
    ],

    // Payment methods (defaults.json order_settings.payment_methods id-based)
    'payment_methods' => [
        'card' => [
            'name' => 'Credit Card',
            'description' => 'Pay securely with credit card',
        ],
        'vbank' => [
            'name' => 'Virtual Account',
            'description' => 'Pay via virtual account',
        ],
        'dbank' => [
            'name' => 'Bank Transfer',
            'description' => 'Direct bank transfer',
        ],
        'bank' => [
            'name' => 'Account Transfer',
            'description' => 'Real-time bank transfer',
        ],
        'phone' => [
            'name' => 'Mobile Payment',
            'description' => 'Mobile phone payment',
        ],
        'point' => [
            'name' => 'Points',
            'description' => 'Pay with points',
        ],
        'deposit' => [
            'name' => 'Store Credit',
            'description' => 'Pay with store credit',
        ],
        'free' => [
            'name' => 'Free',
            'description' => 'Order without payment',
        ],
    ],

    // Banks (defaults.json order_settings.bank_codes — used for bank transfer)
    'banks' => [
        '004' => ['name' => 'Kookmin Bank'],
        '088' => ['name' => 'Shinhan Bank'],
        '020' => ['name' => 'Woori Bank'],
        '081' => ['name' => 'Hana Bank'],
        '003' => ['name' => 'IBK Industrial Bank'],
        '011' => ['name' => 'NH Nonghyup Bank'],
        '071' => ['name' => 'Korea Post'],
    ],
];
