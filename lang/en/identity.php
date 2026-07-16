<?php

return [
    'providers' => [
        'mail' => [
            'label' => 'Email',
            'settings' => [
                'code_length' => 'Verification code length',
                'code_length_help' => 'Number of digits in the verification code (default 6, min 4, max 10).',
                'from_address' => 'From address',
                'from_address_help' => 'Leave blank to use the system default sender.',
            ],
        ],
    ],

    'errors' => [
        'verification_required' => 'Identity verification is required.',
        'challenge_not_found' => 'Invalid verification request.',
        'wrong_provider' => 'This verification request must be handled by a different provider.',
        'invalid_state' => 'This verification request has already been processed.',
        'expired' => 'The verification has expired. Please try again.',
        'max_attempts' => 'Too many attempts. Please request a new code.',
        'invalid_code' => 'The verification code is incorrect.',
        'invalid_verification_token' => 'Invalid verification token.',
        'missing_target' => 'A verification target (email or phone) is required.',
        'target_mismatch' => 'The verified target does not match the requested one.',
        'purpose_not_supported' => 'The selected provider does not support this purpose.',
        'provider_unavailable' => 'Identity verification provider is not available.',
        'generic' => 'Identity verification failed.',
        'missing_scope_or_target' => 'Both scope and target are required to resolve a policy.',
        'admin_policy_has_no_default' => 'Admin-created policies do not have a declared default.',
        'reset_field_failed' => 'Failed to reset the field to its declared default. Check if the field is valid.',
    ],

    'messages' => [
        'challenge_requested' => 'A verification code has been sent.',
        'challenge_verified' => 'Identity verification completed.',
        'challenge_cancelled' => 'Verification request has been cancelled.',
    ],

    'logs' => [
        'activity' => [
            'requested' => 'Identity verification code sent to :email.',
            'verified' => 'Identity verification completed.',
            'failed' => 'Identity verification failed.',
            'expired' => 'Identity verification expired.',
            'cancelled' => 'Identity verification cancelled.',
        ],
    ],

    'purposes' => [
        'signup' => [
            'label' => 'Signup Verification',
            'description' => 'Verify ownership of email/phone for new sign-ups.',
        ],
        'password_reset' => [
            'label' => 'Password Reset',
            'description' => 'Verify identity before resetting a forgotten password.',
        ],
        'self_update' => [
            'label' => 'Self Update',
            'description' => 'Verify identity when a logged-in user changes their own contact info.',
        ],
        'sensitive_action' => [
            'label' => 'Sensitive Action',
            'description' => 'Re-verify before sensitive actions such as account deletion or admin operations.',
        ],
    ],

    'channels' => [
        'email' => 'Email',
    ],

    'origin_types' => [
        'route' => 'Route',
        'hook' => 'Hook',
        'policy' => 'Policy',
        'middleware' => 'Middleware',
        'api' => 'Direct API call',
        'custom' => 'Custom',
        'system' => 'System',
    ],

    'policy' => [
        'scope' => [
            'route' => 'Route',
            'hook' => 'Hook',
            'custom' => 'Custom',
        ],
        'fail_mode' => [
            'block' => 'Block (HTTP 428)',
            'log_only' => 'Log only',
        ],
        'applies_to' => [
            'self' => 'Self',
            'admin' => 'Admin',
            'both' => 'Both',
        ],
        'source_type' => [
            'core' => 'Core',
            'module' => 'Module',
            'plugin' => 'Plugin',
            'admin' => 'Admin',
        ],
    ],

    'message' => [
        'scope_type' => [
            'provider_default' => 'Provider default',
            'purpose' => 'Per purpose',
            'policy' => 'Per policy',
        ],
    ],
];
