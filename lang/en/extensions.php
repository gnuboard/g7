<?php

return [
    'types' => [
        'module' => 'Module',
        'plugin' => 'Plugin',
        'template' => 'Template',
    ],

    'errors' => [
        'core_version_mismatch' => ':extension (:type) requires Gnuboard7 core version :required or higher. (Current: :installed)',
        'version_check_failed' => 'Version check failed.',
        'operation_in_progress' => 'Cannot process the request because ":name" has an operation in progress (:status).',
        'zip_missing_manifest' => 'Manifest :file not found inside ZIP: :zip',
        'zip_invalid_manifest' => 'Manifest :file inside ZIP is not valid JSON.',
        'zip_identifier_mismatch' => 'ZIP manifest identifier does not match target extension. (expected: :expected, actual: :actual)',
        'zip_missing_version' => 'Manifest :file inside ZIP has no version field.',
    ],

    'warnings' => [
        'auto_deactivated' => ':type ":identifier" has been automatically deactivated due to core version incompatibility.',
    ],

    'alerts' => [
        'incompatible_deactivated' => ':type ":name" auto-deactivated',
        'incompatible_message' => 'Required: :required, Installed: :installed',
    ],

    'commands' => [
        'clear_cache_success' => 'Extension version check cache has been cleared.',
    ],
];
