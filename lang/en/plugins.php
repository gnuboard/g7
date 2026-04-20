<?php

return [
    // Plugin related messages
    'list_success' => 'Plugin list retrieved successfully.',
    'list_failed' => 'Failed to retrieve plugin list.',
    'fetch_success' => 'Plugin information retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve plugin information.',
    'not_found' => 'Plugin :plugin not found.',
    'install_success' => 'Plugin installed successfully.',
    'install_failed' => 'Failed to install plugin.',
    'install_validation_failed' => 'Plugin installation validation failed.',
    'install_error' => 'An error occurred while installing plugin.',
    'uninstall_success' => 'Plugin uninstalled successfully.',
    'uninstall_failed' => 'Failed to uninstall plugin.',
    'uninstall_validation_failed' => 'Plugin uninstallation validation failed.',
    'uninstall_error' => 'An error occurred while uninstalling plugin.',
    'activate_success' => 'Plugin activated successfully.',
    'activate_failed' => 'Failed to activate plugin.',
    'activate_validation_failed' => 'Plugin activation validation failed.',
    'activate_error' => 'An error occurred while activating plugin.',
    'deactivate_success' => 'Plugin deactivated successfully.',
    'deactivate_failed' => 'Failed to deactivate plugin.',
    'deactivate_validation_failed' => 'Plugin deactivation validation failed.',
    'deactivate_error' => 'An error occurred while deactivating plugin.',
    'refresh_layouts_success' => 'Plugin layouts refreshed successfully.',
    'refresh_layouts_failed' => 'Failed to refresh plugin layouts: :error',
    'uninstall_info_success' => 'Plugin uninstall information retrieved successfully.',
    'uninstall_info_failed' => 'Failed to retrieve plugin uninstall information.',
    'license_not_found' => 'Plugin license file not found.',
    'refresh_layouts_validation_failed' => 'Plugin layouts refresh validation failed.',
    'refresh_layouts_error' => 'An error occurred while refreshing plugin layouts.',

    // Status
    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'updating' => 'Updating',
    ],

    // Update related messages
    'update_success' => 'Plugin ":plugin" has been updated to version :version.',
    'update_failed' => 'Plugin update failed: :error',
    'update_hook_failed' => 'Plugin update hook execution failed.',
    'no_update_available' => 'No update available.',
    'check_updates_success' => 'Update check completed.',
    'check_updates_failed' => 'Update check failed.',
    'check_modified_layouts_success' => 'Modified layouts check completed.',
    'check_modified_layouts_failed' => 'Modified layouts check failed: :error',
    'not_installed' => 'Plugin ":plugin" is not installed.',

    // _pending related messages
    'pending_not_found' => 'Plugin ":plugin" not found in pending list.',
    'already_exists' => 'Plugin ":plugin" already exists.',
    'move_failed' => 'Failed to move plugin ":plugin".',

    // Dependency messages
    'dependency_not_active' => 'Dependency ":dependency" is not installed or inactive.',

    // Plugin service error messages
    'installation_failed' => 'Plugin installation failed: :error',
    'activation_failed' => 'Plugin activation failed: :error',
    'deactivation_failed' => 'Plugin deactivation failed: :error',
    'uninstallation_failed' => 'Plugin uninstallation failed: :error',

    // Warning messages
    'warnings' => [
        'has_dependent_templates' => 'There are active templates that depend on this plugin. Use force option to deactivate.',
        'has_dependents' => 'There are active extensions that depend on this plugin.',
        'deactivation_warning' => 'Deactivating will affect the following templates:',
        'confirm_deactivation' => 'Do you still want to deactivate?',
        'missing_dependencies' => 'The following dependencies are required to activate this plugin.',
    ],

    // Activation warning message
    'activate_warning' => 'Required dependencies are not met for plugin activation.',

    // Deactivation warning message
    'deactivate_warning' => 'There are active extensions that depend on this plugin.',

    // Deactivation warning message (extended)
    'deactivate_warning_extended' => 'There are active extensions (templates, modules, plugins) that depend on this plugin.',

    // Error messages
    'errors' => [
        'force_update_no_source' => 'Cannot force update :plugin. Neither bundle nor GitHub URL is available.',
        'invalid_permission_structure' => 'Invalid permission structure for plugin ":identifier": :reason',
        'invalid_translation_path' => 'Invalid translation path for plugin ":identifier". Use ":correct_path" instead of ":wrong_path".',
        'seo_variable_conflict' => 'SEO variable name conflict in plugin ":identifier": :reason',
        'plugin_not_found' => 'Plugin ":name" not found.',
        'plugin_not_active' => 'Plugin ":name" is not active.',
        // Asset serving error messages
        'not_found' => 'Plugin :plugin not found.',
        'file_not_found' => 'File not found.',
        'file_type_not_allowed' => 'File type not allowed.',
        'unknown_error' => 'An unknown error occurred.',
        'layout_validation_json_error' => 'Failed to parse JSON in layout file :file: :error',
        'layout_validation_missing_name' => 'layout_name is missing in layout file :file.',
        'layout_validation_partial_error' => 'Failed to process partial in layout file :file: :error (partial: :partial)',
        'layout_validation_validation_failed' => 'Layout validation failed for plugin :identifier. :count error(s) found.',
        'operation_in_progress' => 'Plugin ":name" is currently :status. Please wait until the operation is complete.',
        'github_api_failed' => 'GitHub API call failed.',
        'invalid_github_url' => 'Invalid GitHub URL.',
        'zip_url_not_found' => 'ZIP download URL not found.',
        'download_failed' => 'Failed to download plugin ":plugin" version :version.',
        'zip_extract_failed' => 'ZIP extraction failed.',
        'extracted_dir_not_found' => 'Extracted directory not found.',
        'reload_failed' => 'Failed to reload plugin.',
        'delete_directory_failed' => 'Failed to delete plugin directory.',
        'update_failed' => 'Failed to update plugin ":plugin": :error',
        'invalid_layout_strategy' => 'Invalid layout strategy. (only overwrite or keep allowed)',
        'invalid_vendor_mode' => 'Invalid vendor installation mode. (only auto, composer, bundled allowed)',
        'github_url_invalid' => 'Invalid GitHub URL format.',
        'github_download_failed' => 'Failed to download from GitHub.',
        'github_repo_not_found' => 'GitHub repository not found.',
        'zip_open_failed' => 'Unable to open ZIP file.',
        'plugin_json_not_found' => 'plugin.json file not found.',
        'plugin_json_invalid' => 'Invalid plugin.json file format.',
        'identifier_missing' => 'Plugin identifier is missing.',
        'already_installed' => 'Plugin ":plugin" is already installed.',
        'install_failed' => 'Plugin installation failed.',
    ],

    // Validation messages (FormRequest)
    'validation' => [
        'github_url_required' => 'GitHub URL is required.',
        'github_url_invalid' => 'Invalid URL format.',
        'github_url_format' => 'Must be a GitHub repository URL. (e.g., https://github.com/owner/repo)',
        'file_required' => 'Please select a file to install.',
        'file_invalid' => 'Invalid file.',
        'file_must_be_zip' => 'Only ZIP files can be uploaded.',
        'file_max_size' => 'File size must not exceed :sizeMB.',
    ],

    // Artisan command messages
    'commands' => [
        'list' => [
            'headers' => [
                'identifier' => 'Identifier',
                'name' => 'Name',
                'vendor' => 'Vendor',
                'version' => 'Version',
                'status' => 'Status',
            ],
            'status' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'uninstalled' => 'Not Installed',
            ],
            'no_plugins' => 'No plugins found.',
            'summary' => 'Total :total plugins (Installed: :installed, Active: :active)',
            'invalid_status' => 'Invalid status. (installed, uninstalled, active, inactive)',
        ],
        'install' => [
            'success' => 'Plugin ":plugin" installed successfully',
            'vendor' => 'Vendor: :vendor',
            'version' => 'Version: :version',
            'roles_created' => ':count roles created',
            'permissions_created' => ':count permissions created',
            'already_installed' => 'Plugin ":plugin" is already installed.',
            'force_reinstall' => 'Force reinstalling plugin ":plugin" (overwriting active directory)...',
        ],
        'activate' => [
            'success' => 'Plugin ":plugin" activated successfully',
            'not_installed' => 'Plugin ":plugin" is not installed.',
            'already_active' => 'Plugin ":plugin" is already active.',
            'layouts_registered' => ':count layouts registered',
        ],
        'deactivate' => [
            'success' => 'Plugin ":plugin" deactivated successfully',
            'not_installed' => 'Plugin ":plugin" is not installed.',
            'not_active' => 'Plugin ":plugin" is not active.',
            'warning' => 'Plugin routes and features have been deactivated.',
            'layouts_deleted' => ':count layouts deleted',
        ],
        'uninstall' => [
            'success' => 'Plugin ":plugin" uninstalled successfully',
            'roles_deleted' => ':count roles deleted',
            'permissions_deleted' => ':count permissions deleted',
            'layouts_deleted' => ':count layouts deleted',
            'confirm_prompt' => 'Are you sure you want to uninstall plugin ":plugin"?',
            'confirm_details' => [
                'roles' => '- :count roles will be deleted.',
                'permissions' => '- :count permissions will be deleted.',
                'layouts' => '- :count layouts will be deleted.',
                'data' => '- All plugin data will be deleted.',
            ],
            'confirm_question' => 'Do you really want to delete?',
            'aborted' => 'Plugin uninstallation cancelled.',
            'not_installed' => 'Plugin ":plugin" is not installed.',
        ],
        'cache_clear' => [
            'clearing_all' => 'Clearing all plugin caches...',
            'clearing_single' => 'Clearing plugin ":plugin" cache...',
            'success_all' => 'Plugin cache cleared (:count items)',
            'success_single' => 'Plugin ":plugin" cache cleared (:count items)',
        ],
        'check_updates' => [
            'not_installed' => 'Plugin ":plugin" is not installed.',
            'no_installed' => 'No installed plugins found.',
            'update_available' => 'Update available',
            'up_to_date' => 'Up to date',
            'headers' => [
                'identifier' => 'Identifier',
                'current_version' => 'Current Version',
                'latest_version' => 'Latest Version',
                'source' => 'Source',
                'status' => 'Status',
            ],
            'summary' => 'Total :total checked (Updates available: :updates)',
            'single_up_to_date' => 'Plugin ":plugin" is up to date. (v:version)',
            'single_update_available' => 'Plugin ":plugin" update available: v:current → v:latest (:source)',
        ],
        'update' => [
            'success' => 'Plugin ":plugin" updated successfully',
            'version_change' => 'v:from → v:to',
            'no_update' => 'No update available for plugin ":plugin".',
            'not_installed' => 'Plugin ":plugin" is not installed.',
            'current_version' => 'Current version: :version',
            'latest_version' => 'Latest version: :version',
            'update_source' => 'Update source: :source',
            'confirm_question' => 'Do you want to proceed with the update?',
            'aborted' => 'Update cancelled.',
            'backup_restored' => 'Previous version has been restored from backup.',
            'force_mode' => 'Force update mode: Skipping version comparison and reinstalling.',
        ],
    ],

    // Composer dependency installation messages
    'composer_install' => [
        'start' => 'Installing Composer dependencies for plugin ":plugin"...',
        'success' => 'Composer dependencies installed for plugin ":plugin"',
        'no_dependencies' => 'Plugin ":plugin" has no Composer dependencies to install.',
        'failed' => 'Failed to install Composer dependencies for plugin ":plugin".',
        'summary' => '📊 Result: :success succeeded, :skip skipped, :fail failed',
    ],

    // Plugin settings related messages
    'settings' => [
        'update_failed' => 'Failed to update plugin settings.',
        'updated' => 'Plugin settings updated successfully.',
    ],

    // Validation messages
    'validation' => [
        'plugin_name_required' => 'Plugin name is required.',
        'plugin_name_string' => 'Plugin name must be a string.',
        'plugin_name_max' => 'Plugin name must not exceed :max characters.',
        'name_required' => 'Plugin name is required.',
        'name_string' => 'Plugin name must be a string.',
        'name_max' => 'Plugin name must not exceed 255 characters.',
        'search_max' => 'Search term must not exceed 255 characters.',
        'filters_max' => 'You can set up to 10 search filters.',
        'filter_field_required' => 'Search field is required.',
        'filter_field_invalid' => 'Invalid search field.',
        'filter_value_required' => 'Search value is required.',
        'filter_value_max' => 'Search value must not exceed 255 characters.',
        'filter_operator_invalid' => 'Invalid search operator.',
        'status_invalid' => 'Invalid status value.',
        'per_page_min' => 'Items per page must be at least 1.',
        'per_page_max' => 'Items per page must not exceed 100.',
        'page_min' => 'Page number must be at least 1.',
    ],
];
