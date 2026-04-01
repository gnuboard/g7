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
