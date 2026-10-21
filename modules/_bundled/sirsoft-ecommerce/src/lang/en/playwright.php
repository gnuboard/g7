<?php

/**
 * Playwright E2E seed command translations (developer-only — no user exposure).
 *
 * This file exists for i18n compliance of the PlaywrightSeedEcommerce stub.
 * It can be removed when the stub body is replaced with the actual seed logic
 * during the ecommerce module work session.
 */
return [
    'seed_stub_unimplemented' => 'PlaywrightSeedEcommerce is a stub. Implement the actual seed logic in the ecommerce module work session.',
    // Playwright seed command console output
    'guard' => [
        'cli_only' => 'This command can only be run from the CLI.',
        'bypass_required' => 'The G7_PLAYWRIGHT_BYPASS=1 environment variable is required.',
    ],
    'seed_claim_detail' => [
        'invalid_tag' => '--tag must be 1-16 alphanumeric characters.',
        'tag_required' => '--tag is required for seeding (so parallel workers do not delete each other\'s claims).',
    ],
    'seed_site_day_orders' => [
        'no_product_options' => 'No product options exist, so order options cannot be attached.',
    ],
];
