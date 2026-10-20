<?php

return [
    'test_login' => [
        'started' => 'Connection test started. Finish the provider consent in the opened window.',
    ],
    'errors' => [
        'provider_unknown' => 'The provider was not found or is not available.',
    ],
    'redirect_notes' => [
        'exact_match' => 'The callback URL you register in the provider console must match the URL above exactly.',
    ],
    'test_result' => [
        'title' => 'Social Login Connection Test Result',
        'expired' => 'There is no result, or it was already viewed. A result can be viewed only once. Start the test again from the admin screen.',
        'success' => 'Connected. The provider sent the information below.',
        'failure' => 'Connection failed. Error code: :code',
        'provider' => 'Provider',
        'provider_user_id' => 'Provider user ID',
        'email' => 'Email',
        'email_verified' => 'Email verification',
        'verified_yes' => 'Verified',
        'verified_no' => 'Not verified',
        'verified_unknown' => 'Not reported by the provider',
        'name' => 'Name',
        'nickname' => 'Nickname',
        'received_fields' => 'Received fields',
        'missing_required' => 'Missing for sign-up',
        'missing_none' => 'None',
        'raw_keys' => 'Provider raw keys',
        'tested_at' => 'Tested at',
        'note' => 'The test does not sign you in. You can close this window.',
    ],
    'mock' => [
        'title' => 'Mock Provider Consent',
        'notice' => 'Mock provider for development and QA (response shape: :style). No request reaches a real provider.',
        'variant' => 'Response variant',
        'email' => 'Email',
        'subject' => 'Provider user ID',
        'approve' => 'Agree and continue',
        'deny' => 'Deny',
        'label' => 'Mock :provider',
    ],
    'mock_cli' => [
        'choose_one' => 'Specify exactly one of --enable, --disable or --status.',
        'minutes_range' => '--minutes must be between 1 and :max.',
        'enabled' => 'Mock providers enabled. They turn off automatically at :until.',
        'disabled' => 'Mock providers disabled.',
        'status_on' => 'Mock providers are on until :until.',
        'status_off' => 'Mock providers are off.',
    ],
    'providers' => [
        'listed' => 'Social sign-in providers.',
    ],
    'signup' => [
        'pending' => 'Pending signup details.',
        'code_sent' => 'We sent a verification code.',
        'email_verified' => 'Your email is verified.',
        'completed' => 'Your signup is complete.',
    ],
    'link_existing' => [
        'linked' => 'The social account is linked to your existing account.',
    ],
    'accounts' => [
        'listed' => 'Linked social accounts.',
        'link_started' => 'Moving to the provider screen.',
        'unlinked' => 'The social account is unlinked.',
    ],
    'reauth' => [
        'provider_label' => 'Confirm with social account',
        'channel_label' => 'Social sign-in',
        'started' => 'Moving to the provider screen.',
        'verified' => 'Your identity is confirmed.',
    ],
];
