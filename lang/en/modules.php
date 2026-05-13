<?php

return [
    // Common messages
    'not_found' => 'Module ":module" not found.',
    'dependency_not_active' => 'Dependency module ":dependency" is not installed or inactive.',

    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'updating' => 'Updating',
    ],

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
            'no_modules' => 'No modules found.',
            'summary' => 'Total :total modules (Installed: :installed, Active: :active)',
            'invalid_status' => 'Invalid status. (installed, uninstalled, active, inactive)',
        ],
        'install' => [
            'success' => 'Module ":module" installed successfully',
            'vendor' => 'Vendor: :vendor',
            'version' => 'Version: :version',
            'roles_created' => ':count roles created',
            'permissions_created' => ':count permissions created',
            'menus_created' => ':count menus created',
            'already_installed' => 'Module ":module" is already installed.',
            'force_reinstall' => 'Force reinstalling module ":module" (overwriting active directory)...',
        ],
        'activate' => [
            'success' => 'Module ":module" activated successfully',
            'not_installed' => 'Module ":module" is not installed.',
            'already_active' => 'Module ":module" is already active.',
            'layouts_registered' => ':count layouts registered',
        ],
        'deactivate' => [
            'success' => 'Module ":module" deactivated successfully',
            'not_installed' => 'Module ":module" is not installed.',
            'not_active' => 'Module ":module" is not active.',
            'warning' => 'Module routes and features have been deactivated.',
            'layouts_deleted' => ':count layouts deleted',
        ],
        'uninstall' => [
            'success' => 'Module ":module" uninstalled successfully',
            'roles_deleted' => ':count roles deleted',
            'permissions_deleted' => ':count permissions deleted',
            'menus_deleted' => ':count menus deleted',
            'layouts_deleted' => ':count layouts deleted',
            'confirm_prompt' => 'Are you sure you want to uninstall module ":module"?',
            'confirm_details' => [
                'roles' => '- :count roles will be deleted.',
                'permissions' => '- :count permissions will be deleted.',
                'menus' => '- :count menus will be deleted.',
                'layouts' => '- :count layouts will be deleted.',
                'data' => '- All module data will be deleted.',
            ],
            'confirm_question' => 'Do you really want to delete?',
            'aborted' => 'Module uninstallation cancelled.',
            'not_installed' => 'Module ":module" is not installed.',
        ],
        'cache_clear' => [
            'clearing_all' => 'Clearing all module caches...',
            'clearing_single' => 'Clearing module ":module" cache...',
            'success_all' => 'Module cache cleared (:count items)',
            'success_single' => 'Module ":module" cache cleared (:count items)',
        ],
        'check_updates' => [
            'not_installed' => 'Module ":module" is not installed.',
            'no_installed' => 'No installed modules found.',
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
            'single_up_to_date' => 'Module ":module" is up to date. (v:version)',
            'single_update_available' => 'Module ":module" update available: v:current → v:latest (:source)',
        ],
        'update' => [
            'success' => 'Module ":module" updated successfully',
            'version_change' => 'v:from → v:to',
            'no_update' => 'No update available for module ":module".',
            'not_installed' => 'Module ":module" is not installed.',
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
        'start' => 'Installing Composer dependencies for module ":module"...',
        'success' => 'Composer dependencies installed for module ":module"',
        'no_dependencies' => 'Module ":module" has no Composer dependencies to install.',
        'failed' => 'Failed to install Composer dependencies for module ":module".',
        'summary' => '📊 Result: :success succeeded, :skip skipped, :fail failed',
    ],

    // Update related messages
    'update_success' => 'Module ":module" has been updated to version :version.',
    'update_failed' => 'Module update failed: :error',
    'update_hook_failed' => 'Module update hook execution failed: :error',
    'no_update_available' => 'No update available.',
    'check_updates_success' => 'Update check completed.',
    'check_updates_failed' => 'Update check failed: :error',
    'check_modified_layouts_success' => 'Modified layouts check completed.',
    'check_modified_layouts_failed' => 'Modified layouts check failed: :error',
    'not_installed' => 'Module ":module" is not installed.',

    // _pending related messages
    'pending_not_found' => 'Module ":module" not found in pending list.',
    'already_exists' => 'Module ":module" already exists.',
    'move_failed' => 'Failed to move module ":module": :error',

    // Module service error messages
    'deactivate_warning' => 'There are active templates that depend on this module.',
    'installation_failed' => 'Module installation failed: :error',
    'activation_failed' => 'Module activation failed: :error',
    'deactivation_failed' => 'Module deactivation failed: :error',
    'uninstallation_failed' => 'Module uninstallation failed: :error',
    'refresh_layouts_failed' => 'Failed to refresh layouts: :error',

    // Warning messages
    'warnings' => [
        'has_dependent_templates' => 'There are active templates that depend on this module. Use force option to deactivate.',
        'has_dependents' => 'There are active extensions that depend on this module.',
        'deactivation_warning' => 'Deactivating will affect the following templates:',
        'confirm_deactivation' => 'Do you still want to deactivate?',
        'missing_dependencies' => 'The following dependencies are required to activate this module.',
    ],

    // Activation warning message
    'activate_warning' => 'Required dependencies are not met for module activation.',

    // Deactivation warning message (extended)
    'deactivate_warning_extended' => 'There are active extensions (templates, modules, plugins) that depend on this module.',

    // Error messages
    'errors' => [
        'force_update_no_source' => 'Cannot force update :module. Neither bundle nor GitHub URL is available.',
        'downgrade_blocked' => 'Downgrade blocked (:from → :to). Use --force to allow intentional downgrades.',
        'zip_open_failed' => 'Failed to open ZIP file: :error',
        'module_json_not_found' => 'module.json file not found.',
        'module_json_invalid' => 'Invalid module.json file format.',
        'identifier_missing' => 'Module identifier is missing.',
        'already_installed' => 'Module is already installed.',
        'install_failed' => 'Failed to install module: :error',
        'github_url_invalid' => 'Invalid GitHub URL format.',
        'github_download_failed' => 'Failed to download from GitHub: :error',
        'github_repo_not_found' => 'GitHub repository not found.',
        'module_not_found' => 'Module :name not found.',
        'module_not_active' => 'Module :name is not active.',
        // Asset serving error messages
        'not_found' => 'Module :module not found.',
        'file_not_found' => 'File not found.',
        'file_type_not_allowed' => 'File type not allowed.',
        'unknown_error' => 'An unknown error occurred.',
        'invalid_permission_structure' => 'Invalid permission structure for module ":identifier": :reason',
        'invalid_translation_path' => 'Invalid translation path for module ":identifier". Use ":correct_path" instead of ":wrong_path".',
        'seo_variable_conflict' => 'SEO variable name conflict in module ":identifier": :reason',
        'layout_validation_json_error' => 'Failed to parse JSON in layout file :file: :error',
        'layout_validation_missing_name' => 'Layout file :file is missing layout_name.',
        'layout_validation_partial_error' => 'Failed to process partial in layout file :file: :error (partial: :partial)',
        'layout_validation_validation_failed' => 'Layout validation failed for module :identifier. :count errors found: :error',
        'operation_in_progress' => 'Module ":name" is currently :status. Please wait until the operation is complete.',
        'github_api_failed' => 'GitHub API call failed: :error',
        'invalid_github_url' => 'Invalid GitHub URL.',
        'zip_url_not_found' => 'ZIP download URL not found.',
        'download_failed' => 'Failed to download module ":module" version :version: :error',
        'zip_extract_failed' => 'ZIP extraction failed: :error',
        'extracted_dir_not_found' => 'Extracted directory not found.',
        'reload_failed' => 'Failed to reload module: :error',
        'delete_directory_failed' => 'Failed to delete module directory: :error',
        'update_failed' => 'Failed to update module ":module": :error',
        'invalid_layout_strategy' => 'Invalid layout strategy. (only overwrite or keep allowed)',
        'invalid_vendor_mode' => 'Invalid vendor installation mode. (only auto, composer, bundled allowed)',
    ],

    // Validation messages
    'validation' => [
        // Basic module fields
        'name_required' => 'Module name is required.',
        'name_string' => 'Module name must be a string.',
        'name_max' => 'Module name cannot exceed 255 characters.',

        // Search filters
        'search_max' => 'Search term cannot exceed 255 characters.',
        'filters_max' => 'Maximum 10 search filters are allowed.',
        'filter_field_required' => 'Search field is required.',
        'filter_field_invalid' => 'Invalid search field.',
        'filter_value_required' => 'Search value is required.',
        'filter_value_max' => 'Search value cannot exceed 255 characters.',
        'filter_operator_invalid' => 'Invalid search operator.',
        'status_invalid' => 'Invalid status.',
        'per_page_min' => 'Items per page must be at least 1.',
        'per_page_max' => 'Items per page cannot exceed 100.',
        'page_min' => 'Page number must be at least 1.',

        // File upload
        'file_required' => 'Module file is required.',
        'file_invalid' => 'Invalid file.',
        'file_must_be_zip' => 'Module file must be in ZIP format.',
        'file_max_size' => 'Module file size cannot exceed :sizeMB.',

        // GitHub
        'github_url_required' => 'GitHub URL is required.',
        'github_url_invalid' => 'Invalid URL format.',
        'github_url_format' => 'Invalid GitHub repository URL format.',

        // with parameter
        'with_max' => 'Maximum 10 with relationships are allowed.',
        'with_invalid' => 'Invalid with relationship.',
    ],
];
