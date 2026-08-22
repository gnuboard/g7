<?php

declare(strict_types=1);

return [
    'title' => 'NHN KCP Mobile Identity Verification',
    'description' => 'Connects NHN KCP mobile identity verification to the G7 core identity verification infrastructure.',

    'bridge_page_title' => 'Identity verification result',

    'channels' => [
        'phone' => 'Mobile Phone',
    ],

    'settings' => [
        'test_mode' => 'Test Mode',
        'test_site_cd' => 'Test Site Code',
        'test_enc_key' => 'Test Encryption Key',
        'live_site_cd' => 'Live Site Code (SM prefix)',
        'live_enc_key' => 'Live Encryption Key',
        'web_siteid' => 'Web Site ID',
        'live_site_cd_attribute' => 'Live Site Code',
        'live_enc_key_attribute' => 'Live Encryption Key',
        'duplicate_field' => 'Duplicate check basis',
        'duplicate_block_enabled' => [
            'label' => 'Block Duplicate Signup',
            'description' => 'When enabled, a person who already signed up with another email address cannot sign up again after identity verification. Disable it when one person legitimately needs multiple accounts (shared family phone, B2B). Signing up again with the same email is always blocked regardless of this setting (core default).',
        ],
    ],

    'card' => [
        'title' => 'Identity Verification',
        'method' => 'Method',
        'method_value' => 'NHN KCP Mobile Verification',
        'verified_at' => 'Verified At',
        'name' => 'Name',
        'birthday' => 'Date of Birth',
        'phone' => 'Mobile Phone',
        'is_adult' => [
            'label' => 'Adult',
            'true' => 'Adult',
            'false' => 'Minor',
        ],
    ],

    'purposes' => [
        'adult_verification' => [
            'label' => 'Adult Verification (NHN KCP only)',
            'description' => 'Map this purpose to the NHN KCP provider only. Mail/SMS providers do not return a date of birth, so adult status cannot be determined. A wrong mapping may expose age-restricted content to minors.',
        ],
    ],
];
