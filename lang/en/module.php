<?php

return [
    // Module management messages
    'not_found' => 'Module :module not found.',
    'dependency_not_active' => 'Dependency module :dependency is not installed or active.',
    
    // Module operation messages
    'fetch_success' => 'Module retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve module: :error',
    'install_success' => 'Module installed successfully.',
    'install_failed' => 'Failed to install module: :error',
    'preview_success' => 'Manifest preview completed.',
    'preview_failed' => 'Failed to preview manifest: :error',
    'uninstall_success' => 'Module uninstalled successfully.',
    'uninstall_failed' => 'Failed to uninstall module: :error',
    'activate_success' => 'Module activated successfully.',
    'activate_failed' => 'Failed to activate module: :error',
    'deactivate_success' => 'Module deactivated successfully.',
    'deactivate_failed' => 'Failed to deactivate module: :error',
    'update_success' => 'Module updated successfully.',
    'update_failed' => 'Failed to update module: :error',
    'refresh_layouts_success' => 'Module layouts refreshed successfully.',
    'refresh_layouts_failed' => 'Failed to refresh module layouts: :error',
    'uninstall_info_success' => 'Module uninstall information retrieved successfully.',
    'uninstall_info_failed' => 'Failed to retrieve module uninstall information: :error',
    'dependent_templates_success' => 'Dependent templates retrieved successfully.',
    'dependent_templates_failed' => 'Failed to retrieve dependent templates: :error',
    'license_not_found' => 'Module license file not found.',

    // Status
    'status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'installing' => 'Installing',
        'uninstalling' => 'Uninstalling'
    ],
    
    // Error messages
    'errors' => [
        'module_class_not_found' => 'Module class not found.',
        'migration_failed' => 'Failed to run migrations: :error',
        'dependency_check_failed' => 'Dependency check failed: :error',
        'database_error' => 'Database error occurred.',
        'module_not_found' => 'Module :name not found.',
        'module_not_active' => 'Module :name is not active.',
    ]
];
