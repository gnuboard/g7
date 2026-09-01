<?php

return [
    // VendorMode UI labels (VendorMode::label())
    'mode' => [
        'auto' => 'Auto (Recommended)',
        'composer' => 'Composer',
        'bundled' => 'Bundled vendor',
    ],

    // Installer / build UI messages
    'installer' => [
        'checking_bundle' => 'Verifying vendor bundle...',
        'extracting_bundle' => 'Extracting vendor bundle ({current}/{total})',
        'running_composer' => 'Running composer install...',
        'mode_label' => 'Vendor install mode',
        'mode_hint' => 'Use bundled mode when Composer is unavailable.',
    ],

    // Execution constraints (hosting profiles that block process functions)
    'composer_unavailable' => 'Composer cannot be executed on this server (process execution functions are disabled). Use the vendor bundle provided by the extension, or run the command from an environment with SSH access.',

    // Build / verify progress messages
    'build' => [
        'start' => 'Building {target}...',
        'success' => 'Built {target} ({size}, {packages} packages)',
        'skipped_no_deps' => '{target} skipped (no external composer dependencies)',
        'skipped_not_installed' => '{target} skipped (extension not installed)',
        'up_to_date' => '{target}: up-to-date',
        'stale' => '{target}: STALE — rebuild needed',
    ],
];
