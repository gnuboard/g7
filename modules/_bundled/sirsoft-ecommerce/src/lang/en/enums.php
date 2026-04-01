<?php

/**
 * Ecommerce Module Enum Labels
 *
 * Multilingual labels for enum values
 */
return [
    'sales_status' => [
        'on_sale' => 'On Sale',
        'suspended' => 'Suspended',
        'sold_out' => 'Sold Out',
        'coming_soon' => 'Coming Soon',
    ],
    'display_status' => [
        'visible' => 'Visible',
        'hidden' => 'Hidden',
    ],
    'tax_status' => [
        'taxable' => 'Taxable',
        'tax_free' => 'Tax Free',
    ],
    'image_collection' => [
        'main' => 'Main Image',
        'detail' => 'Detail Image',
        'additional' => 'Additional Image',
    ],
    'target_screen' => [
        'products' => 'Products',
        'orders' => 'Orders',
        'customers' => 'Customers',
    ],
    'date_type' => [
        'created_at' => 'Created Date',
        'updated_at' => 'Updated Date',
    ],
    'price_type' => [
        'selling_price' => 'Selling Price',
        'supply_price' => 'Supply Price',
        'list_price' => 'List Price',
    ],

    // Order Status (matches OrderStatusEnum values)
    'order_status' => [
        'pending_order' => 'Pending Order',
        'pending_payment' => 'Pending Payment',
        'payment_complete' => 'Payment Complete',
        'shipping_hold' => 'Shipping Hold',
        'preparing' => 'Preparing',
        'shipping_ready' => 'Ready to Ship',
        'shipping' => 'Shipping',
        'delivered' => 'Delivered',
        'confirmed' => 'Confirmed',
        'cancelled' => 'Cancelled',
        'partial_cancelled' => 'Partially Cancelled',
    ],

    // Payment Status (matches PaymentStatusEnum values)
    'payment_status' => [
        'ready' => 'Pending',
        'in_progress' => 'In Progress',
        'waiting_deposit' => 'Awaiting Deposit',
        'paid' => 'Paid',
        'partial_cancelled' => 'Partially Cancelled',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed',
        'expired' => 'Expired',
    ],

    // Payment Method (matches PaymentMethodEnum values)
    'payment_method' => [
        'card' => 'Credit Card',
        'vbank' => 'Virtual Account',
        'dbank' => 'Bank Deposit',
        'bank' => 'Bank Transfer',
        'phone' => 'Mobile Payment',
        'point' => 'Points',
        'deposit' => 'Store Credit',
        'free' => 'Free',
    ],

    // Shipping Status
    'shipping_status' => [
        'pending' => 'Pending',
        'preparing' => 'Preparing',
        'ready' => 'Ready to Ship',
        'shipped' => 'Shipped',
        'in_transit' => 'In Transit',
        'out_for_delivery' => 'Out for Delivery',
        'delivered' => 'Delivered',
        'failed' => 'Failed',
        'returned' => 'Returned',
        'pickup_ready' => 'Ready for Pickup',
        'pickup_complete' => 'Picked Up',
    ],

    // Shipping Type (matches ShippingTypeEnum values)
    'shipping_type' => [
        'domestic_parcel' => 'Domestic Parcel',
        'domestic_express' => 'Domestic Express',
        'domestic_quick' => 'Quick Service',
        'international_ems' => 'International EMS',
        'international_standard' => 'International Standard',
        'pickup' => 'Store Pickup',
        'cvs' => 'Convenience Store',
        'digital' => 'Digital Product',
    ],

    // Option Status
    'option_status' => [
        'ordered' => 'Ordered',
        'confirmed' => 'Confirmed',
        'preparing' => 'Preparing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'refund_requested' => 'Refund Requested',
        'refund_complete' => 'Refunded',
        'return_requested' => 'Return Requested',
        'return_complete' => 'Returned',
        'exchange_requested' => 'Exchange Requested',
        'exchange_complete' => 'Exchanged',
    ],

    // Order Date Type
    'order_date_type' => [
        'ordered_at' => 'Order Date',
        'paid_at' => 'Payment Date',
        'confirmed_at' => 'Confirmation Date',
        'delivered_at' => 'Delivery Date',
        'cancelled_at' => 'Cancellation Date',
    ],

    // Device Type
    'device_type' => [
        'pc' => 'PC',
        'mobile' => 'Mobile',
        'app_ios' => 'iOS App',
        'app_android' => 'Android App',
        'admin' => 'Admin',
        'api' => 'API',
    ],

    // Coupon Target Type (matches CouponTargetType Enum values)
    'coupon_target_type' => [
        'product_amount' => 'Product Amount',
        'order_amount' => 'Order Amount',
        'shipping_fee' => 'Shipping Fee',
    ],

    // Coupon Target Type Short Labels (for dropdown display)
    'coupon_target_type_short' => [
        'product_amount' => 'Product',
        'order_amount' => 'Order',
        'shipping_fee' => 'Shipping',
    ],

    // Coupon Discount Type (matches CouponDiscountType Enum values)
    'coupon_discount_type' => [
        'fixed' => 'Fixed Amount',
        'rate' => 'Percentage',
    ],

    // Coupon Issue Status (matches CouponIssueStatus Enum values)
    'coupon_issue_status' => [
        'issuing' => 'Active',
        'stopped' => 'Stopped',
    ],

    // Coupon Issue Method (matches CouponIssueMethod Enum values)
    'coupon_issue_method' => [
        'direct' => 'Direct Issue',
        'download' => 'Download',
        'auto' => 'Automatic',
    ],

    // Coupon Issue Condition (matches CouponIssueCondition Enum values)
    'coupon_issue_condition' => [
        'manual' => 'Manual',
        'signup' => 'Sign Up',
        'first_purchase' => 'First Purchase',
        'birthday' => 'Birthday',
    ],

    // Coupon Target Scope (matches CouponTargetScope Enum values)
    'coupon_target_scope' => [
        'all' => 'All Products',
        'products' => 'Specific Products',
        'categories' => 'Specific Categories',
    ],

    // Coupon Issue Record Status (matches CouponIssueRecordStatus Enum values)
    'coupon_issue_record_status' => [
        'available' => 'Available',
        'used' => 'Used',
        'expired' => 'Expired',
        'cancelled' => 'Cancelled',
    ],

    // Shipping Method
    'shipping_method' => [
        'parcel' => 'Parcel',
        'freight' => 'Freight',
        'express' => 'Express',
        'economy' => 'Economy',
        'ems' => 'EMS',
        'collect' => 'Collect on Delivery',
        'quick' => 'Quick Service',
        'direct' => 'Direct Delivery',
        'pickup' => 'Pickup',
        'other' => 'Other',
    ],

    // Charge Policy
    'charge_policy' => [
        'free' => 'Free',
        'fixed' => 'Fixed',
        'conditional_free' => 'Conditional Free',
        'range_amount' => 'Range by Amount',
        'range_quantity' => 'Range by Quantity',
        'range_weight' => 'Range by Weight',
        'range_volume' => 'Range by Volume',
        'range_volume_weight' => 'Range by Volume+Weight',
        'api' => 'Calculation API',
        'per_quantity' => 'Per Quantity',
        'per_weight' => 'Per Weight',
        'per_volume' => 'Per Volume',
        'per_volume_weight' => 'Per Volume Weight',
        'per_amount' => 'Per Amount',
    ],

    // Order Option Status — unified with OrderStatusEnum (see order_status key)

    // Order Option Source Type (OrderOptionSourceTypeEnum)
    'order_option_source_type' => [
        'order' => 'Original Order',
        'exchange' => 'Exchange',
        'split' => 'Quantity Split',
    ],

    // Tax Invoice Status (matches TaxInvoiceStatusEnum values)
    'tax_invoice_status' => [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'issued' => 'Issued',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    // Shipping Country
    'shipping_country' => [
        'KR' => 'Korea',
        'US' => 'USA',
        'CN' => 'China',
        'JP' => 'Japan',
    ],

    // Cancel Type (matches CancelTypeEnum values)
    'cancel_type' => [
        'full' => 'Full Cancel',
        'partial' => 'Partial Cancel',
    ],

    // Cancel Status (matches CancelStatusEnum values)
    'cancel_status' => [
        'requested' => 'Cancel Requested',
        'completed' => 'Cancel Completed',
    ],

    // Claim Reason Fault Type (matches ClaimReasonFaultTypeEnum values)
    'claim_reason_fault_type' => [
        'customer' => 'Customer',
        'seller' => 'Seller',
        'carrier' => 'Carrier',
    ],

    // Claim Reason Type (matches ClaimReasonTypeEnum values)
    'claim_reason_type' => [
        'refund' => 'Refund/Cancel',
    ],

    // Cancel Option Status (matches CancelOptionStatusEnum values)
    'cancel_option_status' => [
        'requested' => 'Cancel Requested',
        'completed' => 'Completed',
    ],

    // Refund Status (matches RefundStatusEnum values)
    'refund_status' => [
        'requested' => 'Refund Requested',
        'approved' => 'Refund Approved',
        'processing' => 'Refund Processing',
        'on_hold' => 'Refund On Hold',
        'completed' => 'Refund Completed',
        'rejected' => 'Refund Rejected',
    ],

    // Refund Method (matches RefundMethodEnum values)
    'refund_method' => [
        'pg' => 'PG Refund',
        'bank' => 'Bank Transfer Refund',
        'points' => 'Points Refund',
    ],

    // Refund Priority (matches RefundPriorityEnum values)
    'refund_priority' => [
        'pg_first' => 'Refund payment method (PG) first',
        'points_first' => 'Refund points first',
    ],

    // Refund Option Status (matches RefundOptionStatusEnum values)
    'refund_option_status' => [
        'requested' => 'Refund Requested',
        'approved' => 'Refund Approved',
        'processing' => 'Refund Processing',
        'on_hold' => 'Refund On Hold',
        'completed' => 'Completed',
        'rejected' => 'Refund Rejected',
    ],

    // Review Status (ReviewStatus values)
    'review_status' => [
        'visible' => 'Visible',
        'hidden' => 'Hidden',
    ],

    // Review Reply Status
    'has_reply' => [
        'replied' => 'Replied',
        'not_replied' => 'Not Replied',
    ],
];
