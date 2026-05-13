<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ecommerce Module Messages (English)
    |--------------------------------------------------------------------------
    */

    'order' => [
        // Order creation
        'created' => 'Your order has been placed.',
        'create_failed' => 'Failed to create order.',

        // Order status
        'status_changed' => 'Order status has been changed.',
        'cancelled' => 'Order has been cancelled.',
        'cancel_failed' => 'Failed to cancel order.',

        // Auto cancel
        'auto_cancel_expired_reason' => 'Automatically cancelled due to payment deadline expiration',

        // Order cancellation
        'cancelled' => 'Order has been cancelled.',

        // Payment
        'payment_cancelled' => 'Payment cancellation has been recorded.',
        'payment_pending' => 'Awaiting payment.',
        'payment_completed' => 'Payment completed.',
        'payment_failed' => 'Payment failed.',

        // Stock
        'stock_insufficient' => 'Insufficient stock.',
        'stock_restored' => 'Stock has been restored.',

        // Validation
        'not_found' => 'Order not found.',
        'invalid_status' => 'Invalid order status.',
        'amount_mismatch' => 'Payment amount does not match.',
    ],

    'address' => [
        'created' => 'Address has been added.',
        'create_failed' => 'Failed to add address.',
        'updated' => 'Address has been updated.',
        'update_failed' => 'Failed to update address.',
        'deleted' => 'Address has been deleted.',
        'delete_failed' => 'Failed to delete address.',
        'not_found' => 'Address not found.',
        'fetched' => 'Address retrieved.',
        'fetch_failed' => 'Failed to retrieve address.',
        'list_fetched' => 'Address list retrieved.',
        'list_fetch_failed' => 'Failed to retrieve address list.',
        'set_default' => 'Set as default address.',
        'default_set' => 'Set as default address.',
        'set_default_failed' => 'Failed to set default address.',
        'name_duplicate' => 'An address with the same name already exists.',
        'auto_saved_label' => 'Auto-saved address',
    ],

    'cart' => [
        'added' => 'Added to cart.',
        'updated' => 'Cart has been updated.',
        'removed' => 'Removed from cart.',
        'empty' => 'Your cart is empty.',
    ],

    'product' => [
        'not_found' => 'Product not found.',
        'not_available' => 'This product is currently not available.',
        'option_not_found' => 'Product option not found.',
    ],

    'coupon' => [
        'applied' => 'Coupon applied.',
        'removed' => 'Coupon removed.',
        'invalid' => 'Invalid coupon.',
        'expired' => 'This coupon has expired.',
        'already_used' => 'This coupon has already been used.',
        'min_amount_not_met' => 'Minimum order amount not met.',
    ],

    'payment' => [
        'method_not_supported' => 'Payment method not supported.',
        'dbank_account_required' => 'Please select a bank account for bank transfer.',
        'depositor_name_required' => 'Please enter the depositor name.',
        'provider_not_found' => 'Payment provider not found.',
        'client_config_success' => 'Payment client configuration retrieved.',
    ],
];
