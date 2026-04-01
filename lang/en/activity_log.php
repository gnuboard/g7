<?php

return [
    // Activity log messages
    'fetch_success' => 'Activity log information retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve activity log information.',
    'delete_success' => 'Activity log has been deleted.',
    'bulk_delete_success' => 'Selected activity logs have been deleted.',
    'delete_failed' => 'Failed to delete activity log.',

    // Validation messages
    'validation' => [
        'ids_required' => 'Please select activity logs to delete.',
        'ids_min' => 'Please select at least one activity log to delete.',
        'id_not_found' => 'The selected activity log was not found.',
    ],

    // Log type labels
    'type' => [
        'admin' => 'Admin',
        'user' => 'User',
        'system' => 'System',
    ],

    // Action labels (last segment based)
    'action' => [
        'created' => 'Created',
        'create' => 'Created',
        'updated' => 'Updated',
        'update' => 'Updated',
        'deleted' => 'Deleted',
        'delete' => 'Deleted',
        'login' => 'Login',
        'logout' => 'Logout',
        'export' => 'Export',
        'import' => 'Import',
        'index' => 'List Viewed',
        'show' => 'Details Viewed',
        'search' => 'Search',
        'install' => 'Installed',
        'activate' => 'Activated',
        'deactivate' => 'Deactivated',
        'uninstall' => 'Uninstalled',
        'save' => 'Saved',
        'run' => 'Executed',
        'duplicate' => 'Duplicated',
        'upload' => 'Uploaded',
        'reorder' => 'Reordered',
        'toggle_status' => 'Status Toggled',
        'toggle_active' => 'Active Status Changed',
        'bulk_update' => 'Bulk Updated',
        'bulk_delete' => 'Bulk Deleted',
        'bulk_status' => 'Bulk Status Changed',
        'bulk_update_status' => 'Bulk Status Changed',
        'cancel' => 'Cancelled',
        'partial_cancel' => 'Partially Cancelled',
        'refund' => 'Refunded',
        'restore' => 'Restored',
        'blind' => 'Blinded',
        'publish' => 'Published',
        'register' => 'Registered',
        'reset_password' => 'Password Reset',
        'forgot_password' => 'Password Recovery',
        'record_consents' => 'Consent Recorded',
        'check' => 'Checked',
        'sync_permissions' => 'Permissions Synchronized',
        'sync_roles' => 'Roles Synchronized',
        'copy' => 'Copied',
        'reply' => 'Replied',
        'toggle' => 'Toggled',
        'download' => 'Downloaded',
        'earn' => 'Earned',
        'use' => 'Used',

        // Additional actions
        'withdraw' => 'Withdrawn',
        'update_order' => 'Order Changed',
        'refresh_layouts' => 'Layouts Refreshed',
        'version_update' => 'Version Updated',
        'version_restore' => 'Version Restored',
        'reset' => 'Reset',

        // Ecommerce/Board common
        'bulk_price_update' => 'Bulk Price Updated',
        'bulk_stock_update' => 'Bulk Stock Updated',
        'stock_sync' => 'Stock Synchronized',
        'bulk_status_update' => 'Bulk Status Updated',
        'bulk_shipping_update' => 'Bulk Shipping Updated',
        'update_shipping_address' => 'Shipping Address Updated',
        'send_email' => 'Email Sent',
        'payment_complete' => 'Payment Completed',
        'payment_failed' => 'Payment Failed',
        'status_change' => 'Status Changed',
        'bulk_status_change' => 'Bulk Status Changed',
        'confirm' => 'Confirmed',
        'set_default' => 'Set as Default',
        'bulk_toggle_active' => 'Bulk Active Status Changed',
        'bulk_create' => 'Bulk Created',
        'add' => 'Added',
        'update_quantity' => 'Quantity Updated',
        'change_option' => 'Option Changed',
        'delete_all' => 'All Deleted',
        'add_to_menu' => 'Added to Menu',
        'bulk_apply' => 'Bulk Applied',
        'update_status' => 'Status Updated',
        'restore_content' => 'Content Restored',
        'blind_content' => 'Content Blinded',
        'delete_content' => 'Content Deleted',
    ],

    // Description templates (used as description_key)
    'description' => [
        // User management
        'user_index' => 'User list viewed',
        'user_create' => 'User created (ID: :user_id)',
        'user_show' => 'User details viewed (ID: :user_id)',
        'user_update' => 'User updated (ID: :user_id)',
        'user_delete' => 'User deleted (ID: :user_id)',
        'user_withdraw' => 'User withdrawn (ID: :user_id)',
        'user_statistics' => 'User statistics viewed',
        'user_recent' => 'Recent users viewed',
        'user_search' => 'User search performed',
        'user_check_email' => 'Email duplication checked',
        'user_update_language' => 'User language setting changed',
        'user_bulk_update_status' => 'Users status bulk updated (:count items)',

        // Role management
        'role_index' => 'Role list viewed',
        'role_active' => 'Active role list viewed',
        'role_show' => 'Role details viewed (ID: :role_id)',
        'role_create' => 'Role created (ID: :role_id)',
        'role_update' => 'Role updated (ID: :role_id)',
        'role_delete' => 'Role deleted (ID: :role_id)',
        'role_toggle_status' => 'Role status toggled (ID: :role_id)',
        'role_sync_permissions' => 'Role permissions synchronized (ID: :role_id)',

        // Permission management
        'permission_index' => 'Permission list viewed',

        // Menu management
        'menu_index' => 'Menu list viewed',
        'menu_hierarchy' => 'Menu hierarchy viewed',
        'menu_active' => 'Active menu list viewed',
        'menu_show' => 'Menu details viewed (ID: :menu_id)',
        'menu_create' => 'Menu created (ID: :menu_id)',
        'menu_update' => 'Menu updated (ID: :menu_id)',
        'menu_delete' => 'Menu deleted (ID: :menu_id)',
        'menu_update_order' => 'Menu order changed',
        'menu_toggle_status' => 'Menu status toggled (ID: :menu_id)',
        'menu_sync_roles' => 'Menu roles synchronized (ID: :menu_id)',
        'menu_get_by_extension' => 'Menus by extension viewed (:extension_type: :extension_identifier)',

        // Settings management
        'settings_index' => 'Settings list viewed',
        'settings_save' => 'Settings saved',
        'settings_show' => 'Setting viewed',
        'settings_update' => 'Setting updated',
        'settings_system_info' => 'System information viewed',
        'settings_clear_cache' => 'Cache cleared',
        'settings_optimize_system' => 'System optimized',
        'settings_backup_database' => 'Database backed up',
        'settings_get_app_key' => 'App key viewed',
        'settings_app_key_regenerated' => 'App key regenerated',
        'settings_backup' => 'Backup created',
        'settings_restore' => 'Backup restored',
        'settings_test_mail' => 'Mail sending tested',
        'settings_test_driver_connection' => 'Driver connection tested',

        // Authentication
        'auth_login' => 'Admin login',
        'auth_logout' => 'Admin logout',
        'auth_register' => 'User registered',
        'auth_forgot_password' => 'Password reset requested',
        'auth_reset_password' => 'Password reset',
        'auth_record_consents' => 'Consent recorded',

        // Schedule management
        'schedule_index' => 'Schedule list viewed',
        'schedule_show' => 'Schedule details viewed (ID: :schedule_id)',
        'schedule_create' => 'Schedule created (ID: :schedule_id)',
        'schedule_update' => 'Schedule updated (ID: :schedule_id)',
        'schedule_delete' => 'Schedule deleted (ID: :schedule_id)',
        'schedule_run' => 'Schedule manually executed (ID: :schedule_id)',
        'schedule_duplicate' => 'Schedule duplicated',
        'schedule_bulk_update_status' => 'Schedule status bulk updated (:count items)',
        'schedule_bulk_delete' => 'Schedules bulk deleted (:count items)',
        'schedule_statistics' => 'Schedule statistics viewed',
        'schedule_history' => 'Schedule execution history viewed (ID: :schedule_id)',
        'schedule_delete_history' => 'Schedule history deleted',

        // Dashboard
        'dashboard_stats' => 'Dashboard statistics viewed',
        'dashboard_resources' => 'Dashboard resources viewed',
        'dashboard_activities' => 'Dashboard activities viewed',
        'dashboard_alerts' => 'Dashboard alerts viewed',

        // Attachment management
        'attachment_upload' => 'File uploaded',
        'attachment_upload_batch' => 'Files batch uploaded',
        'attachment_delete' => 'File deleted',
        'attachment_bulk_delete' => 'Files bulk deleted',
        'attachment_reorder' => 'File order changed',

        // Layout management
        'layout_index' => 'Layout list viewed',
        'layout_show' => 'Layout details viewed (:layout_path)',
        'layout_update' => 'Layout updated (:layout_path)',
        'layout_versions_index' => 'Layout versions viewed (:layout_path)',
        'layout_version_show' => 'Layout version viewed (:layout_path)',
        'layout_version_restore' => 'Layout version restored (:layout_path)',

        // Mail template management
        'mail_template_update' => 'Mail template updated (:template_name)',
        'mail_template_toggle_active' => 'Mail template status toggled (:template_name)',

        // Module management
        'module_index' => 'Module list viewed',
        'module_installed' => 'Installed module list viewed',
        'module_uninstalled' => 'Uninstalled module list viewed',
        'module_show' => 'Module details viewed (:module_name)',
        'module_install' => 'Module installed (:module_name)',
        'module_activate' => 'Module activated (:module_name)',
        'module_deactivate' => 'Module deactivated (:module_name)',
        'module_uninstall' => 'Module uninstalled (:module_name)',
        'module_uninstall_info' => 'Module uninstall info viewed (Module: :module_name)',
        'module_check_updates' => 'Module updates checked',
        'module_update' => 'Module updated (:module_name)',
        'module_refresh_layouts' => 'Module layouts refreshed (:module_name)',
        'module_dependent_templates' => 'Module dependent templates viewed',
        'module_install_from_file' => 'Module installed from file',
        'module_install_from_github' => 'Module installed from GitHub',

        // Module settings
        'module_settings_save' => 'Module settings saved (:module_name)',
        'module_settings_reset' => 'Module settings reset (:module_name)',

        // Plugin management
        'plugin_index' => 'Plugin list viewed',
        'plugin_installed' => 'Installed plugin list viewed',
        'plugin_show' => 'Plugin details viewed (:plugin_name)',
        'plugin_install' => 'Plugin installed (:plugin_name)',
        'plugin_activate' => 'Plugin activated (:plugin_name)',
        'plugin_deactivate' => 'Plugin deactivated (:plugin_name)',
        'plugin_uninstall' => 'Plugin uninstalled (:plugin_name)',
        'plugin_uninstall_info' => 'Plugin uninstall info viewed (Plugin: :plugin_name)',
        'plugin_check_updates' => 'Plugin updates checked',
        'plugin_update' => 'Plugin updated (:plugin_name)',
        'plugin_refresh_layouts' => 'Plugin layouts refreshed (:plugin_name)',
        'plugin_dependent_templates' => 'Plugin dependent templates viewed',

        // Plugin settings
        'plugin_settings_save' => 'Plugin settings saved (:plugin_name)',
        'plugin_settings_reset' => 'Plugin settings reset (:plugin_name)',

        // Template management
        'template_index' => 'Template list viewed',
        'template_show' => 'Template details viewed (:template_name)',
        'template_install' => 'Template installed (:template_name)',
        'template_activate' => 'Template activated (:template_name)',
        'template_deactivate' => 'Template deactivated (:template_name)',
        'template_uninstall' => 'Template uninstalled (:template_name)',
        'template_check_updates' => 'Template updates checked',
        'template_check_modified_layouts' => 'Template modified layouts checked (:template_name)',
        'template_update' => 'Template updated (:template_name)',
        'template_version_update' => 'Template version updated (:template_name)',
        'template_install_from_file' => 'Template installed from file',
        'template_install_from_github' => 'Template installed from GitHub',
        'template_refresh_layouts' => 'Template layouts refreshed (:template_name)',

        // Core update
        'core_update_check' => 'Core update checked',
        'core_update_update' => 'Core update executed',

        // Activity log
        'activity_log_index' => 'Activity log list viewed',
        'activity_log_delete' => 'Activity log deleted (ID: :log_id)',
        'activity_log_bulk_delete' => 'Activity logs bulk deleted (:count entries)',
    ],

    // ChangeDetector field labels
    'fields' => [
        // User
        'name' => 'Name',
        'nickname' => 'Nickname',
        'email' => 'Email',
        'language' => 'Language',
        'timezone' => 'Timezone',
        'country' => 'Country',
        'status' => 'Status',
        'is_super' => 'Super Admin',
        'homepage' => 'Homepage',
        'mobile' => 'Mobile',
        'phone' => 'Phone',
        'zipcode' => 'Zip Code',
        'address' => 'Address',
        'address_detail' => 'Address Detail',
        'bio' => 'Bio',
        'admin_memo' => 'Admin Memo',

        // Role
        'identifier' => 'Identifier',
        'is_active' => 'Active',

        // Menu
        'url' => 'URL',
        'icon' => 'Icon',
        'parent_id' => 'Parent',
        'order' => 'Order',

        // Schedule
        'command' => 'Command',
        'expression' => 'Expression',
        'without_overlapping' => 'Without Overlapping',
        'run_in_maintenance' => 'Run in Maintenance',
        'timeout' => 'Timeout',

        // MailTemplate
        'subject' => 'Subject',
        'body' => 'Body',
        'is_default' => 'Default',
    ],
];
