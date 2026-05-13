<?php

/**
 * Ecommerce Module Validation Messages
 *
 * Messages used when FormRequest validation fails
 */
return [
    // Product 1:1 Inquiry validation messages
    'inquiries' => [
        'content' => [
            'required' => 'Please enter your inquiry content.',
            'min'      => 'Inquiry content must be at least :min characters.',
            'max'      => 'Inquiry content may not be greater than :max characters.',
        ],
        'reply_content' => [
            'required' => 'Please enter the reply content.',
            'min'      => 'Reply content must be at least 1 character.',
            'max'      => 'Reply content may not be greater than 5000 characters.',
        ],
    ],

    // Custom validation messages
    'settings' => [
        'key_required' => 'The settings key is required.',
        'value_present' => 'The settings value is required.',
    ],

    // Shipping carrier validation messages
    'shipping_carrier' => [
        'code_required' => 'The carrier code is required.',
        'code_unique' => 'This carrier code is already in use.',
        'code_format' => 'The carrier code may only contain lowercase letters, numbers, and hyphens.',
        'name_required' => 'The carrier name is required.',
        'type_required' => 'The carrier type is required.',
        'type_invalid' => 'The carrier type must be domestic or international.',
    ],

    // List query common validation messages
    'list' => [
        // Pagination
        'page' => [
            'integer' => 'Page number must be an integer.',
            'min' => 'Page number must be at least 1.',
        ],
        'per_page' => [
            'integer' => 'Items per page must be an integer.',
            'min' => 'Items per page must be at least :min.',
            'max' => 'Items per page cannot exceed :max.',
        ],
        // Sorting
        'sort' => [
            'string' => 'Sort field must be a string.',
            'in' => 'Please select a valid sort option.',
        ],
        'sort_by' => [
            'string' => 'Sort by field must be a string.',
            'in' => 'Please select a valid sort field.',
        ],
        'sort_order' => [
            'string' => 'Sort order must be a string.',
            'in' => 'Sort order must be asc or desc.',
        ],
        // Search
        'search' => [
            'string' => 'Search term must be a string.',
            'max' => 'Search term cannot exceed :max characters.',
        ],
        'search_field' => [
            'string' => 'Search field must be a string.',
            'in' => 'Please select a valid search field.',
        ],
        'search_keyword' => [
            'string' => 'Search keyword must be a string.',
            'max' => 'Search keyword cannot exceed :max characters.',
        ],
        // Filters
        'is_active' => [
            'boolean' => 'Active status must be true or false.',
            'in' => 'Active status value is invalid.',
        ],
        'active_only' => [
            'boolean' => 'Active only filter must be true or false.',
        ],
        'locale' => [
            'string' => 'Locale code must be a string.',
            'in' => 'This language is not supported.',
        ],
        'region' => [
            'string' => 'Region must be a string.',
            'max' => 'Region cannot exceed :max characters.',
        ],
        // Category list
        'parent_id' => [
            'exists' => 'Parent category not found.',
        ],
        'hierarchical' => [
            'boolean' => 'Hierarchical option must be true or false.',
        ],
        'flat' => [
            'boolean' => 'Flat list option must be true or false.',
        ],
        'max_depth' => [
            'integer' => 'Max depth must be an integer.',
            'min' => 'Max depth must be at least :min.',
            'max' => 'Max depth cannot exceed :max.',
        ],
        // Coupon list
        'target_type' => [
            'string' => 'Target type must be a string.',
            'in' => 'Please select a valid target type.',
        ],
        'discount_type' => [
            'string' => 'Discount type must be a string.',
            'in' => 'Please select a valid discount type.',
        ],
        'issue_status' => [
            'string' => 'Issue status must be a string.',
            'in' => 'Please select a valid issue status.',
        ],
        'issue_method' => [
            'string' => 'Issue method must be a string.',
            'in' => 'Please select a valid issue method.',
        ],
        'issue_condition' => [
            'string' => 'Issue condition must be a string.',
            'in' => 'Please select a valid issue condition.',
        ],
        'min_benefit_amount' => [
            'numeric' => 'Minimum benefit amount must be a number.',
            'min' => 'Minimum benefit amount must be at least 0.',
        ],
        'max_benefit_amount' => [
            'numeric' => 'Maximum benefit amount must be a number.',
            'min' => 'Maximum benefit amount must be at least 0.',
        ],
        'min_order_amount' => [
            'numeric' => 'Minimum order amount must be a number.',
            'min' => 'Minimum order amount must be at least 0.',
        ],
        // Date filters
        'created_start_date' => [
            'date' => 'Created start date must be a valid date.',
        ],
        'created_end_date' => [
            'date' => 'Created end date must be a valid date.',
            'after_or_equal' => 'Created end date must be after or equal to start date.',
        ],
        'valid_start_date' => [
            'date' => 'Valid start date must be a valid date.',
        ],
        'valid_end_date' => [
            'date' => 'Valid end date must be a valid date.',
            'after_or_equal' => 'Valid end date must be after or equal to start date.',
        ],
        'issue_start_date' => [
            'date' => 'Issue start date must be a valid date.',
        ],
        'issue_end_date' => [
            'date' => 'Issue end date must be a valid date.',
            'after_or_equal' => 'Issue end date must be after or equal to start date.',
        ],
        // Shipping policy list
        'shipping_methods' => [
            'array' => 'Shipping methods must be an array.',
            'string' => 'Shipping method must be a string.',
            'in' => 'Please select a valid shipping method.',
        ],
        'charge_policies' => [
            'array' => 'Charge policies must be an array.',
            'string' => 'Charge policy must be a string.',
            'in' => 'Please select a valid charge policy.',
        ],
        'countries' => [
            'array' => 'Countries must be an array.',
            'string' => 'Country must be a string.',
            'in' => 'Please select a valid country.',
        ],
    ],

    // Product category/option validation messages (root level)
    'category_required' => 'Please select a category.',
    'category_min' => 'Please select at least one category.',
    'category_max' => 'You can select up to 5 categories.',
    'options_required' => 'Please add at least one option.',
    'options_min' => 'Please add at least one option.',
    'selling_price_lte_list' => 'Selling price cannot exceed list price.',
    'option_selling_price_lte_list' => 'Option selling price cannot exceed list price.',

    // Product validation messages
    'product' => [
        'name' => [
            'required' => 'Please enter the product name.',
        ],
        'name_primary' => [
            'required' => 'Product name in the primary language is required.',
        ],
        'product_code' => [
            'required' => 'Please enter the product code.',
            'unique' => 'This product code is already in use.',
        ],
        'list_price' => [
            'required' => 'Please enter the list price.',
            'min' => 'List price must be 1 or greater.',
        ],
        'selling_price' => [
            'required' => 'Please enter the selling price.',
            'min' => 'Selling price must be 1 or greater.',
            'lte' => 'Selling price must be less than or equal to the list price.',
        ],
        'stock_quantity' => [
            'required' => 'Stock quantity is required.',
        ],
        'sales_status' => [
            'required' => 'Sales status is required.',
            'in' => 'Invalid sales status.',
        ],
        'display_status' => [
            'required' => 'Display status is required.',
            'in' => 'Invalid display status.',
        ],
        'tax_status' => [
            'required' => 'Tax status is required.',
            'in' => 'Invalid tax status.',
        ],
        'category_ids' => [
            'required' => 'Please select at least one category.',
            'min' => 'Please select at least one category.',
            'max' => 'You can select up to 5 categories.',
        ],
        'options' => [
            'required' => 'Product options are required.',
            'min' => 'Please add at least one product option.',
            'option_code' => [
                'required_with' => 'Option code is required.',
            ],
            'option_name' => [
                'required_with' => 'Option name is required.',
            ],
            'option_values' => [
                'required_with' => 'Option values are required.',
            ],
            'list_price' => [
                'required_with' => 'Option list price is required.',
            ],
            'selling_price' => [
                'required_with' => 'Option selling price is required.',
            ],
            'stock_quantity' => [
                'required_with' => 'Option stock quantity is required.',
            ],
        ],
        'label_assignments' => [
            'label_id' => [
                'required' => 'Please select a label.',
                'exists' => 'The selected label does not exist.',
            ],
            'end_date' => [
                'after_or_equal' => 'End date must be after or equal to the start date.',
            ],
        ],
        'shipping_policy_id' => [
            'exists' => 'The selected shipping policy does not exist.',
        ],
        'common_info_id' => [
            'exists' => 'The selected common info does not exist.',
        ],
        'use_main_image_for_og' => [
            'boolean' => 'The OG image setting must be true or false.',
        ],
        'invalid_sales_status' => 'Invalid sales status.',
        'invalid_display_status' => 'Invalid display status.',
        // Product bulk processing
        'bulk' => [
            'ids_required' => 'Please select products to update.',
            'ids_min' => 'Please select at least one product.',
            'product_not_found' => 'Product not found.',
            'option_not_found' => 'Option not found.',
        ],
    ],

    // Option validation messages
    'option' => [
        'bulk' => [
            'ids_required' => 'Please select options to update.',
            'ids_min' => 'Please select at least one option.',
            'invalid_id_format' => 'Invalid option ID format.',
        ],
    ],

    // Bulk processing validation messages
    'bulk' => [
        'ids_required' => 'Please select products to update.',
        'method_required' => 'Please select an update method.',
        'value_required' => 'Please enter a value.',
    ],

    // Option bulk price update validation messages
    'bulk_option_price' => [
        'ids_required' => 'Please select products or options to update.',
        'product_ids' => [
            'required' => 'Please select products to update.',
            'min' => 'Please select at least one product.',
        ],
        'method' => [
            'required' => 'Please select an update method.',
            'in' => 'Please select a valid update method.',
        ],
        'value' => [
            'required' => 'Please enter a value.',
            'integer' => 'The value must be a number.',
            'min' => 'The value must be at least 0.',
        ],
        'unit' => [
            'required' => 'Please select a unit.',
            'in' => 'Please select a valid unit.',
        ],
    ],

    // Option bulk stock update validation messages
    'bulk_option_stock' => [
        'ids_required' => 'Please select products or options to update.',
        'product_ids' => [
            'required' => 'Please select products to update.',
            'min' => 'Please select at least one product.',
        ],
        'method' => [
            'required' => 'Please select an update method.',
            'in' => 'Please select a valid update method.',
        ],
        'value' => [
            'required' => 'Please enter a value.',
            'integer' => 'The value must be a number.',
            'min' => 'The value must be at least 0.',
        ],
    ],

    // Preset validation messages
    'preset' => [
        'name_required' => 'Please enter the preset name.',
        'name_exists' => 'A preset with the same name already exists.',
        'conditions_required' => 'Please enter the search conditions.',
    ],

    // Brand validation messages
    'brand' => [
        'name_required' => 'Please enter the brand name.',
        'slug_required' => 'Please enter the slug.',
        'slug_unique' => 'This slug is already in use.',
        'slug_format' => 'The slug must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens (-).',
        'website_invalid_url' => 'Please enter a valid URL format.',
    ],

    // Product label validation messages
    'label' => [
        'name_required' => 'Please enter the label name.',
        'color_required' => 'Please enter the label color.',
        'color_invalid' => 'The label color must be in #RRGGBB format.',
    ],

    // Category validation messages
    'category' => [
        'name_required' => 'Please enter the category name.',
        'slug_required' => 'Please enter the slug.',
        'slug_unique' => 'This slug is already in use.',
        'slug_format' => 'The slug must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens (-).',
        'parent_not_found' => 'Parent category not found.',
    ],

    // Product image validation messages
    'product_images' => [
        'file' => [
            'required' => 'Please select an image file.',
            'file' => 'The file is not valid.',
            'image' => 'Only image files can be uploaded.',
            'mimes' => 'Supported image formats: jpeg, png, jpg, gif, webp',
            'max' => 'Image file size must be 10MB or less.',
        ],
        'temp_key' => [
            'string' => 'Temp key must be a string.',
            'max' => 'Temp key must not exceed 64 characters.',
        ],
        'collection' => [
            'in' => 'Please select a valid collection.',
            'enum' => 'Please select a valid collection. (main, detail, additional)',
        ],
        'alt_text' => [
            'array' => 'Alt text must be an array.',
        ],
        'orders' => [
            'required' => 'Please enter the sort order.',
            'array' => 'Sort order must be an array.',
            'min' => 'Please enter at least one sort order.',
            'item' => [
                'required' => 'Please enter a sort order value.',
                'integer' => 'Sort order must be a number.',
                'min' => 'Sort order must be at least 0.',
            ],
        ],
    ],

    // Category image validation messages
    'category_images' => [
        'file' => [
            'required' => 'Please select an image file.',
            'file' => 'The file is not valid.',
            'image' => 'Only image files are allowed.',
            'mimes' => 'Supported image formats: jpeg, png, jpg, gif, svg, webp',
            'max' => 'Image file size must be 10MB or less.',
        ],
        'temp_key' => [
            'string' => 'Temp key must be a string.',
            'max' => 'Temp key cannot exceed 64 characters.',
        ],
        'collection' => [
            'in' => 'Please select a valid collection.',
        ],
        'alt_text' => [
            'array' => 'Alt text must be an array.',
        ],
        'orders' => [
            'required' => 'Please enter the sort order.',
            'array' => 'Sort order must be an array.',
            'min' => 'Please enter at least one sort order.',
            'item' => [
                'required' => 'Please enter a sort order value.',
                'integer' => 'Sort order must be a number.',
                'min' => 'Sort order must be at least 0.',
            ],
        ],
    ],

    // Product Notice Template validation messages
    'product_notice_template' => [
        'name_required' => 'Please enter the product category name.',
        'name_max' => 'Product category name cannot exceed 100 characters.',
        'fields_required' => 'Please add at least one field.',
        'fields_min' => 'Please add at least one field.',
        'field_name_required' => 'Please enter the field name.',
        'field_name_min' => 'Please enter the field name.',
        'field_name_max' => 'Field name cannot exceed 200 characters.',
        'field_content_required' => 'Please enter the content.',
        'field_content_min' => 'Please enter the content.',
        'field_content_max' => 'Content cannot exceed 2000 characters.',
    ],

    // Coupon validation messages
    'coupon' => [
        'name' => [
            'required' => 'Please enter the coupon name.',
        ],
        'name_ko' => [
            'required' => 'Please enter the Korean coupon name.',
        ],
        'coupon_code' => [
            'required' => 'Please enter the coupon code.',
            'unique' => 'This coupon code is already in use.',
        ],
        'target_type' => [
            'required' => 'Please select a target type.',
            'in' => 'Please select a valid target type.',
        ],
        'discount_type' => [
            'required' => 'Please select a discount type.',
            'in' => 'Please select a valid discount type.',
        ],
        'discount_value' => [
            'required' => 'Please enter a discount value.',
            'numeric' => 'Discount value must be a number.',
            'min' => 'Discount value must be at least 0.',
        ],
        'max_discount_amount' => [
            'numeric' => 'Maximum discount amount must be a number.',
            'min' => 'Maximum discount amount must be at least 0.',
        ],
        'min_order_amount' => [
            'numeric' => 'Minimum order amount must be a number.',
            'min' => 'Minimum order amount must be at least 0.',
        ],
        'max_issue_count' => [
            'integer' => 'Maximum issue count must be an integer.',
            'min' => 'Maximum issue count must be at least 0.',
        ],
        'max_use_count_per_user' => [
            'integer' => 'Maximum use count per user must be an integer.',
            'min' => 'Maximum use count per user must be at least 0.',
        ],
        'valid_from' => [
            'date' => 'Valid from must be a date.',
        ],
        'valid_until' => [
            'date' => 'Valid until must be a date.',
            'after_or_equal' => 'Valid until must be after or equal to valid from.',
        ],
        'issue_start_at' => [
            'date' => 'Issue start date must be a date.',
        ],
        'issue_end_at' => [
            'date' => 'Issue end date must be a date.',
            'after_or_equal' => 'Issue end date must be after or equal to issue start date.',
        ],
        'issue_status' => [
            'required' => 'Please select an issue status.',
            'in' => 'Please select a valid issue status.',
        ],
        'issue_method' => [
            'in' => 'Please select a valid issue method.',
        ],
        'issue_condition' => [
            'in' => 'Please select a valid issue condition.',
        ],
        'combinable' => [
            'boolean' => 'Combinable must be true or false.',
        ],
        'products' => [
            'array' => 'Products must be an array.',
        ],
        'categories' => [
            'array' => 'Categories must be an array.',
        ],
        'ids' => [
            'required' => 'Please select coupons to update.',
            'array' => 'Coupon IDs must be an array.',
            'min' => 'Please select at least one coupon.',
        ],
        // Flat format keys (unified with FormRequest)
        'name_required' => 'Please enter the coupon name.',
        'target_type_required' => 'Please select a target type.',
        'discount_type_required' => 'Please select a discount type.',
        'discount_value_required' => 'Please enter a discount value.',
        'discount_value_rate_min' => 'Discount rate must be at least 1%.',
        'discount_value_rate_max' => 'Discount rate must not exceed 100%.',
        'issue_method_required' => 'Please select an issue method.',
        'issue_condition_required' => 'Please select an issue condition.',
        'valid_type_required' => 'Please select a validity type.',
        'valid_days_required' => 'Please enter the number of valid days.',
        'valid_from_required' => 'Please enter the valid from date.',
        'valid_to_required' => 'Please enter the valid until date.',
        'valid_to_after_from' => 'Valid until date must be after or equal to valid from date.',
        'ids_required' => 'Please select coupons to update.',
        'ids_min' => 'Please select at least one coupon.',
        'issue_status_required' => 'Please select an issue status.',
        'issue_status_invalid' => 'Please select a valid issue status.',
        'id_required' => 'Coupon ID is required.',
        'id_integer' => 'Coupon ID must be an integer.',
        'id_not_found' => 'Coupon not found.',
    ],

    // Order validation messages (orders.* format - unified with FormRequest)
    'orders' => [
        'ids' => [
            'required' => 'Please select orders to update.',
            'array' => 'Order IDs must be an array.',
            'min' => 'Please select at least one order.',
            'exists' => 'Order not found.',
        ],
        'order_status' => [
            'required' => 'Please select an order status.',
            'array' => 'Order status must be an array.',
            'string' => 'Order status must be a string.',
            'in' => 'Please select a valid order status.',
        ],
        'carrier_id' => [
            'required' => 'Please select a carrier.',
            'exists' => 'The selected carrier does not exist.',
        ],
        'tracking_number' => [
            'required' => 'Please enter a tracking number.',
            'string' => 'Tracking number must be a string.',
            'max' => 'Tracking number cannot exceed 50 characters.',
            'requires_status' => 'Please also change the shipping status when entering a tracking number.',
        ],
        'admin_memo' => [
            'max' => 'Admin memo must not exceed 2000 characters.',
        ],
        'recipient_name' => [
            'required' => 'Please enter the recipient name.',
            'max' => 'Recipient name must not exceed 50 characters.',
        ],
        'recipient_phone' => [
            'required_without' => 'Either phone or tel number is required.',
            'max' => 'Recipient phone must not exceed 20 characters.',
        ],
        'recipient_tel' => [
            'required_without' => 'Either tel or phone number is required.',
            'max' => 'Recipient tel must not exceed 20 characters.',
        ],
        'recipient_zipcode' => [
            'required' => 'Please enter the zipcode.',
            'max' => 'Zipcode must not exceed 10 characters.',
        ],
        'recipient_address' => [
            'required' => 'Please enter an address. Use the zipcode search.',
            'max' => 'Address must not exceed 255 characters.',
        ],
        'recipient_detail_address' => [
            'required' => 'Please enter a detail address.',
            'max' => 'Detail address must not exceed 255 characters.',
        ],
        'delivery_memo' => [
            'max' => 'Delivery memo must not exceed 500 characters.',
        ],
        'recipient_country_code' => [
            'size' => 'Country code must be exactly 2 characters.',
        ],
        'email' => [
            'required' => 'Please enter an email address.',
            'email' => 'Please enter a valid email address.',
            'max' => 'Email address must not exceed 255 characters.',
        ],
        'email_message' => [
            'required' => 'Please enter an email message.',
            'max' => 'Email message must not exceed 5000 characters.',
        ],
        'not_found' => 'Order not found.',
        'cannot_update' => 'This order cannot be updated.',
        'cannot_cancel' => 'This order cannot be cancelled.',
        'cannot_refund' => 'This order cannot be refunded.',
        'carrier_required' => 'A carrier is required for the selected status.',
        'tracking_number_required' => 'A tracking number is required for the selected status.',
        // Bulk processing related
        'bulk_update' => [
            'at_least_one' => 'Please enter at least one of: order status, carrier, or tracking number.',
        ],
        // Search/list related
        'search_field' => [
            'in' => 'Please select a valid search field.',
        ],
        'search_keyword' => [
            'string' => 'Search keyword must be a string.',
            'max' => 'Search keyword cannot exceed 200 characters.',
        ],
        'date_type' => [
            'in' => 'Please select a valid date type.',
        ],
        'start_date' => [
            'date' => 'Start date must be a valid date.',
        ],
        'end_date' => [
            'date' => 'End date must be a valid date.',
            'after_or_equal' => 'End date must be after or equal to start date.',
        ],
        // Option status
        'option_status' => [
            'array' => 'Option status must be an array.',
            'string' => 'Option status must be a string.',
            'in' => 'Please select a valid option status.',
        ],
        // Shipping type
        'shipping_type' => [
            'array' => 'Shipping type must be an array.',
            'string' => 'Shipping type must be a string.',
            'in' => 'Please select a valid shipping type.',
        ],
        // Payment method
        'payment_method' => [
            'array' => 'Payment method must be an array.',
            'string' => 'Payment method must be a string.',
            'in' => 'Please select a valid payment method.',
        ],
        // Category
        'category_id' => [
            'integer' => 'Category ID must be an integer.',
        ],
        // Amount range
        'min_amount' => [
            'integer' => 'Minimum amount must be an integer.',
            'min' => 'Minimum amount must be at least 0.',
        ],
        'max_amount' => [
            'integer' => 'Maximum amount must be an integer.',
            'min' => 'Maximum amount must be at least 0.',
        ],
        // Country codes
        'country_codes' => [
            'array' => 'Country codes must be an array.',
            'string' => 'Country code must be a string.',
            'size' => 'Country code must be 2 characters.',
        ],
        // Order device
        'order_device' => [
            'array' => 'Order device must be an array.',
            'string' => 'Order device must be a string.',
            'in' => 'Please select a valid order device.',
        ],
        // User ID
        'user_id' => [
            'integer' => 'User ID must be an integer.',
        ],
        // Orderer UUID
        'orderer_uuid' => [
            'uuid' => 'Orderer UUID format is invalid.',
        ],
        // Sorting and pagination
        'sort_by' => [
            'in' => 'Please select a valid sort field.',
        ],
        'sort_order' => [
            'in' => 'Sort order must be asc or desc.',
        ],
        'per_page' => [
            'integer' => 'Items per page must be an integer.',
            'min' => 'Items per page must be at least 10.',
            'max' => 'Items per page cannot exceed 100.',
        ],
        'page' => [
            'integer' => 'Page number must be an integer.',
            'min' => 'Page number must be at least 1.',
        ],
        'admin_memo' => [
            'max' => 'Admin memo cannot exceed 1000 characters.',
        ],
    ],

    // Order option quantity split validation
    'quantity_exceeds_available' => 'The change quantity exceeds the available quantity.',
    'quantity_min_one' => 'The change quantity must be at least 1.',

    // Order option bulk status change validation
    'order_options' => [
        'items' => [
            'required' => 'Please select options to change.',
            'min' => 'Please select at least one option.',
        ],
        'option_id' => [
            'required' => 'Option ID is required.',
            'exists' => 'The selected option does not exist.',
        ],
        'quantity' => [
            'required' => 'Please enter the change quantity.',
            'min' => 'The change quantity must be at least 1.',
        ],
        'status' => [
            'required' => 'Please select a status to change.',
            'in' => 'Please select a valid option status.',
        ],
    ],

    // Order validation messages (backward compatibility - order.* format)
    'order' => [
        'ids' => [
            'required' => 'Please select orders to update.',
            'array' => 'Order IDs must be an array.',
            'min' => 'Please select at least one order.',
        ],
        'order_status' => [
            'required' => 'Please select an order status.',
            'in' => 'Please select a valid order status.',
        ],
        'carrier_id' => [
            'required' => 'Please select a carrier.',
            'exists' => 'The selected carrier does not exist.',
        ],
        'tracking_number' => [
            'required' => 'Please enter a tracking number.',
            'string' => 'Tracking number must be a string.',
            'max' => 'Tracking number cannot exceed 100 characters.',
        ],
        'not_found' => 'Order not found.',
        'cannot_update' => 'This order cannot be updated.',
        'cannot_cancel' => 'This order cannot be cancelled.',
        'cannot_refund' => 'This order cannot be refunded.',
        // Order creation (checkout) validation messages
        'orderer_name_required' => 'Please enter the orderer name.',
        'orderer_phone_required' => 'Please enter the orderer phone number.',
        'orderer_email_invalid' => 'Please enter a valid email address.',
        'recipient_name_required' => 'Please enter the recipient name.',
        'recipient_phone_required' => 'Please enter the recipient phone number.',
        'recipient_phone_required_without' => 'Please enter either a mobile phone or landline number.',
        'recipient_tel_required_without' => 'Please enter either a mobile phone or landline number.',
        'zipcode_required' => 'Please enter the postal code.',
        'address_required' => 'Please enter the address.',
        'address_detail_required' => 'Please enter the detail address.',
        'address_line_1_required' => 'Please enter the address.',
        'intl_city_required' => 'Please enter the city.',
        'intl_postal_code_required' => 'Please enter the postal code.',
        'payment_method_required' => 'Please select a payment method.',
        'payment_method_invalid' => 'Please select a valid payment method.',
        'expected_total_amount_required' => 'Expected total amount is required.',
        'expected_total_amount_numeric' => 'Expected total amount must be a number.',
        'depositor_name_required' => 'Please enter the depositor name.',
        'dbank_bank_code_required' => 'Please select a bank.',
        'dbank_bank_name_required' => 'Bank name is required.',
        'dbank_account_number_required' => 'Account number is required.',
        'dbank_account_holder_required' => 'Account holder name is required.',
    ],

    // Order bulk processing validation messages (backward compatibility)
    'order_bulk' => [
        'ids_required' => 'Please select orders to update.',
        'status_or_shipping_required' => 'Either order status or shipping information must be provided.',
        'invalid_status' => 'Invalid order status.',
        'invalid_carrier' => 'Invalid carrier.',
    ],

    // Order export validation messages
    'order_export' => [
        'format' => [
            'required' => 'Please select an export format.',
            'in' => 'Please select a valid export format.',
        ],
        'columns' => [
            'required' => 'Please select columns to export.',
            'array' => 'Columns must be an array.',
            'min' => 'Please select at least one column.',
        ],
    ],

    // Cart validation messages
    'cart' => [
        'product_id_required' => 'Please select a product.',
        'product_not_found' => 'Product not found.',
        'option_id_required' => 'Please select a product option.',
        'option_not_found' => 'Product option not found.',
        'quantity_required' => 'Please enter quantity.',
        'quantity_min' => 'Quantity must be at least 1.',
        'quantity_max' => 'Quantity must be at most 9999.',
        'ids_required' => 'Please select items to delete.',
        'ids_array' => 'Item IDs must be an array.',
        'ids_min' => 'Please select at least one item.',
        'item_not_found' => 'Cart item not found.',
        'product_id_required' => 'Please select a product.',
        'product_not_found' => 'Product not found.',
        'option_id_required' => 'Please select an option.',
        'option_not_found' => 'Option not found.',
        'quantity_required' => 'Please enter a quantity.',
        'quantity_min' => 'Quantity must be at least 1.',
        'quantity_max' => 'Quantity cannot exceed 9,999.',
        'items_required' => 'Please select items to add to cart.',
        'items_min' => 'Please select at least one item.',
        'option_values_not_found' => 'Matching option combination not found.',
    ],

    // Wishlist validation messages
    'wishlist' => [
        'product_id_required' => 'Please select a product.',
        'product_not_found' => 'Product not found.',
        'selected_ids_array' => 'Selected item IDs must be an array.',
        'selected_ids_integer' => 'Selected item ID must be a number.',
        'selected_ids_min' => 'Selected item ID must be at least 1.',
        'cart_key_required' => 'Guest cart key is required.',
        'invalid_cart_key' => 'Invalid cart key format.',
        'login_required' => 'Login is required.',
    ],

    // Checkout validation messages
    'checkout' => [
        'item_ids_required' => 'Please select items to order.',
        'item_ids_array' => 'Item IDs must be an array.',
        'item_ids_min' => 'Please select at least one item.',
        'use_points_integer' => 'Points must be a number.',
        'use_points_min' => 'Points must be at least 0.',
        'coupon_issue_ids_array' => 'Coupon IDs must be an array.',
        'coupon_issue_id_integer' => 'Coupon ID must be a number.',
        'country_code_size' => 'Country code must be 2 characters.',
        'zipcode_max' => 'Zipcode cannot exceed 20 characters.',
        'region_max' => 'Region cannot exceed 100 characters.',
        'city_max' => 'City cannot exceed 100 characters.',
        'address_max' => 'Address cannot exceed 255 characters.',
    ],

    // Shipping policy validation messages
    'shipping_policy' => [
        'ids_required' => 'Please select shipping policies to update.',
        'ids_array' => 'Shipping policy IDs must be an array.',
        'ids_min' => 'Please select at least one shipping policy.',
        'id_integer' => 'Shipping policy ID must be a number.',
        'id_exists' => 'Shipping policy not found.',
        'is_active_required' => 'Please select active status.',
        'is_active_boolean' => 'Active status must be true or false.',
        'base_fee_zero_not_allowed' => 'Shipping fee cannot be 0 for non-free shipping policies.',
        'custom_shipping_name_required' => 'Please enter a shipping method name when selecting custom.',
        'ranges' => [
            'first_min_zero' => 'The first tier must start at 0.',
            'last_max_unlimited' => 'The last tier must have no upper limit.',
            'continuity' => 'Ranges are not continuous.',
            'min_less_than_max' => 'The minimum must be less than the maximum.',
            'fee_non_negative' => 'The fee must be 0 or greater.',
        ],
    ],

    // Extra fee template validation messages
    'extra_fee_template' => [
        'zipcode_required' => 'Please enter the zipcode.',
        'zipcode_unique' => 'This zipcode is already registered.',
        'zipcode_max' => 'Zipcode cannot exceed 10 characters.',
        'zipcode_format' => 'Zipcode must contain only numbers and hyphens (-). (e.g., 12345 or 12345-12399)',
        'fee_required' => 'Please enter the extra fee.',
        'fee_numeric' => 'Extra fee must be a number.',
        'fee_min' => 'Extra fee must be at least 0.',
        'ids_required' => 'Please select items to update.',
        'ids_array' => 'Item IDs must be an array.',
        'ids_min' => 'Please select at least one item.',
        'id_not_found' => 'Item not found.',
        'is_active_required' => 'Please select active status.',
        'is_active_boolean' => 'Active status must be true or false.',
        'items_required' => 'Please enter items to register.',
        'items_array' => 'Items must be an array.',
        'items_min' => 'Please enter at least one item.',
        'items_max' => 'You can register up to 100 items at once.',
        'item_zipcode_required' => 'Please enter the zipcode.',
        'item_fee_required' => 'Please enter the extra fee.',
    ],

    // Product common info validation messages
    'product_common_info' => [
        'name_required' => 'Please enter the common info name.',
        'name_max' => 'Common info name cannot exceed 100 characters.',
        'content_mode_invalid' => 'Please select a valid content mode (text or html).',
    ],

    // Coupon issues list validation messages
    'coupon_issues' => [
        'user_id_integer' => 'User ID must be an integer.',
        'user_id_exists' => 'User not found.',
        'status_in' => 'Invalid status value.',
        'per_page_integer' => 'Items per page must be an integer.',
        'per_page_min' => 'Items per page must be at least 1.',
        'per_page_max' => 'Items per page cannot exceed 100.',
    ],

    // Search preset validation messages
    'search_preset' => [
        'target_screen_in' => 'Invalid target screen.',
    ],

    // Category reorder validation messages
    'category_reorder' => [
        'parent_menus_required' => 'Parent menus or child menus data is required.',
        'parent_menus_array' => 'Parent menus must be an array.',
        'id_required' => 'Category ID is required.',
        'id_integer' => 'Category ID must be an integer.',
        'id_exists' => 'Category not found.',
        'order_required' => 'Order value is required.',
        'order_integer' => 'Order value must be an integer.',
        'order_min' => 'Order value must be at least 0.',
    ],

    // Review validation messages (admin)
    'reviews' => [
        'search_field' => [
            'in' => 'Please select a valid search field.',
        ],
        'search_keyword' => [
            'string' => 'Search keyword must be a string.',
            'max' => 'Search keyword cannot exceed :max characters.',
        ],
        'rating' => [
            'in' => 'Please select a valid rating.',
        ],
        'reply_status' => [
            'in' => 'Please select a valid reply status.',
        ],
        'has_photo' => [
            'boolean' => 'Photo review filter must be true or false.',
        ],
        'status' => [
            'in' => 'Please select a valid review status.',
        ],
        'start_date' => [
            'date' => 'Start date must be a valid date.',
        ],
        'end_date' => [
            'date' => 'End date must be a valid date.',
            'after_or_equal' => 'End date must be after or equal to start date.',
        ],
        'sort_by' => [
            'in' => 'Please select a valid sort field.',
        ],
        'sort_order' => [
            'in' => 'Sort order must be asc or desc.',
        ],
        'per_page' => [
            'integer' => 'Items per page must be an integer.',
            'min' => 'Items per page must be at least :min.',
            'max' => 'Items per page cannot exceed :max.',
        ],
        'page' => [
            'integer' => 'Page number must be an integer.',
            'min' => 'Page number must be at least 1.',
        ],
    ],

    // Public product list validation messages
    'public_product' => [
        'category_id' => [
            'integer' => 'Category ID must be an integer.',
        ],
        'category_slug' => [
            'string' => 'Category slug must be a string.',
            'max' => 'Category slug cannot exceed :max characters.',
        ],
        'brand_id' => [
            'integer' => 'Brand ID must be an integer.',
        ],
        'search' => [
            'string' => 'Search term must be a string.',
            'max' => 'Search term cannot exceed :max characters.',
        ],
        'sort' => [
            'in' => 'Please select a valid sort option.',
        ],
        'min_price' => [
            'integer' => 'Minimum price must be an integer.',
            'min' => 'Minimum price must be at least 0.',
        ],
        'max_price' => [
            'integer' => 'Maximum price must be an integer.',
            'min' => 'Maximum price must be at least 0.',
        ],
        'per_page' => [
            'integer' => 'Items per page must be an integer.',
            'min' => 'Items per page must be at least :min.',
            'max' => 'Items per page cannot exceed :max.',
        ],
        'limit' => [
            'integer' => 'Limit must be an integer.',
            'min' => 'Limit must be at least :min.',
            'max' => 'Limit cannot exceed :max.',
        ],
        'ids' => [
            'string' => 'Product IDs must be a string.',
            'max' => 'Product IDs cannot exceed :max characters.',
        ],
    ],

    // Public review list validation messages
    'public_review' => [
        'sort' => [
            'in' => 'Please select a valid sort option.',
        ],
        'photo_only' => [
            'boolean' => 'Photo only filter must be true or false.',
        ],
        'page' => [
            'integer' => 'Page number must be an integer.',
            'min' => 'Page number must be at least 1.',
        ],
        'per_page' => [
            'integer' => 'Items per page must be an integer.',
            'min' => 'Items per page must be at least :min.',
            'max' => 'Items per page cannot exceed :max.',
        ],
        'rating' => [
            'in' => 'Please select a valid rating.',
        ],
    ],

    // User coupon validation messages
    'user_coupon' => [
        'status' => [
            'in' => 'Please select a valid coupon status.',
        ],
        'per_page' => [
            'integer' => 'Items per page must be an integer.',
            'min' => 'Items per page must be at least :min.',
            'max' => 'Items per page cannot exceed :max.',
        ],
        'product_ids' => [
            'array' => 'Product IDs must be an array.',
        ],
        'product_ids_item' => [
            'integer' => 'Product ID must be an integer.',
        ],
    ],

    // User mileage validation messages
    'user_mileage' => [
        'order_amount' => [
            'required' => 'Order amount is required.',
            'integer' => 'Order amount must be an integer.',
            'min' => 'Order amount must be at least 0.',
        ],
    ],

    // Field name translations (Laravel standard)
    'attributes' => [
        // Basic info
        'basic_info' => 'Basic Information',
        'basic_info.shop_name' => 'Shop Name',
        'basic_info.route_path' => 'Route Path',
        'basic_info.company_name' => 'Company Name',
        'basic_info.business_number_1' => 'Business Number',
        'basic_info.business_number_2' => 'Business Number',
        'basic_info.business_number_3' => 'Business Number',
        'basic_info.ceo_name' => 'CEO Name',
        'basic_info.business_type' => 'Business Type',
        'basic_info.business_category' => 'Business Category',
        'basic_info.zipcode' => 'Zip Code',
        'basic_info.base_address' => 'Base Address',
        'basic_info.detail_address' => 'Detail Address',
        'basic_info.phone_1' => 'Phone Number',
        'basic_info.phone_2' => 'Phone Number',
        'basic_info.phone_3' => 'Phone Number',
        'basic_info.fax_1' => 'Fax Number',
        'basic_info.fax_2' => 'Fax Number',
        'basic_info.fax_3' => 'Fax Number',
        'basic_info.email_id' => 'Email',
        'basic_info.email_domain' => 'Email',
        'basic_info.privacy_officer' => 'Privacy Officer',
        'basic_info.privacy_officer_email' => 'Privacy Officer Email',
        'basic_info.mail_order_number' => 'Mail Order Business Number',
        'basic_info.telecom_number' => 'Telecom Business Number',

        // Language/Currency settings
        'language_currency' => 'Language/Currency Settings',
        'language_currency.default_language' => 'Default Language',
        'language_currency.default_currency' => 'Default Currency',
        'language_currency.currencies' => 'Currencies',
        'language_currency.currencies.*.code' => 'Currency Code',
        'language_currency.currencies.*.name' => 'Currency Name',
        'language_currency.currencies.*.name.*' => 'Currency Name',
        'language_currency.currencies.*.exchange_rate' => 'Exchange Rate',
        'language_currency.currencies.*.rounding_unit' => 'Rounding Unit',
        'language_currency.currencies.*.rounding_method' => 'Rounding Method',
        'language_currency.currencies.*.decimal_places' => 'Decimal Places',

        // SEO settings
        'seo' => 'SEO Settings',
        'seo.meta_main_title' => 'Main Page Title',
        'seo.meta_main_description' => 'Main Page Description',
        'seo.meta_category_title' => 'Category Page Title',
        'seo.meta_category_description' => 'Category Page Description',
        'seo.meta_search_title' => 'Search Page Title',
        'seo.meta_search_description' => 'Search Page Description',
        'seo.meta_product_title' => 'Product Page Title',
        'seo.meta_product_description' => 'Product Page Description',
        'seo.meta_shop_index_title' => 'Shop Main Page Title',
        'seo.meta_shop_index_description' => 'Shop Main Page Description',
        'seo.seo_shop_index' => 'Shop Main Page SEO',
        'seo.seo_user_agents' => 'SEO User Agents',

        // Order settings
        'order_settings.payment_methods' => 'Payment Methods',
        'order_settings.payment_methods.*.id' => 'Payment Method ID',
        'order_settings.payment_methods.*.sort_order' => 'Payment Method Sort Order',
        'order_settings.payment_methods.*.is_active' => 'Payment Method Active Status',
        'order_settings.payment_methods.*.min_order_amount' => 'Minimum Order Amount',
        'order_settings.payment_methods.*.stock_deduction_timing' => 'Stock Deduction Timing',
        'order_settings.banks' => 'Bank List',
        'order_settings.bank_accounts' => 'Bank Accounts',
        'order_settings.bank_accounts.*.bank_code' => 'Bank Code',
        'order_settings.bank_accounts.*.account_number' => 'Account Number',
        'order_settings.bank_accounts.*.account_holder' => 'Account Holder',
        'order_settings.bank_accounts.*.is_active' => 'Account Active Status',
        'order_settings.bank_accounts.*.is_default' => 'Default Account',
        'order_settings.auto_cancel_expired' => 'Auto Cancel Unpaid Orders',
        'order_settings.auto_cancel_days' => 'Auto Cancel Days',
        'order_settings.vbank_due_days' => 'Virtual Account Due Days',
        'order_settings.dbank_due_days' => 'Bank Transfer Due Days',
        'order_settings.cart_expiry_days' => 'Cart Expiry Days',
        'order_settings.default_pg_provider' => 'Default PG Provider',
        'order_settings.payment_methods.*.pg_provider' => 'PG Provider',
        'order_settings.stock_restore_on_cancel' => 'Restore Stock on Cancel',

        // Order information
        'order_number' => 'Order Number',
        'order_status' => 'Order Status',
        'payment_status' => 'Payment Status',
        'payment_method' => 'Payment Method',
        'total_amount' => 'Total Amount',
        'total_paid_amount' => 'Total Paid Amount',
        'ordered_at' => 'Order Date',
        'paid_at' => 'Payment Date',
        'carrier_id' => 'Carrier',
        'tracking_number' => 'Tracking Number',
        'shipping_status' => 'Shipping Status',
        'shipping_type' => 'Shipping Type',
        'orderer_name' => 'Orderer Name',
        'orderer_phone' => 'Orderer Phone',
        'orderer_email' => 'Orderer Email',
        'recipient_name' => 'Recipient Name',
        'recipient_phone' => 'Recipient Phone',
        'recipient_zipcode' => 'Zip Code',
        'recipient_address' => 'Shipping Address',
        'recipient_detail_address' => 'Detail Address',
        'recipient_country_code' => 'Country Code',
        'delivery_memo' => 'Delivery Memo',
        'address_id' => 'Address',

        // Product labels
        'label_name' => 'Label Name',
        'label_color' => 'Label Color',
        'is_active' => 'Active Status',
        'sort_order' => 'Sort Order',
    ],

    // Field-specific custom validation messages
    'custom' => [
        'basic_info' => [
            'shop_name' => [
                'required' => 'Shop name is required.',
                'string' => 'Shop name must be a string.',
                'max' => 'Shop name cannot exceed 255 characters.',
            ],
            'route_path' => [
                'required' => 'Route path is required.',
                'string' => 'Route path must be a string.',
                'max' => 'Route path cannot exceed 100 characters.',
            ],
            'no_route' => [
                'boolean' => 'No route option must be true or false.',
            ],
            'company_name' => [
                'string' => 'Company name must be a string.',
                'max' => 'Company name cannot exceed 255 characters.',
            ],
            'business_number' => [
                'string' => 'Business number must be a string.',
                'max' => 'Business number format is invalid.',
            ],
            'ceo_name' => [
                'string' => 'CEO name must be a string.',
                'max' => 'CEO name cannot exceed 100 characters.',
            ],
            'business_type' => [
                'string' => 'Business type must be a string.',
                'max' => 'Business type cannot exceed 100 characters.',
            ],
            'business_category' => [
                'string' => 'Business category must be a string.',
                'max' => 'Business category cannot exceed 255 characters.',
            ],
            'zipcode' => [
                'string' => 'Zip code must be a string.',
                'max' => 'Zip code cannot exceed 10 characters.',
            ],
            'base_address' => [
                'string' => 'Base address must be a string.',
                'max' => 'Base address cannot exceed 500 characters.',
            ],
            'detail_address' => [
                'string' => 'Detail address must be a string.',
                'max' => 'Detail address cannot exceed 255 characters.',
            ],
            'phone' => [
                'string' => 'Phone number must be a string.',
                'max' => 'Phone number format is invalid.',
            ],
            'fax' => [
                'string' => 'Fax number must be a string.',
                'max' => 'Fax number format is invalid.',
            ],
            'email_id' => [
                'string' => 'Email ID must be a string.',
                'max' => 'Email ID cannot exceed 100 characters.',
            ],
            'email_domain' => [
                'string' => 'Email domain must be a string.',
                'max' => 'Email domain cannot exceed 100 characters.',
            ],
            'privacy_officer' => [
                'string' => 'Privacy officer must be a string.',
                'max' => 'Privacy officer cannot exceed 100 characters.',
            ],
            'privacy_officer_email' => [
                'email' => 'Please enter a valid email address.',
                'max' => 'Privacy officer email cannot exceed 255 characters.',
            ],
            'mail_order_number' => [
                'string' => 'Mail order business number must be a string.',
                'max' => 'Mail order business number cannot exceed 100 characters.',
            ],
            'telecom_number' => [
                'string' => 'Telecom business number must be a string.',
                'max' => 'Telecom business number cannot exceed 100 characters.',
            ],
        ],
        'language_currency' => [
            'default_language' => [
                'string' => 'Default language must be a string.',
                'max' => 'Default language cannot exceed 10 characters.',
            ],
            'default_currency' => [
                'string' => 'Default currency must be a string.',
                'max' => 'Default currency cannot exceed 10 characters.',
            ],
            'currencies' => [
                'duplicate_code' => 'Duplicate currency code found.',
                'name_required' => 'Currency name must be entered in at least one language.',
                'code' => [
                    'required_with' => 'Currency code is required.',
                    'string' => 'Currency code must be a string.',
                    'max' => 'Currency code cannot exceed 10 characters.',
                ],
                'name' => [
                    'required_with' => 'Currency name is required.',
                    'array' => 'Currency name must be an array.',
                    'string' => 'Currency name must be a string.',
                    'max' => 'Currency name cannot exceed 100 characters.',
                ],
                'exchange_rate' => [
                    'numeric' => 'Exchange rate must be a number.',
                    'min' => 'Exchange rate must be at least 0.',
                ],
                'rounding_unit' => [
                    'string' => 'Rounding unit must be a string.',
                ],
                'rounding_method' => [
                    'string' => 'Rounding method must be a string.',
                    'in' => 'The rounding method must be one of: floor, round, ceil.',
                ],
                'decimal_places' => [
                    'integer' => 'Decimal places must be an integer.',
                    'min' => 'Decimal places must be at least 0.',
                    'max' => 'Decimal places cannot exceed 8.',
                ],
                'is_default' => [
                    'boolean' => 'Default currency option must be true or false.',
                ],
            ],
        ],
        'seo' => [
            'meta_main_title' => [
                'string' => 'Main page title must be a string.',
                'max' => 'Main page title cannot exceed 500 characters.',
            ],
            'meta_main_description' => [
                'string' => 'Main page description must be a string.',
                'max' => 'Main page description cannot exceed 1000 characters.',
            ],
            'meta_category_title' => [
                'string' => 'Category page title must be a string.',
                'max' => 'Category page title cannot exceed 500 characters.',
            ],
            'meta_category_description' => [
                'string' => 'Category page description must be a string.',
                'max' => 'Category page description cannot exceed 1000 characters.',
            ],
            'meta_search_title' => [
                'string' => 'Search page title must be a string.',
                'max' => 'Search page title cannot exceed 500 characters.',
            ],
            'meta_search_description' => [
                'string' => 'Search page description must be a string.',
                'max' => 'Search page description cannot exceed 1000 characters.',
            ],
            'meta_product_title' => [
                'string' => 'Product page title must be a string.',
                'max' => 'Product page title cannot exceed 500 characters.',
            ],
            'meta_product_description' => [
                'string' => 'Product page description must be a string.',
                'max' => 'Product page description cannot exceed 1000 characters.',
            ],
            'seo_site_main' => [
                'boolean' => 'Main page SEO option must be true or false.',
            ],
            'seo_category' => [
                'boolean' => 'Category page SEO option must be true or false.',
            ],
            'seo_search_result' => [
                'boolean' => 'Search result page SEO option must be true or false.',
            ],
            'seo_product_detail' => [
                'boolean' => 'Product detail page SEO option must be true or false.',
            ],
            'meta_shop_index_title' => [
                'string' => 'Shop main page title must be a string.',
                'max' => 'Shop main page title cannot exceed 500 characters.',
            ],
            'meta_shop_index_description' => [
                'string' => 'Shop main page description must be a string.',
                'max' => 'Shop main page description cannot exceed 1000 characters.',
            ],
            'seo_shop_index' => [
                'boolean' => 'Shop main page SEO option must be true or false.',
            ],
            'seo_user_agents' => [
                'string' => 'SEO user agent must be a string.',
                'max' => 'SEO user agent cannot exceed 100 characters.',
            ],
        ],
        'banks' => [
            'code' => [
                'required_with' => 'Bank code is required.',
                'string' => 'Bank code must be a string.',
                'max' => 'Bank code cannot exceed 10 characters.',
            ],
            'name' => [
                'required_with' => 'Bank name is required.',
                'array' => 'Bank name must be a multilingual array.',
                'string' => 'Bank name must be a string.',
                'max' => 'Bank name cannot exceed 100 characters.',
            ],
        ],
        'order_settings' => [
            'payment_methods' => [
                'at_least_one_active' => 'At least one payment method must be active.',
                'id' => [
                    'required_with' => 'Payment method ID is required.',
                    'string' => 'Payment method ID must be a string.',
                ],
                'sort_order' => [
                    'integer' => 'Payment method sort order must be an integer.',
                    'min' => 'Payment method sort order must be at least 1.',
                ],
                'is_active' => [
                    'boolean' => 'Payment method active status must be true or false.',
                ],
                'min_order_amount' => [
                    'integer' => 'Minimum order amount must be an integer.',
                    'min' => 'Minimum order amount must be at least 0.',
                ],
                'stock_deduction_timing' => [
                    'string' => 'Stock deduction timing must be a string.',
                    'in' => 'Stock deduction timing must be one of: order_placed, payment_complete, or none.',
                ],
                'pg_required_for_activation' => 'Select a PG provider to activate this payment method.',
            ],
            'bank_accounts' => [
                'at_least_one_active_default' => 'At least one bank account must be both active and set as default.',
                'bank_code' => [
                    'required_with' => 'Bank is required.',
                    'string' => 'Bank code must be a string.',
                ],
                'account_number' => [
                    'required_with' => 'Account number is required.',
                    'string' => 'Account number must be a string.',
                    'max' => 'Account number cannot exceed 50 characters.',
                ],
                'account_holder' => [
                    'required_with' => 'Account holder is required.',
                    'string' => 'Account holder must be a string.',
                    'max' => 'Account holder cannot exceed 100 characters.',
                ],
                'is_active' => [
                    'boolean' => 'Account active status must be true or false.',
                ],
                'is_default' => [
                    'boolean' => 'Default account option must be true or false.',
                ],
            ],
            'auto_cancel_expired' => [
                'boolean' => 'Auto cancel unpaid orders option must be true or false.',
            ],
            'auto_cancel_days' => [
                'integer' => 'Auto cancel days must be an integer.',
                'min' => 'Auto cancel days must be at least 0.',
                'max' => 'Auto cancel days cannot exceed 30.',
            ],
            'vbank_due_days' => [
                'integer' => 'Virtual account due days must be an integer.',
                'min' => 'Virtual account due days must be at least 1.',
                'max' => 'Virtual account due days cannot exceed 30.',
            ],
            'dbank_due_days' => [
                'integer' => 'Bank transfer due days must be an integer.',
                'min' => 'Bank transfer due days must be at least 1.',
                'max' => 'Bank transfer due days cannot exceed 30.',
            ],
            'cart_expiry_days' => [
                'integer' => 'Cart expiry days must be an integer.',
                'min' => 'Cart expiry days must be at least 1.',
                'max' => 'Cart expiry days cannot exceed 365.',
            ],
            'stock_restore_on_cancel' => [
                'boolean' => 'Restore stock on cancel option must be true or false.',
            ],
        ],
        'shipping' => [
            'default_country' => [
                'string' => 'Default country must be a string.',
                'max' => 'Default country must not exceed 10 characters.',
                'must_exist_in_countries' => 'Default country must exist in the available countries list.',
            ],
            'available_countries' => [
                'array' => 'Available countries must be an array.',
                'duplicate_code' => 'Duplicate country code found.',
                'name_required' => 'Country name must be provided in at least one language.',
                'code' => [
                    'required_with' => 'Country code is required.',
                    'string' => 'Country code must be a string.',
                    'max' => 'Country code must not exceed 10 characters.',
                ],
                'name' => [
                    'required_with' => 'Country name is required.',
                    'array' => 'Country name must be an array.',
                    'string' => 'Country name must be a string.',
                    'max' => 'Country name must not exceed 100 characters.',
                ],
                'is_active' => [
                    'boolean' => 'Country active status must be true or false.',
                ],
            ],
            'international_shipping_enabled' => [
                'boolean' => 'International shipping option must be true or false.',
            ],
            'remote_area_enabled' => [
                'boolean' => 'Remote area option must be true or false.',
            ],
            'remote_area_extra_fee' => [
                'integer' => 'Remote area extra fee must be an integer.',
                'min' => 'Remote area extra fee must be at least 0.',
            ],
            'island_extra_fee' => [
                'integer' => 'Island extra fee must be an integer.',
                'min' => 'Island extra fee must be at least 0.',
            ],
            'free_shipping_threshold' => [
                'integer' => 'Free shipping threshold must be an integer.',
                'min' => 'Free shipping threshold must be at least 0.',
            ],
            'free_shipping_enabled' => [
                'boolean' => 'Free shipping option must be true or false.',
            ],
            'address_validation_enabled' => [
                'boolean' => 'Address validation option must be true or false.',
            ],
            'address_api_provider' => [
                'string' => 'Address API provider must be a string.',
                'max' => 'Address API provider must not exceed 50 characters.',
            ],
            'types' => [
                'duplicate_code' => 'Duplicate shipping type code found.',
                'name_required' => 'Shipping type name is required.',
                'code' => [
                    'required_with' => 'Shipping type code is required.',
                    'string' => 'Shipping type code must be a string.',
                    'max' => 'Shipping type code must not exceed 50 characters.',
                    'regex' => 'Shipping type code must contain only lowercase letters, numbers, hyphens, and underscores.',
                ],
                'name' => [
                    'required_with' => 'Shipping type name is required.',
                    'array' => 'Shipping type name must be a multilingual array.',
                ],
                'category' => [
                    'required_with' => 'Shipping type category is required.',
                    'in' => 'Shipping type category must be one of: domestic, international, other.',
                ],
                'is_active' => [
                    'boolean' => 'Shipping type active status must be true or false.',
                ],
            ],
            'carriers' => [
                'duplicate_code' => 'Duplicate carrier code found.',
                'name_required' => 'Carrier name is required.',
                'code' => [
                    'required_with' => 'Carrier code is required.',
                    'string' => 'Carrier code must be a string.',
                    'max' => 'Carrier code must not exceed 50 characters.',
                    'regex' => 'Carrier code must contain only lowercase letters, numbers, hyphens, and underscores.',
                ],
                'name' => [
                    'required_with' => 'Carrier name is required.',
                    'array' => 'Carrier name must be a multilingual array.',
                    'string' => 'Carrier name must be a string.',
                    'max' => 'Carrier name must not exceed 100 characters.',
                ],
                'name_ko' => [
                    'required_with' => 'Carrier name (Korean) is required.',
                    'string' => 'Carrier name (Korean) must be a string.',
                    'max' => 'Carrier name (Korean) must not exceed 100 characters.',
                ],
                'type' => [
                    'required_with' => 'Carrier type is required.',
                    'in' => 'Carrier type must be either domestic or international.',
                ],
                'tracking_url' => [
                    'string' => 'Tracking URL must be a string.',
                    'max' => 'Tracking URL must not exceed 500 characters.',
                ],
                'is_active' => [
                    'boolean' => 'Carrier active status must be true or false.',
                ],
            ],
        ],
    ],

    // User address validation messages
    'user_address' => [
        'name_required' => 'Address label is required.',
        'name_string' => 'Address label must be a string.',
        'recipient_name_required' => 'Recipient name is required.',
        'recipient_name_string' => 'Recipient name must be a string.',
        'recipient_phone_required' => 'Recipient phone is required.',
        'recipient_phone_string' => 'Recipient phone must be a string.',
        'zipcode_required' => 'Zip code is required.',
        'address_required' => 'Address is required.',
        'address_line_1_required' => 'Address Line 1 is required.',
        'intl_city_required' => 'City is required for international addresses.',
        'intl_postal_code_required' => 'Postal code is required for international addresses.',
    ],

    // Review image upload validation
    'review_image' => [
        'image_required' => 'Please select an image.',
        'image_file' => 'Invalid file format.',
        'image_image' => 'Only image files can be uploaded.',
        'image_max' => 'Image size cannot exceed 10MB.',
    ],
];
