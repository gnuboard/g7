<?php

return [
    'description' => [
        // Product management (Admin)
        'product_index' => 'Product list viewed',
        'product_show' => 'Product details viewed (ID: :product_id)',
        'product_create' => 'Product created (:product_name)',
        'product_update' => 'Product updated (:product_name)',
        'product_delete' => 'Product deleted (:product_name)',
        'product_bulk_update' => 'Products bulk updated (:count items)',
        'product_bulk_price_update' => 'Product prices bulk updated (:count items)',
        'product_bulk_stock_update' => 'Product stocks bulk updated (:count items)',
        'product_stock_sync' => 'Product stock synced (:product_name)',

        // Order management (Admin)
        'order_index' => 'Order list viewed',
        'order_show' => 'Order details viewed (:order_number)',
        'order_create' => 'Order created (:order_number)',
        'order_update' => 'Order updated (:order_number)',
        'order_delete' => 'Order deleted (:order_number)',
        'order_payment_complete' => 'Payment completed (:order_number)',
        'order_payment_failed' => 'Payment failed (:order_number)',
        'order_cancel' => 'Order fully cancelled (:order_number)',
        'order_partial_cancel' => 'Order partially cancelled (:order_number)',
        'order_coupon_restore' => 'Order cancellation coupon restored (:order_number)',
        'order_mileage_restore' => 'Order cancellation mileage restored (:order_number, :amount)',
        'order_bulk_update' => 'Orders bulk updated (:count items)',
        'order_bulk_status_update' => 'Order statuses bulk updated (:count items)',
        'order_bulk_shipping_update' => 'Shipping info bulk entered (:count items)',
        'order_update_shipping_address' => 'Shipping address changed (:order_number)',
        'order_send_email' => 'Order email sent (:order_number)',
        'order_option_status_change' => 'Order option status changed (Order: :order_number)',
        'order_option_bulk_status_change' => 'Order option statuses bulk changed (:count items)',
        'order_option_confirm' => 'Order option purchase confirmed (Order: :order_number)',
        'order_option_partial_cancel' => 'Order option partially cancelled (Order: :order_number)',

        // Coupon management (Admin)
        'coupon_index' => 'Coupon list viewed',
        'coupon_show' => 'Coupon details viewed (:coupon_name)',
        'coupon_create' => 'Coupon created (:coupon_name)',
        'coupon_update' => 'Coupon updated (:coupon_name)',
        'coupon_delete' => 'Coupon deleted (:coupon_name)',
        'coupon_bulk_status' => 'Coupon statuses bulk changed (:count items)',

        // Shipping policy management (Admin)
        'shipping_policy_index' => 'Shipping policy list viewed',
        'shipping_policy_create' => 'Shipping policy created (:policy_name)',
        'shipping_policy_update' => 'Shipping policy updated (:policy_name)',
        'shipping_policy_delete' => 'Shipping policy deleted (:policy_name)',
        'shipping_policy_toggle_active' => 'Shipping policy active status changed (:policy_name)',
        'shipping_policy_set_default' => 'Default shipping policy set (:policy_name)',
        'shipping_policy_bulk_delete' => 'Shipping policies bulk deleted (:count items)',
        'shipping_policy_bulk_toggle_active' => 'Shipping policy active statuses bulk changed (:count items)',

        // Shipping carrier management (Admin)
        'shipping_carrier_index' => 'Shipping carrier list viewed',
        'shipping_carrier_show' => 'Shipping carrier details viewed (:carrier_name)',
        'shipping_carrier_create' => 'Shipping carrier created (:carrier_name)',
        'shipping_carrier_update' => 'Shipping carrier updated (:carrier_name)',
        'shipping_carrier_delete' => 'Shipping carrier deleted (:carrier_name)',
        'shipping_carrier_toggle_status' => 'Shipping carrier status toggled (:carrier_name)',

        // Category management (Admin)
        'category_index' => 'Category list viewed',
        'category_show' => 'Category details viewed (:category_name)',
        'category_create' => 'Category created (:category_name)',
        'category_update' => 'Category updated (:category_name)',
        'category_delete' => 'Category deleted (:category_name)',
        'category_reorder' => 'Category order changed',
        'category_toggle_status' => 'Category status toggled (:category_name)',

        // Brand management (Admin)
        'brand_index' => 'Brand list viewed',
        'brand_show' => 'Brand details viewed (:brand_name)',
        'brand_create' => 'Brand created (:brand_name)',
        'brand_update' => 'Brand updated (:brand_name)',
        'brand_delete' => 'Brand deleted (:brand_name)',
        'brand_toggle_status' => 'Brand status toggled (:brand_name)',

        // Label management (Admin)
        'label_index' => 'Product label list viewed',
        'label_create' => 'Product label created (:label_name)',
        'label_update' => 'Product label updated (:label_name)',
        'label_delete' => 'Product label deleted (:label_name)',
        'label_toggle_status' => 'Product label status toggled (:label_name)',

        // Product common info (Admin)
        'product_common_info_index' => 'Product common info list viewed',
        'product_common_info_create' => 'Product common info created (:info_name)',
        'product_common_info_update' => 'Product common info updated (:info_name)',
        'product_common_info_delete' => 'Product common info deleted (:info_name)',

        // Product notice template (Admin)
        'product_notice_template_index' => 'Product notice template list viewed',
        'product_notice_template_create' => 'Product notice template created (:template_name)',
        'product_notice_template_update' => 'Product notice template updated (:template_name)',
        'product_notice_template_delete' => 'Product notice template deleted (:template_name)',
        'product_notice_template_copy' => 'Product notice template copied (:template_name)',

        // Extra fee template (Admin)
        'extra_fee_template_index' => 'Extra fee template list viewed',
        'extra_fee_template_create' => 'Extra fee template created (:template_name)',
        'extra_fee_template_update' => 'Extra fee template updated (:template_name)',
        'extra_fee_template_delete' => 'Extra fee template deleted (:template_name)',
        'extra_fee_template_toggle_active' => 'Extra fee template active status changed (:template_name)',
        'extra_fee_template_bulk_delete' => 'Extra fee templates bulk deleted (:count items)',
        'extra_fee_template_bulk_toggle_active' => 'Extra fee template active statuses bulk changed (:count items)',
        'extra_fee_template_bulk_create' => 'Extra fee templates bulk created (:count items)',

        // Product option (Admin)
        'product_option_bulk_price_update' => 'Product option prices bulk updated (:count items)',
        'product_option_bulk_stock_update' => 'Product option stocks bulk updated (:count items)',
        'product_option_bulk_update' => 'Product options bulk updated (:count items)',

        // Product image (Admin)
        'product_image_upload' => 'Product image uploaded (Product: :product_name)',
        'product_image_delete' => 'Product image deleted (Product: :product_name)',
        'product_image_reorder' => 'Product image order changed (Product: :product_name)',

        // Review management (Admin)
        'review_index' => 'Review list viewed',
        'review_show' => 'Review details viewed (ID: :review_id)',
        'review_create' => 'Review created (Product: :product_name)',
        'review_delete' => 'Review deleted (ID: :review_id)',
        'review_bulk_delete' => 'Reviews bulk deleted (:count items)',
        'review_reply' => 'Review reply created (ID: :review_id)',
        'product_review_create' => 'Product review created (Product: :product_name)',
        'product_review_delete' => 'Product review deleted (Product: :product_name)',

        // Ecommerce settings
        'ecommerce_settings_index' => 'Ecommerce settings viewed',

        // Payment (Admin)
        'payment_refund' => 'Payment refunded (Order: :order_number)',

        // User actions (ActivityLogType::User)
        'cart_add' => 'Added to cart (:product_name)',
        'cart_update_quantity' => 'Cart quantity changed (:product_name)',
        'cart_change_option' => 'Cart option changed (:product_name)',
        'cart_delete' => 'Cart item deleted',
        'cart_delete_all' => 'Cart cleared',
        'wishlist_add' => 'Added to wishlist (:product_name)',
        'wishlist_remove' => 'Removed from wishlist (:product_name)',
        'coupon_use' => 'Coupon used (:coupon_name)',
        'coupon_restore' => 'Coupon restored (:coupon_name)',
        'user_coupon_download' => 'Coupon downloaded (:coupon_name)',
        'mileage_earn' => 'Mileage earned (:amount)',
        'mileage_use' => 'Mileage used (:amount)',
        'mileage_restore' => 'Mileage restored (:amount)',
        'user_order_create' => 'Order created (:order_number)',
        'user_order_option_confirm' => 'Purchase confirmed (Order: :order_number)',
    ],
];
