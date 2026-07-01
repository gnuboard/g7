<?php

declare(strict_types=1);

return [
    'title' => 'KG Inicis Identity Verification',
    'description' => 'Plugin that connects KG Inicis identity verification service to G7 core IDV infrastructure.',

    'channels' => [
        'ipin' => 'i-PIN',
    ],

    'settings' => [
        'test_mode' => 'Test Mode',
        'live_mid' => 'Live MID (SRA prefix)',
        'live_api_key' => 'Live API Key',
        // Short field labels for validation error messages (without "(SRA prefix)" hint).
        'live_mid_attribute' => 'Live MID',
        'live_api_key_attribute' => 'Live API Key',
        'duplicate_field' => 'Duplicate Identifier',
        'duplicate_block_enabled' => [
            'label' => 'Duplicate Registration Block',
            'description' => 'When enabled, blocks registration if the verified person has previously registered with a different email. Disable for scenarios such as shared family phones or B2B sites where one person legitimately operates multiple accounts. Regardless of this setting, re-registration with the same email is always blocked (core default).',
        ],
    ],

    'card' => [
        'title' => 'Identity Verification',
        'method' => 'Method',
        'method_value' => 'KG Inicis Verification',
        'status' => 'Status',
        'status_verified' => '✓ Verified',
        'verified_at' => 'Verified At',
        'name' => 'Name',
        'birthday' => 'Birthday',
        'phone' => 'Phone',
        'is_adult' => [
            'label' => 'Adult',
            'true' => 'Adult',
            'false' => 'Minor',
        ],
    ],

    'purposes' => [
        'adult_verification' => [
            'label' => 'Adult Verification (KG Inicis only)',
            'description' => 'Must be mapped to the KG Inicis provider only. Mail/SMS providers do not return birthdate, so adult status cannot be determined. Incorrect mapping may expose adult content to non-adult users.',
        ],
    ],
];
