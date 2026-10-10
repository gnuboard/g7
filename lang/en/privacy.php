<?php

return [
    // Retention handler labels — shared by command output and the dry-run badge on the settings screen.
    'handlers' => [
        'user_withdrawn' => 'Residual personal data of withdrawn members',
    ],

    // Target list modal — column labels and target list API errors.
    'targets' => [
        'columns' => [
            'name' => 'Name',
            'email' => 'Email',
            'withdrawn_at' => 'Withdrawn on',
            'last_activity_at' => 'Last activity',
            'dormant_at' => 'Dormant since',
        ],
        'unknown_handler' => 'This personal data processing target is not registered.',
        'unknown_stage' => 'Unknown dormant stage.',
    ],

    // Manual run by an administrator — the "Run now" button on settings cards (queue → process → result on the card).
    'manual_run' => [
        'queued' => 'The run has been queued. The result will appear on the card when processing finishes.',
        'unknown_target' => 'This manual run target is not registered.',
        'target_missing' => 'The target disappeared after the run was queued, so it could not run (the extension may have been deactivated).',
        'stale' => 'Marked as failed because it did not finish within :minutes minutes. Check that a queue worker is running.',
        'status' => [
            'queued' => 'Queued',
            'running' => 'Running',
            'completed' => 'Completed',
            'failed' => 'Failed',
        ],
        'dormant_stages' => [
            'pre_notice' => 'Advance notice before dormancy',
            'convert' => 'Convert to dormant',
            'withdraw_notice' => 'Notice before withdrawing dormant accounts',
            'withdraw' => 'Withdraw dormant accounts',
        ],
        // Password expiry (#736) — security tab "Run now"
        'password_expiry_stages' => [
            'notice' => 'Password expiry advance notice',
            'expire' => 'Password expiry processing',
        ],
    ],
    // Public consent snapshot (GET /api/consent) response message.
    'consent' => [
        'fetch_success' => 'Checked the visitor consent status.',
    ],
];
