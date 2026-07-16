<?php

/**
 * Ecommerce Module Exception Messages (English)
 *
 * Custom exception messages for the ecommerce module
 */
return [
    // Mileage validation exceptions
    'mileage' => [
        'insufficient_balance' => 'Insufficient mileage balance available.',
        'use_exceeds_balance' => 'Cannot use more than your available mileage (:amount points).',
        'deduct_exceeds_balance' => 'The mileage to deduct exceeds the available balance.',
        'below_min_use_amount' => 'The minimum usable amount is :amount points.',
        'invalid_use_unit' => 'Mileage can only be used in units of :unit points.',
        'exceeds_max_use' => 'Exceeds the maximum usable limit.',
        'base_currency_rule_missing' => 'Mileage cannot be used because the mileage usage unit for the base currency is not configured.',
    ],

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
    'coupon_issue_not_found' => 'Coupon issue record not found.',
    'coupon_issue_not_cancellable' => 'Only unused issued coupons can be cancelled.',
    'label_not_found' => 'Label not found.',
    'product_notice_template_not_found' => 'Product notice template not found.',
    'label_has_products' => 'Cannot delete label because it has :count products. Please change the label of products first.',
    'operation_failed' => 'An error occurred while processing the operation.',
    'product_image_limit_exceeded' => 'You can upload up to :max images.',
    'cart_item_not_found' => 'Cart item not found.',
    'cart_access_denied' => 'You do not have permission to access this cart item.',
    'cart_empty' => 'Cart is empty.',
    'temp_order_not_found' => 'Temporary order not found.',
    'option_not_found' => 'Product option not found.',
    'additional_option_invalid' => 'The selected additional option is invalid.',
    'additional_option_required' => 'Please select the required additional option (:name).',
    'additional_option_custom_text_required' => 'Please enter the custom text for the additional option (:name).',
    'out_of_stock' => 'Product is out of stock.',
    'product_unavailable' => 'This product is currently not available for sale.',
    'stock_exceeded' => 'Insufficient stock. (Requested: :requested, Available: :available)',
    'min_purchase_qty_not_met' => 'The minimum purchase quantity is :limit. (Requested: :requested)',
    'max_purchase_qty_exceeded' => 'The maximum purchase quantity is :limit. (Requested: :requested)',
    'invalid_option_for_product' => 'This option does not belong to the product.',
    'order_not_found' => 'Order not found.',
    'unauthorized' => 'You do not have permission to access this order.',
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
    'purchase_not_allowed' => 'This item is not available for your account to purchase.',
    'country_not_shippable' => 'This item cannot be shipped to the selected country.',
    'order_amount_changed' => 'Order amount has changed. Please refresh the checkout page and try again. (Previous: :stored, Current: :recalculated)',
    'order_calculation_validation_failed' => 'Order calculation validation failed. Coupons may have expired or stock may have changed.',

    // Order cancellation/refund related
    'cancel_option_not_found' => 'Cancel target order option not found.',
    'cancel_option_already_cancelled' => 'This order option has already been cancelled.',
    'cancel_quantity_invalid' => 'Cancel quantity is invalid.',
    'cancel_refund_negative' => 'Cancelling this item changes the applied discount conditions and increases the payment amount, so it cannot be cancelled.',
    'pg_refund_failed' => 'PG refund processing failed. (:error)',
    'order_cancel_failed' => 'Failed to cancel the order.',
    'order_estimate_refund_failed' => 'Failed to calculate the estimated refund amount.',
    'order_create_failed' => 'Failed to create the order.',

    // Currency related
    'unknown_currency' => 'Unsupported currency: :currency',
    'invalid_exchange_rate' => 'Invalid exchange rate for currency: :currency',
    'unsupported_payment_currency' => 'Payment is not available in :currency. Please check the exchange rate settings.',

    // Claim Reason related
    'claim_reason_not_found' => 'Claim reason not found.',
    'claim_reason_in_use' => 'Cannot delete a reason in use by order cancellations. (Used :count times)',

    // Shipping Type related
    'shipping_type_not_found' => 'Shipping type not found.',
    'shipping_type_in_use' => 'Cannot delete shipping type (:name) in use by orders. (Used :count times)',
];
