<?php

/**
 * TossPayments Plugin Messages (English)
 */
return [
    'errors' => [
        'payment_failed' => 'Payment could not be completed. Please try again in a moment.',
        'approval_verification_cancel_reason' => 'Automatically cancelled because the payment approval could not be verified',
    ],
    'claim' => [
        'invalid_callback' => 'The callback parameters required to approve the claim payment (paymentKey, orderId, amount) are missing.',
        'order_id_mismatch' => 'The callback order id does not match the claim payment number being approved.',
        'deposit_not_supported' => 'Claim additional payments do not support deposit-pending methods (virtual account).',
        'amount_mismatch' => 'The charge changed after the payment window was opened. Please start the payment again from the claim.',
        'amount_mismatch_cancel_reason' => 'Automatically cancelled due to a claim payment approval amount mismatch',
    ],
    'refund' => [
        'missing_payment_key' => 'Cannot process refund: TossPayments payment key not found.',
        'default_reason' => 'Cancelled by customer request',
        'pg_failed' => 'An error occurred while communicating with the payment gateway. Please try again later.',
        'escrow_partial_not_allowed' => 'Escrow payments do not support partial cancellation. Only full cancellation is available.',
        'missing_refund_account' => 'Cancelling a virtual account payment requires refund account details (bank, account number, holder name).',
    ],
    'cash_receipt' => [
        'provider_name' => 'TossPayments',
        'invalid_order_id' => 'The cash receipt identifier does not match the TossPayments format (6-64 chars, letters/digits/-/_).',
        'cancel_reason' => 'Reissued due to an order amount change',
        'pg_failed' => 'An error occurred while communicating with the payment gateway, so the cash receipt could not be processed. Please try again later.',
    ],
    'settings_validation' => [
        'vbank_valid_hours_range' => 'Virtual account deposit deadline must be between :min and :max hours (max 90 days).',
        'use_escrow_invalid' => 'The escrow usage setting value is invalid.',
    ],
    'settings' => [
        'redirect_url_format' => 'The post-payment redirect address must start with {shopBase}, /, http:// or https:// and cannot contain spaces. Protocol-relative //addresses and javascript: are not allowed.',
    ],
    // Error messages of the payment screen and PG integration APIs
    'responses' => [
        'amount_mismatch' => 'Payment amount does not match the order amount.',
        'currency_not_chargeable' => 'The charge cannot be calculated in the payment currency.',
        'order_not_found' => 'Order not found.',
        'ownership_verification_failed' => 'Order ownership verification failed.',
        'too_many_requests' => 'Too many requests. Please try again later.',
    ],
];
