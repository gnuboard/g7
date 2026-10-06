<?php

declare(strict_types=1);

return [
    'claim' => [
        'invalid_callback' => 'The callback parameters required to approve the claim payment are missing.',
        'window_failed' => 'The payment window authentication did not complete.',
        'currency_not_supported' => 'NHN KCP claim payments support KRW only.',
        'deposit_not_supported' => 'Claim additional payments do not support deposit-pending methods (virtual account).',
        'compensation_cancel_reason' => 'Automatically cancelled after the claim payment approval was rejected',
        'amount_mismatch' => 'The payment amount does not match the charge, or the charge could not be determined, so the payment was not confirmed (any approved amount is cancelled). Please start the payment again from the claim.',
    ],
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction number (tno) is missing.',
        'default_reason' => 'Buyer refund request',
        'in_progress' => 'NHN KCP refund is already in progress.',
        'vbank_requires_bank_transfer' => 'A settled virtual account payment cannot be refunded automatically through NHN KCP. Change the refund method to bank transfer and send the money to the customer account directly.',
    ],
    'escrow' => [
        'invoice_required' => 'Please enter the tracking number.',
        'courier_required' => 'Please select a courier.',
    ],
    'errors' => [
        'payment_failed' => 'Payment could not be completed. Please try again in a moment.',
        'wsdl_missing' => 'KCP WSDL file not found: :file',
        'approval_key_error' => 'KCP approval key error [:code]: :message',
        'soap_error' => 'KCP SOAP integration error: :message',
        'cli_binary_missing' => 'KCP CLI binary missing: :path',
        'cli_binary_not_executable' => 'KCP CLI binary is not executable. Operator action required (sudo chmod 755 :path)',
    ],
];
