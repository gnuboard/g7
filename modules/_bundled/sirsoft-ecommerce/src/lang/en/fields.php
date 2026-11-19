<?php

/**
 * Names and help texts of the member fields this module declares in the core.user field set.
 *
 * The declaration (`getFields()` in `module.php`) reads this file directly to build the multilingual arrays.
 */
return [
    'user' => [
        'ecommerce_preferred_currency' => [
            'name' => 'Payment Currency',
            'description' => 'The currency used for orders and payments.',
        ],
        'ecommerce_preferred_shipping_country' => [
            'name' => 'Shipping Country',
            'description' => 'The shipping country used by default.',
            // Shown as the field help on the profile edit screen when the saved shipping country is no longer available
            'saved_not_applied' => 'Your saved shipping country :saved is not available for shipping now, so it is not applied. Shipping country in use: :applied',
            // Lock reason shown on the "required" switch of the admin field table while international shipping is off
            'required_locked' => 'While international shipping is off, members are not asked for a shipping country, so the required setting is not applied.',
        ],
    ],

    // Name of the checkout field set (sirsoft-ecommerce.order_contact) and the fields this module declares in it
    'order_contact' => [
        'set_label' => 'Checkout Fields',
        'locked' => 'This field is essential for orders and delivery, so its enabled and required settings cannot be changed.',
        'orderer_name' => ['name' => 'Orderer Name'],
        'orderer_phone' => ['name' => 'Orderer Phone'],
        'orderer_email' => [
            'name' => 'Orderer Email',
            'description' => 'Always required for guest orders.',
        ],
        'recipient_name' => ['name' => 'Recipient Name'],
        'recipient_phone' => ['name' => 'Recipient Phone'],
        'address' => ['name' => 'Shipping Address'],
        'address_detail' => ['name' => 'Address Detail'],
        'shipping_memo' => ['name' => 'Delivery Memo'],
    ],
];
