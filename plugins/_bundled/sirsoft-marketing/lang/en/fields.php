<?php

/**
 * Names and help texts of the consent items declared as member fields (English)
 *
 * Used by `Fields\MarketingConsentFields` for the field declarations (`getFields()`) and the per-request decoration.
 * A channel item takes its name from the channel label in the plugin settings (`channels.*.label`).
 */
return [
    'marketing_consent' => [
        'name' => 'Marketing Consent',
        'description' => 'Agree to the collection and use of personal information for marketing purposes.',
    ],
    'third_party_consent' => [
        'name' => 'Third Party Sharing Consent',
        'description' => 'Agree to the sharing of personal information with third parties for marketing purposes.',
    ],
    'info_disclosure' => [
        'name' => 'Information Disclosure Consent',
        'description' => 'Allow your profile information to be visible to other users. Some community features may be limited if not agreed.',
    ],
    'terms_link' => [
        'label' => 'View',
        'title' => ':name Terms',
    ],
    'locks' => [
        'enabled' => 'Whether this item is used is decided in the Marketing Consent plugin settings.',
    ],
    'consented_on' => 'Agreed on :date',
];
