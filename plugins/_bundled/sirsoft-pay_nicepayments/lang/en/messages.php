<?php

declare(strict_types=1);

return [
    'errors' => [
        'tid_required' => 'Please enter the TID.',
        'order_not_found' => 'Order not found.',
        'invalid_request' => 'Invalid request.',
        'invalid_amount' => 'The requested amount is invalid.',
        'vbank_refund_order_not_found' => 'The order to refund could not be found.',
        'vbank_refund_invalid_payment' => 'This is not a refundable completed NicePayments virtual-account payment.',
        'vbank_refund_amount_mismatch' => 'The requested refund amount does not match the cancellable amount in the database.',
        'vbank_refund_already_processing' => 'A virtual-account refund is already being processed.',
        'vbank_completed_requires_bank_info' => 'Refund account information is required for completed virtual account deposits. Please process the refund through the admin API.',
        'invalid_refund_amount' => 'The refund amount is invalid. (requested: :amount KRW)',
    ],
    'claim' => [
        'invalid_callback' => 'The callback parameters required to approve the claim payment are missing.',
        'window_failed' => 'The payment window authentication did not complete.',
        'mid_mismatch' => 'The callback merchant ID (MID) does not match the configured value.',
        'signature_mismatch' => 'The callback signature verification failed.',
        'deposit_not_supported' => 'Claim additional payments do not support deposit-pending methods (virtual account).',
        'invalid_refund_amount' => 'The refund amount is invalid.',
        'amount_mismatch' => 'The payment amount does not match the charge, or the charge could not be determined, so the payment was not confirmed (any approved amount is cancelled). Please start the payment again from the claim.',
        'order_id_mismatch' => 'The order number in the approval response does not match the claim payment number being approved.',
        'compensation_cancel_reason' => 'Automatically cancelled after the claim payment approval was rejected',
    ],
    'refund' => [
        'missing_tid' => 'Cannot process refund: transaction ID (TID) is missing.',
        'default_reason' => 'Buyer refund request',
    ],
    'defaults' => [
        'vbank_refund_msg' => 'Virtual account refund',
    ],
    'fields' => [
        'tid' => 'transaction ID (TID)',
        'moid' => 'order number',
        'cancel_amt' => 'cancel amount',
        'cancel_msg' => 'cancel reason',
        'refund_acct_no' => 'refund account number',
        'refund_bank_cd' => 'refund bank code',
        'refund_acct_nm' => 'refund account holder',
    ],
    'settings' => [
        'redirect_url_format' => 'The post-payment redirect address must start with {shopBase}, / or http(s):// and cannot contain spaces. Protocol-relative //addresses and javascript: are not allowed.',
    ],
];
