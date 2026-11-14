<?php

return [
    'provider' => [
        'label' => 'Authenticator App (OTP)',
    ],

    'enrollment' => [
        'started' => 'Enrollment details are ready. Scan the QR code with your authenticator app or enter the key manually.',
        'confirmed' => 'Your authenticator app is now enrolled. Keep the backup codes somewhere safe.',
        'disabled' => 'The authenticator app has been unenrolled.',
        'backup_codes_regenerated' => 'New backup codes have been issued. The previous codes no longer work.',
        'admin_reset' => 'The member\'s authenticator app enrollment has been removed.',
        'admin_reset_none' => 'This member has no authenticator app enrolled, so there was nothing to remove.',
    ],

    'errors' => [
        'already_enrolled' => 'An authenticator app is already enrolled. Unenroll it first to enroll again.',
        'invalid_code' => 'That code is not correct. Check the current code in your authenticator app, or use a backup code.',
        'not_enrolled' => 'No authenticator app is enrolled.',
        'password_incorrect' => 'The current password is not correct.',
        'too_many_attempts' => 'Too many code attempts. Try again in :seconds seconds.',
        'confirm_conflict' => 'Enrollment was already confirmed in another window, or a new secret was issued. Refresh the page and try again.',
    ],

    'validation' => [
        'code_required' => 'Enter the code.',
        'code_invalid' => 'The code format is not valid.',
        'code_attribute' => 'verification code',
        'current_password_required' => 'Enter your current password.',
        'current_password_max' => 'The current password may not be longer than 255 characters.',
        'current_password_attribute' => 'current password',
    ],

    'permissions' => [
        'credentials_delete' => 'Remove member authenticator enrollment',
    ],

    // Security notifications (1.0.3) — who made the change
    'notifications' => [
        'actor_self' => 'You',
        'actor_admin' => 'Administrator',
        'actor_console' => 'Server administration command',
    ],

    // Notification template editor "Available variables" descriptions — common: default per variable key
    'notification_variables' => [
        'common' => [
            'name' => 'Recipient (account owner) name',
            'app_name' => 'Site name',
            'occurred_at' => 'Time of change',
            'ip_address' => 'Request IP',
            'actor_label' => 'Who made the change (you, administrator, server shell)',
            'security_url' => 'Authenticator app settings page URL',
        ],
    ],
    // Console command output
    'console' => [
        'seed_totp' => [
            'cli_only' => 'This command can only be run from the CLI. (Current SAPI: :sapi)',
            'bypass_required' => 'The G7_PLAYWRIGHT_BYPASS=1 environment variable is required. (e.g. PowerShell — :example)',
            'mode_required' => 'Specify either --enroll or --code.',
            'account_not_found' => 'Account not found: :email',
        ],
        'reset' => [
            'user_not_found' => 'User not found: :email',
            'not_enrolled' => 'This user has no authenticator app enrolled: :email',
            'done' => 'The authenticator app has been unenrolled: :email',
            'next_login_notice' => 'From the next login, this user will not be asked for an authenticator app code (if email fallback is on, the code is sent by email).',
        ],
        'password_reset_notice' => 'This user has an authenticator app enrolled — if it was lost, reset it: php artisan totp:reset :email',
    ],
];
