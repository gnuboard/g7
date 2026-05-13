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
        'not_found' => 'Extension (:identifier) not found.',
        'cascade_dependency_failed' => 'Failed to install cascade dependency :type (:identifier): :message',
        'invalid_type' => 'Invalid extension type.',
        'not_auto_deactivated' => 'This extension was not automatically deactivated due to core version incompatibility.',
        'hidden_extension' => 'Hidden (internal-only) extensions are not exposed to user actions.',
    ],

    'warnings' => [
        'auto_deactivated' => ':type ":identifier" has been automatically deactivated due to core version incompatibility.',
    ],

    'alerts' => [
        'incompatible_deactivated' => ':type ":name" auto-deactivated',
        'incompatible_message' => 'Required: :required, Installed: :installed',
        'recovered_title' => ':type ":name" is compatible again',
        'recovered_body' => 'Now compatible after core upgrade (previously required: :previously_required). You can re-activate.',
        'recovered_success' => 'Extension has been re-activated.',
        'dismissed' => 'Alert dismissed.',
        'auto_deactivated_listed' => 'Auto-deactivated extensions listed.',
        'recover_action' => 'Re-activate',
        'dismiss_action' => 'Dismiss alert',
    ],

    'badges' => [
        'incompatible' => 'Core upgrade required',
        'incompatible_tooltip' => 'Requires core :required or higher (current: :installed)',
        'incompatible_sr' => ':name requires core :required or higher but :installed is installed; update is not allowed.',
    ],

    'banner' => [
        'title' => 'Some extensions were auto-deactivated due to core compatibility',
        'item_required' => 'Required: :required',
        'guide_link' => 'Core upgrade guide',
        'dismiss' => 'Dismiss',
    ],

    'update_modal' => [
        'compat_warning_title' => 'Core version compatibility warning',
        'compat_warning_message' => 'This :type requires core :required or higher. (current: :installed)',
        'compat_guide_link' => 'View core upgrade guide',
        'force_label' => 'Override warning and force update (not recommended)',
    ],

    'commands' => [
        'clear_cache_success' => 'Extension version check cache has been cleared.',
    ],
];
