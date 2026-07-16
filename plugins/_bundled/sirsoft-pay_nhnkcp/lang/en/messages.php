<?php

declare(strict_types=1);

return [
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction number (tno) is missing.',
        'default_reason' => 'Buyer refund request',
        'in_progress' => 'NHN KCP refund is already in progress.',
    ],
    'errors' => [
        'wsdl_missing' => 'KCP WSDL file not found: :file',
        'approval_key_error' => 'KCP approval key error [:code]: :message',
        'soap_error' => 'KCP SOAP integration error: :message',
        'cli_binary_missing' => 'KCP CLI binary missing: :path',
        'cli_binary_not_executable' => 'KCP CLI binary is not executable. Operator action required (sudo chmod 755 :path)',
    ],
];
