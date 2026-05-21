<?php

return [
    'status' => [
        'received' => 'Received',
        'quoted' => 'Quoted',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'canceled' => 'Canceled',
    ],
    'message' => [
        'quote_issued' => 'Operator issued a quote (version #:version, total :total KRW).',
        'quote_revoked' => 'Operator revoked the quote (version #:version).',
        'quote_rejected' => 'Client rejected the quote (version #:version).',
        'payment_confirmed' => 'Payment has been confirmed.',
        'payment_confirmed_offline' => 'Operator manually confirmed the payment.',
        'completed' => 'Inquiry has been completed.',
        'canceled_by_client' => 'Client canceled the inquiry.',
        'canceled_by_operator' => 'Operator canceled the inquiry.',
    ],
];
