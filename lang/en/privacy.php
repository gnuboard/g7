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
];
