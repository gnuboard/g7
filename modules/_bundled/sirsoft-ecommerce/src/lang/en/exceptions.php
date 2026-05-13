<?php

/**
 * Ecommerce Module Exception Messages (English)
 *
 * Custom exception messages for the ecommerce module
 */
return [
    'brand_not_found' => 'Brand not found.',
    'brand_has_products' => 'Cannot delete brand because it has :count products. Please change the brand of products first.',
    'category_not_found' => 'Category (ID: :category_id) not found.',
    'category_has_children' => 'Cannot delete category (ID: :category_id) because it has child categories.',
    'category_has_products' => 'Cannot delete category because it has :count products. Please change the category of products first.',
    'stock_mismatch' => 'Stock mismatch for product (ID: :product_id). Expected: :expected, Actual: :actual',
    'currency_setting_locked' => 'Cannot modify :setting_type setting because :product_count products exist.',
    'unauthorized_preset_access' => 'You do not have permission to access preset (ID: :preset_id).',
    'sequence_not_found' => 'Sequence not found for type: :type',
    'sequence_overflow' => 'Sequence :type has reached maximum value: :max_value',
    'sequence_code_duplicate' => 'Code :code already exists for type :type.',
    'coupon_not_found' => 'Coupon not found.',
    'coupon_has_issues' => 'Cannot delete coupon because it has :count issued coupons.',
    'label_not_found' => 'Label not found.',
    'label_has_products' => 'Cannot delete label because it has :count products. Please change the label of products first.',
    'operation_failed' => 'An error occurred while processing the operation.',
    'cart_item_not_found' => 'Cart item not found.',
    'cart_access_denied' => 'You do not have permission to access this cart item.',
    'cart_empty' => 'Cart is empty.',
    'temp_order_not_found' => 'Temporary order not found.',
    'option_not_found' => 'Product option not found.',
    'out_of_stock' => 'Product is out of stock.',
    'stock_exceeded' => 'Insufficient stock. (Requested: :requested, Available: :available)',
    'invalid_option_for_product' => 'This option does not belong to the product.',
    'order_not_found' => 'Order not found.',
    'order_not_cancellable' => 'This order cannot be cancelled in its current status.',
    'order_not_cancellable_detail' => 'Cannot cancel order in current status (:current_status). (Cancellable: :allowed_statuses)',
    'order_already_cancelled' => 'This order has already been cancelled.',
    'order_already_paid' => 'This order has already been paid.',
    'order_option_not_found' => 'Order option not found.',
    'order_option_already_cancelled' => 'This order option has already been cancelled.',
    'order_option_already_confirmed' => 'This order option has already been confirmed.',
    'order_option_cannot_confirm' => 'Cannot confirm purchase in current status.',
    'cancel_quantity_exceeds' => 'Cancel quantity exceeds current quantity (:max).',

    // Order payment related
    'insufficient_stock' => ':count products have insufficient stock.',
    'payment_amount_mismatch' => 'Payment amount mismatch. (Expected: :expected, Actual: :actual)',
    'cart_unavailable' => 'Some items in your cart are unavailable for purchase.',
    'order_amount_changed' => 'Order amount has changed. Please refresh the checkout page and try again. (Previous: :stored, Current: :recalculated)',
    'order_calculation_validation_failed' => 'Order calculation validation failed. Coupons may have expired or stock may have changed.',

    // Order cancellation/refund related
    'cancel_option_not_found' => 'Cancel target order option not found.',
    'cancel_option_already_cancelled' => 'This order option has already been cancelled.',
    'cancel_quantity_invalid' => 'Cancel quantity is invalid.',
    'cancel_refund_negative' => 'Cancelling this item would cause the coupon discount to no longer apply, increasing the payment amount. Cancellation is not allowed.',
    'pg_refund_failed' => 'PG refund processing failed. (:error)',

    // Currency related
    'unknown_currency' => 'Unsupported currency: :currency',
    'invalid_exchange_rate' => 'Invalid exchange rate for currency: :currency',

    // Claim Reason related
    'claim_reason_not_found' => 'Claim reason not found.',
    'claim_reason_in_use' => 'Cannot delete a reason in use by order cancellations. (Used :count times)',

    // Shipping Type related
    'shipping_type_not_found' => 'Shipping type not found.',
    'shipping_type_in_use' => 'Cannot delete shipping type (:name) in use by orders. (Used :count times)',
];
