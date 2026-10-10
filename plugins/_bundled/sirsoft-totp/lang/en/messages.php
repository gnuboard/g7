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
    ],

    'validation' => [
        'code_required' => 'Enter the code.',
        'code_invalid' => 'The code format is not valid.',
        'code_attribute' => 'verification code',
    ],

    'permissions' => [
        'credentials_delete' => 'Remove member authenticator enrollment',
    ],
];
