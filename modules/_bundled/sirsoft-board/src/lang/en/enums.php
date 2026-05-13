<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enum Translations (English)
    |--------------------------------------------------------------------------
    */

    // Secret Mode
    'secret_mode' => [
        'disabled' => 'Disabled',
        'enabled' => 'Optional',
        'always' => 'Always Required',
    ],

    // Order Direction
    'order_direction' => [
        'asc' => 'Ascending',
        'desc' => 'Descending',
    ],

    // Board Order By
    'board_order_by' => [
        'created_at' => 'Created At',
        'view_count' => 'View Count',
        'title' => 'Title',
        'author' => 'Author',
    ],

    // Report Type
    'report_type' => [
        'post' => 'Post',
        'comment' => 'Comment',
    ],

    // Report Reason Type
    'report_reason_type' => [
        'abuse' => 'Abuse/Harassment',
        'hate_speech' => 'Hate Speech',
        'spam' => 'Spam/Advertisement',
        'copyright' => 'Copyright Infringement',
        'privacy' => 'Privacy Violation',
        'misinformation' => 'Misinformation',
        'sexual' => 'Sexual Content',
        'violence' => 'Violent Content',
        'other' => 'Other',
    ],

    // Report Status
    'report_status' => [
        'pending' => 'Received',
        'review' => 'Review',
        'rejected' => 'Rejected',
        'suspended' => 'Suspended',
        'deleted' => 'Permanently Deleted',
    ],

    // Trigger Type
    'trigger_type' => [
        'report' => 'Report Action',
        'admin' => 'Admin Manual',
        'system' => 'System',
        'auto_hide' => 'Auto Hide',
        'user' => 'User Deleted',
    ],

    // Post Status
    'post_status' => [
        'published' => 'Published',
        'blinded' => 'Blinded',
        'deleted' => 'Deleted',
    ],
];
