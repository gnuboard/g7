<?php

return [
    'attachment' => [
        'disk' => env('INQUIRY_ATTACHMENT_DISK', 'local'),
        'max_size_inquiry' => env('INQUIRY_ATTACHMENT_MAX_INQUIRY', 50 * 1024 * 1024), // 50MB
        'max_size_message' => env('INQUIRY_ATTACHMENT_MAX_MESSAGE', 20 * 1024 * 1024), // 20MB
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
            'application/pdf',
            'application/zip',
            'application/x-zip-compressed',
        ],
        'orphan_cleanup_after_minutes' => 30,
    ],

    'categories' => [
        'web',
        'design',
        'maintenance',
        'consulting',
        'etc',
    ],

    'quote' => [
        'currency' => 'KRW',
        'default_valid_days' => 14,
    ],

    'permissions' => [
        'manage' => 'inquiry.manage',
        'notify' => 'inquiry.notify',
    ],
];
