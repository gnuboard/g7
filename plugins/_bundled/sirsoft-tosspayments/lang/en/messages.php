<?php

/**
 * TossPayments Plugin Messages (English)
 */
return [
    'errors' => [
        'payment_failed' => 'Payment could not be completed. Please try again in a moment.',
    ],
    'refund' => [
        'missing_payment_key' => 'Cannot process refund: TossPayments payment key not found.',
        'default_reason' => 'Cancelled by customer request',
        'escrow_partial_not_allowed' => 'Escrow payments do not support partial cancellation. Only full cancellation is available.',
        'missing_refund_account' => 'Cancelling a virtual account payment requires refund account details (bank, account number, holder name).',
    ],
    'cash_receipt' => [
        'provider_name' => 'TossPayments',
        'invalid_order_id' => 'The cash receipt identifier does not match the TossPayments format (6-64 chars, letters/digits/-/_).',
        'cancel_reason' => 'Reissued due to an order amount change',
    ],
    'settings_validation' => [
        'vbank_valid_hours_range' => 'Virtual account deposit deadline must be between :min and :max hours (max 90 days).',
        'use_escrow_invalid' => 'The escrow usage setting value is invalid.',
    ],
];
