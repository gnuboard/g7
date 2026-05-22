<?php

return [
    'inquiry_received' => [
        'subject' => '[Inquiry] New inquiry received',
        'greeting' => 'Hello',
        'line' => ':title — A new inquiry has been submitted by :client.',
        'action' => 'View Inquiry',
    ],
    'quote_issued' => [
        'subject' => '[Inquiry] Quote issued',
        'line' => ':title — Quote #:version (Total: :total KRW) has been issued.',
        'action' => 'View Quote',
    ],
    'quote_revoked' => [
        'subject' => '[Inquiry] Quote revoked',
        'line' => ':title — Quote #:version has been revoked by the operator.',
        'action' => 'View Inquiry',
    ],
    'payment_confirmed' => [
        'subject' => '[Inquiry] Payment confirmed, work starting',
        'line' => ':title — Payment confirmed. Work has begun.',
        'action' => 'View Inquiry',
    ],
    'inquiry_completed' => [
        'subject' => '[Inquiry] Inquiry completed',
        'line' => ':title — The operator has marked this inquiry as completed.',
        'action' => 'View Inquiry',
    ],
    'inquiry_canceled' => [
        'subject' => '[Inquiry] Inquiry canceled',
        'line_by_client' => ':title — The client has canceled this inquiry.',
        'line_by_operator' => ':title — This inquiry has been canceled by the operator.',
        'action' => 'View Inquiry',
    ],
    'new_message' => [
        'subject' => '[Inquiry] New message received',
        'line' => ':title — :sender has left a new message.',
        'action' => 'View Inquiry',
    ],
];
