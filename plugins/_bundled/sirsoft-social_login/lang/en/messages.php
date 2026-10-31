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
    'prune' => [
        'dry_run' => 'Records to prune (nothing was deleted).',
        'done' => 'Records past their retention were pruned.',
        'target' => 'Target',
        'count' => 'Count',
        'targets' => [
            'profile_raw' => 'Raw profile copies',
            'attempts' => 'Sign-in attempt logs',
            'webhook_events' => 'Webhook event history',
            'mock_flag' => 'Expired mock provider flag',
        ],
    ],
    'data_deletion' => [
        'title' => 'Facebook Data Deletion Request',
        'not_found' => 'No deletion request matches this confirmation code.',
        'pending' => 'The deletion request was received and is being processed.',
        'done' => 'The Facebook link data stored on this site was deleted.',
        'failed' => 'The request could not be processed. Please contact the site administrator.',
        'code' => 'Confirmation code',
        'received_at' => 'Received at',
        'processed_at' => 'Processed at',
        'note' => 'Deleting the member account itself follows the site policy. To leave the site, sign in and withdraw your account.',
    ],
    'admin' => [
        'providers' => [
            'listed' => 'Social login provider settings.',
            'updated' => 'Provider settings saved.',
            'created' => 'Generic OIDC provider added. Turn it on after a connection test.',
            'deleted' => 'Provider deleted.',
            'reordered' => 'Display order saved.',
            'discovered' => 'Endpoints read from the discovery document. Review them and save.',
            'impact' => 'Impact of the provider change.',
            'tested' => 'Connection test finished.',
        ],
        'diagnostics' => [
            'loaded' => 'Diagnostics.',
            'attempts' => 'Social login attempts.',
            'webhook_events' => 'Provider notice history.',
        ],
        'stats' => [
            'loaded' => 'Social login stats.',
        ],
        'user_accounts' => [
            'listed' => 'Social account links of the member.',
            'options' => 'Providers that have linked members.',
            'unlinked' => 'The member\'s social account link was removed.',
        ],
    ],
    'reauth' => [
        'provider_label' => 'Confirm with social account',
        'channel_label' => 'Social sign-in',
        'started' => 'Moving to the provider screen.',
        'verified' => 'Your identity is confirmed.',
    ],
    // 알림 편집 화면 「사용 가능한 변수」 설명 — ko 는 plugin.php 선언 문자열과 글자 단위로 같게 둔다
    'notification_variables' => [
        'common' => [
            'app_name' => 'Site name',
            'name' => 'Member name',
            'provider_label' => 'Provider name',
            'site_url' => 'Site address',
            'reset_url' => 'Forgot-password screen address',
            'failure_count' => 'Failures in the last 10 minutes',
            'last_error_code' => 'Last error code',
            'diagnostics_url' => 'Diagnostics screen address',
            'count' => 'Signups in this hour',
            'threshold' => 'Alert threshold',
            'stats_url' => 'Statistics screen address',
            'event_type' => 'Notification type',
            'error' => 'Error summary (no secrets)',
        ],
        'by_type' => [
            'social_login_provider_failing' => [
                'name' => 'Recipient (administrator) name',
            ],
            'social_login_signup_surge' => [
                'name' => 'Recipient (administrator) name',
            ],
            'social_login_webhook_failed' => [
                'name' => 'Recipient (administrator) name',
            ],
        ],
    ],
];
