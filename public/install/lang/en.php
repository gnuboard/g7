<?php
// /install/lang/en.php

return [
    // Common
    'next' => 'Next',
    'previous' => 'Previous',
    'install' => 'Install',
    'cancel' => 'Cancel',
    'yes' => 'Yes',
    'no' => 'No',
    'loading' => 'Loading...',
    'saving' => 'Saving...',
    'save_failed' => 'Save failed',
    'error' => 'Error',

    // Step 0: Welcome
    'welcome_title' => 'Getting Started with Gnuboard7',
    'welcome_desc' => 'Welcome to Gnuboard7. This is the first step of the installation.<br>Follow the guided steps in order to complete the required environment setup quickly and reliably.',
    'select_language' => 'Select Language',
    'storage_permission_check' => 'Directory Permission Check',
    'storage_permission_required' => 'Storage Folder Permission Required',
    'storage_permission_failed' => 'Insufficient storage folder permissions',
    'storage_not_exists' => 'Storage Folder Not Found',
    'storage_not_exists_message' => 'The storage folder does not exist. Please create a storage folder in the project root.',
    'storage_not_writable' => 'Storage Folder Not Writable',
    'storage_not_writable_message' => 'The storage folder is not writable. Please check the folder owner or group permissions.',
    'storage_ownership_mismatch' => 'Storage Folder Ownership Mismatch',
    'storage_ownership_mismatch_message' => 'The storage folder owner (:owner) does not match the web server user (:web_user). Current permissions (:permissions) allow only the owner to write, so the web server cannot write. Please apply one of the options below.',
    'storage_permission_guide' => 'Please grant <strong>755</strong> permission to the Storage folder.',
    'storage_permission_guide_detail' => 'Run the command below or change permissions via FTP:',
    'storage_permission_command_linux' => 'chmod -R 755 :path',
    'current_owner' => 'Owner',
    'current_group' => 'Group',
    'current_permissions' => 'Permissions',
    'required_permissions' => 'Required',
    'unknown' => 'Unknown',
    'recheck_permission' => 'Recheck',
    'permission_checking' => 'Checking permissions...',

    // Step 1: License
    'license_title' => 'License Agreement',
    'license_agreement' => 'License Terms',
    'i_agree' => 'I agree to the above license terms',
    'must_agree' => 'You must agree to the license to continue.',
    'license_not_found' => 'License information not found.',

    // Step 2: Requirements
    'checking_requirements' => 'Checking server requirements...',
    'php_version' => 'PHP Version',
    'php_modules' => 'PHP Modules',
    'directory_permissions' => 'Directory Permissions',
    'disk_space' => 'Disk Space',
    'https' => 'HTTPS',
    'required' => 'Required',
    'enabled' => 'Enabled',
    'not_enabled' => 'Not Enabled',
    'recommended' => 'Recommended',
    'needs_fix' => 'Needs Fix',
    'requirements_check_failed' => 'Requirements check failed',
    'all_requirements_met' => 'All requirements are met.',
    'some_requirements_failed' => 'Some requirements are not met. Please check your server configuration.',
    'recheck' => 'Recheck',
    'requirements_not_met' => 'Required conditions are not met. Please check your environment and try again.',
    'badge_required' => 'Required',
    'badge_optional' => 'Optional',
    'or_above' => 'or above',
    'fix_permission' => 'Insufficient write permission',
    'including_subdirectories' => 'including subdirectories',
    'and_more' => 'and :count more',
    'error_php_version' => 'PHP Version',
    'error_missing_extensions' => 'Missing required PHP extensions',
    'error_disk_space' => 'Disk Space',
    'error_directory_permissions' => 'Insufficient directory write permissions',
    'error_required_files' => 'Required files missing',
    'error_required_files_missing_label' => 'Required files missing',
    'error_required_files_not_writable_label' => 'Required files not writable',
    'error_required_files_ownership_mismatch_label' => 'Required files ownership mismatch',
    'ownership_mismatch_option_666' => '[Alternative 2] Last resort — grant write permission to the file for everyone (reduced security, not recommended):',
    'required_files' => 'Required Files',
    'file_exists' => 'Exists',
    'file_missing' => 'Missing',
    'file_not_writable' => 'Not Writable',
    'required_files_fix_guide' => 'Run the following command in your project root:',
    'minimum' => 'minimum',
    'required_text' => 'required',

    // Step 3: Configuration
    'database_settings' => 'Database Settings',
    'database_type' => 'Database Type',
    'db_host' => 'Database Host',
    'db_port' => 'Port',
    'db_name' => 'Database Name',
    'db_username' => 'Username',
    'db_password' => 'Password',
    'db_prefix' => 'Table Prefix',
    'db_prefix_hint' => 'Prefix added to all table names. (e.g., g7_users, g7_posts)',
    'db_prefix_placeholder' => 'g7_',
    'test_write_db_connection' => 'Test Write DB Connection',
    'test_read_db_connection' => 'Test Read DB Connection',
    'use_read_db' => 'Use Read DB Settings',
    'database_settings_read' => 'Database Settings (Read DB) - Optional',

    'site_settings' => 'Site Settings',
    'app_name' => 'Site Name',
    'app_url' => 'Site URL',

    'admin_account' => 'Admin Account',
    'admin_name' => 'Admin Name',
    'admin_language' => 'Admin Language',
    'admin_email' => 'Admin Email',
    'admin_password' => 'Admin Password',
    'admin_password_confirm' => 'Confirm Password',

    'start_installation' => 'Start Installation',

    // Step 5: Installation
    'installation_title' => 'Installation in Progress',
    'installation_in_progress' => 'Installing Gnuboard7. Please wait...',
    'installation_in_progress_message' => 'Installation is in progress. Redirecting to installation page.',
    'overall_progress' => 'Overall Progress',
    'preparing' => 'Preparing...',
    'waiting_installation' => 'Waiting for installation to start...',
    'processing' => 'Processing...',
    'installation_complete' => 'Installation Complete',
    'installation_failed' => 'Installation Failed',
    'retry_installation' => 'Retry',
    'do_not_close_page' => 'Do not close this page until the installation is complete.',
    'installation_log' => 'Installation Log',
    'hide_log' => 'Hide Log',
    'show_log' => 'Show Log',
    'back_to_settings' => 'Back to Settings',
    'progress_status' => 'Installation List',
    'hide_progress' => 'Hide Installation List',
    'show_progress' => 'Show Installation List',
    'installation_completed' => 'Installation Complete!',
    'recommendations' => 'Post-Installation Recommendations',
    'recommendation_env_permission' => 'Set .env file permissions to 644 (chmod 644 .env)',
    'recommendation_https' => 'Use HTTPS in production environment',

    // Step 5: Complete
    'installation_info' => 'Installation Information',
    'installation_complete_message' => 'Gnuboard7 has been successfully installed!',
    'installed_version_label' => 'Installed version:',
    'site_url' => 'Site URL',
    'installed_at' => 'Installed At',
    'go_to_admin_login' => 'Admin Login',

    // Re-entry
    'completed_tasks' => 'Completed Tasks',
    'interrupted_task' => 'Interrupted Task',
    'pending_tasks' => 'Pending Tasks',
    'interrupted_during_execution' => 'Interrupted during execution',
    'resume_continue' => 'Continue',
    'installation_resuming' => 'Installation Resuming',

    // Error Messages - Common
    'error_occurred' => 'An error occurred',
    'please_try_again' => 'Please try again.',
    'invalid_input' => 'Invalid input.',

    // Error Messages - Validation (Database)
    'error_db_host_required' => 'Database host is required.',
    'error_db_name_required' => 'Database name is required.',
    'error_db_username_required' => 'Database username is required.',
    'error_db_credentials_required' => 'Database name and username are required.',
    'error_db_privileges_insufficient' => 'Insufficient database privileges.',
    'error_db_not_tested' => 'Please test the database connection first.',
    'error_write_db_not_tested' => 'Please test Write DB connection first.',
    'error_read_db_not_tested' => 'Please test Read DB connection first.',

    // Error Messages - Validation (Database) (detailed)
    'error_db_privileges_insufficient_detail' => 'Insufficient privileges for :type database: :missing',
    'error_db_connection_failed_detail' => ':type database connection failed: :error',
    'error_db_test_failed' => 'Error occurred during database testing',

    // Error Messages - Validation (Admin Account)
    'error_admin_email_invalid' => 'Please enter a valid email address.',
    'error_admin_language_invalid' => 'Please select a valid language.',
    'error_admin_name_required' => 'Please enter admin name.',
    'error_admin_password_required' => 'Please enter admin password.',
    'error_admin_password_min' => 'Password must be at least 8 characters long.',
    'error_admin_password_confirm_required' => 'Please enter password confirmation.',
    'error_password_mismatch' => 'Passwords do not match.',
    'error_app_name_required' => 'Please enter site name.',
    'error_app_url_required' => 'Please enter site URL.',
    'error_field_required' => 'This field is required.',

    // Error Messages - System (Requirements)
    'error_requirements_check_failed' => 'An error occurred while checking requirements.',
    'error_php_version_insufficient' => 'PHP version does not meet requirements.',
    'error_php_extensions_missing' => 'Some required PHP extensions are not installed.',
    'error_disk_space_insufficient' => 'Insufficient disk space.',
    'error_disk_space_unknown' => 'Unable to determine disk space.',
    'error_directory_not_writable' => 'Some directories are not writable.',

    // Error Messages - System (Requirements) (detailed)
    'error_php_version_insufficient_detail' => 'PHP version does not meet requirements (current: :current, required: :min+)',
    'error_disk_space_insufficient_detail' => 'Insufficient disk space (current: :current MB, required: :min MB)',
    'error_directory_not_writable_detail' => 'Some directories are not writable. Run the command below to grant the permissions.',
    'error_required_files_missing' => 'Required files are missing.',

    'error_step_file_not_found' => 'Step file not found. Installation files may be corrupted.',
    'error_state_file_creation_failed' => 'Unable to create installation state file (state.json). Please check write permissions for the storage/installer directory.',
    'error_invalid_step_access' => 'Invalid step access',
    'error_invalid_step_access_message' => 'Please follow the installation steps in order.',

    // Success Messages - Database
    'success_db_write_connected' => 'Write database connection and privileges verified successfully.',
    'success_db_read_connected' => 'Read database connection and privileges verified successfully.',

    // Success Messages - Requirements
    'success_requirements_met' => 'All requirements are met.',
    'success_php_version' => 'PHP version meets requirements.',
    'success_php_extensions' => 'All required PHP extensions are installed.',
    'success_disk_space' => 'Sufficient disk space available.',
    'success_directories_writable' => 'All directories are writable.',
    'success_required_files' => 'All required files exist.',

    // Success Messages - Requirements (detailed)
    'success_php_version_detail' => 'PHP version meets requirements (current: :current, required: :min+)',
    'success_disk_space_detail' => 'Sufficient disk space available (current: :current MB, required: :min MB)',

    // HTTPS Messages
    'https_enabled' => 'HTTPS is enabled (recommended)',
    'https_disabled' => 'HTTPS is disabled. We recommend using HTTPS for security.',

    // API Response Messages
    'api_method_not_allowed' => 'Only POST requests are allowed.',
    'api_invalid_request' => 'Invalid request data.',
    'api_unexpected_error' => 'An unexpected error occurred.',

    // Step Names (for helper functions)
    'step_0_welcome' => 'Welcome',
    'step_1_license' => 'License Agreement',
    'step_2_requirements' => 'Requirements Check',
    'step_3_configuration' => 'Configuration',
    'step_4_extension_selection' => 'Extension Selection',
    'step_5_installation' => 'Installation',
    'step_6_complete' => 'Complete',
    'step_unknown' => 'Unknown Step',

    // Task Names (for helper functions)
    'task_composer_check' => 'Checking Package Manager',
    'task_composer_install' => 'Installing Required Packages',
    'task_env_create' => 'Creating Configuration File',
    'task_env_update' => 'Updating Configuration File',
    'task_key_generate' => 'Generating Security Key',
    'task_dependency_precheck' => 'Extension Dependency Precheck',
    'task_db_cleanup' => 'Cleaning Up Existing Database Tables',
    'task_db_migrate' => 'Creating Database Tables',
    'task_db_seed' => 'Initializing Default Data',
    'task_template_install' => 'Installing Admin Template',
    'task_template_activate' => 'Activating Admin Template',
    'task_module_install' => 'Installing Module',
    'task_module_activate' => 'Activating Module',
    'task_plugin_install' => 'Installing Plugin',
    'task_plugin_activate' => 'Activating Plugin',
    'task_user_template_install' => 'Installing User Template',
    'task_user_template_activate' => 'Activating User Template',
    'task_language_pack_install' => 'Installing Language Pack',
    'task_cache_clear' => 'Cleaning Up Temporary Files',
    'task_create_settings_json' => 'Creating Settings Files',
    'task_complete_flag' => 'Finalizing Installation',
    'task_unknown' => 'Unknown Task',

    // Task Group Names
    'task_group_environment' => 'Environment Setup',
    'task_group_database' => 'Database',
    'task_group_admin_templates' => 'Admin Templates',
    'task_group_modules' => 'Modules',
    'task_group_plugins' => 'Plugins',
    'task_group_user_templates' => 'User Templates',
    'task_group_language_packs' => 'Language Packs',
    'task_group_finalize' => 'Finalize',

    // Group Status Labels
    'status_pending' => 'Pending',
    'status_in_progress' => 'In Progress',
    'status_completed' => 'Completed',
    'status_failed' => 'Failed',
    'status_aborted' => 'Aborted',

    // Error Messages - Worker (Composer)
    'error_composer_not_installed' => 'Composer is not installed.',
    'error_composer_install_failed' => 'Composer dependency installation failed',

    // Log Messages - Worker (Composer)
    'log_composer_check_success' => 'Composer check completed',
    'log_composer_check_skipped_bundled' => 'Composer check skipped — bundled vendor mode',
    'log_composer_check_auto_fallback' => 'Composer not installed — will auto-fallback to bundled vendor mode',
    'log_composer_already_installed' => 'Composer dependencies are already installed',
    'log_composer_install_success' => 'Composer dependency installation completed',
    'log_composer_vendor_without_lock' => 'vendor directory exists but composer.lock is missing (incomplete state)',
    'log_composer_removing_vendor' => 'Removing vendor directory and reinstalling...',
    'log_composer_vendor_deleted' => 'vendor directory deleted',
    'log_composer_vendor_delete_failed' => 'Failed to delete vendor directory (continuing anyway)',
    'log_composer_installing_from_lock' => 'Installing dependencies from composer.lock...',
    'log_composer_fresh_install' => 'Installing new Composer dependencies...',
    'log_composer_cache_cleared' => 'Cleared previous package cache',

    // Error Messages - Worker (.env)
    'error_env_example_not_found' => '.env.example file not found',
    'error_env_create_failed' => '.env file creation failed',
    'error_env_not_found' => '.env file not found.',

    // Log Messages - Worker (.env)
    'log_env_create_success' => '.env file creation completed',
    'log_env_update_success' => '.env file updated successfully',
    'log_env_readonly_skip' => '.env file is read-only, skipping update.',
    'log_env_flag_skipped' => '.env file is read-only, skipping installation completion flag.',

    // Error Messages - Worker (Key)
    'error_key_generate_failed' => 'Application key generation failed',

    // Log Messages - Worker (Key)
    'log_key_generate_success' => 'Application key generation completed',

    // Error Messages - Worker (Database)
    'error_db_migrate_failed' => 'Database table creation failed',
    'error_db_seed_failed' => 'Default data initialization failed',

    // Log Messages - Worker (Database)
    'log_db_migrate_success' => 'Database migration completed',
    'log_db_seed_success' => 'Database seeding completed',

    // Error Messages - Worker (Template)
    'error_template_install_failed' => 'Template installation failed',
    'error_template_activate_failed' => 'Template activation failed',

    // Log Messages - Worker (Template)
    'log_template_install_success' => 'Template installation completed',
    'log_template_activate_success' => 'Template activation completed',

    // Error Messages - Worker (Module)
    'error_module_install_failed' => 'Module installation failed',
    'error_module_activate_failed' => 'Module activation failed',

    // Log Messages - Worker (Module)
    'log_module_install_success' => 'Module installation completed',
    'log_module_activate_success' => 'Module activation completed',

    // Error Messages - Worker (Plugin)
    'error_plugin_install_failed' => 'Plugin installation failed',
    'error_plugin_activate_failed' => 'Plugin activation failed',

    // Log Messages - Worker (Plugin)
    'log_plugin_install_success' => 'Plugin installation completed',
    'log_plugin_activate_success' => 'Plugin activation completed',

    // Error Messages - Worker (User Template)
    'error_user_template_install_failed' => 'User template installation failed',
    'error_user_template_activate_failed' => 'User template activation failed',

    // Log Messages - Worker (User Template)
    'log_user_template_install_success' => 'User template installation completed',
    'log_user_template_activate_success' => 'User template activation completed',

    // Error/Log Messages - Worker (Language Pack)
    'error_language_pack_install_failed' => 'Language pack installation failed',
    'log_language_pack_install_success' => 'Language pack installation completed',
    'warning_language_pack_install_partial' => 'Language pack install partially failed: :identifier (continuing)',

    // Error Messages - Worker (Cache)
    'error_cache_clear_failed' => 'Cache clearing failed',

    // Log Messages - Worker (Cache)
    'log_cache_clear_success' => 'Cache clearing completed',

    // Error Messages - Worker (Settings JSON)
    'error_settings_json_failed' => 'Settings file creation failed',

    // Log Messages - Worker (Settings JSON)
    'log_creating_settings' => 'Creating settings JSON files...',
    'log_settings_json_created' => 'Settings JSON files created successfully',

    // Error Messages - Worker (Admin)
    'error_autoload_not_found' => 'vendor/autoload.php file not found',
    'error_app_bootstrap_not_found' => 'bootstrap/app.php file not found',

    // Log Messages - Worker (Complete)
    'log_complete_flag_success' => 'Installation completion flag set successfully',
    'log_installation_completed' => 'Installation completed successfully',
    'log_installation_failed' => 'Installation failed',
    'log_installation_task_failed' => '[Failed] :task: :message',
    'log_installation_exception' => '[Failed] Installation error occurred: :error',

    // Worker Log Messages (Task Progress)
    'log_task_in_progress' => ':task in progress...',
    'log_task_completed' => ':task completed',
    'log_task_skipped' => ':task (already exists, skipped)',
    'manual_commands_guide' => '💡 You can run the following commands manually:',
    'log_separator' => '========================================',
    'log_error_occurred' => '❌ Error occurred: :error',
    'log_env_file_created' => '.env file created successfully',
    'log_env_flag_added' => 'Installation complete flag added to .env file',
    'log_installed_flag_created' => 'g7_installed flag file created',
    'log_state_updated' => 'Installation state updated successfully',
    'log_all_tasks_completed' => '🎉 All installation tasks completed successfully!',
    'log_already_completed' => ':task (already completed)',

    // Worker Abort Messages
    'abort_connection_lost' => '[Aborted] Browser connection lost.',
    'abort_rollback_start' => "[Aborted] Starting rollback for current task ':task'.",
    'abort_rollback_success' => '[Aborted] Rollback completed: :message',
    'abort_rollback_failed' => '[Aborted] Rollback failed: :message (continuing)',
    'abort_no_rollback_needed' => '[Aborted] No rollback needed. (current_task is null or already completed)',
    'abort_by_user' => "[Aborted] User aborted installation. (Current task: :task)",
    'abort_installation_stopped' => 'Installation aborted.',

    // Worker Failed Task Rollback Messages
    'failed_rollback_start' => "[Failed] Starting rollback for failed task ':task'.",
    'failed_rollback_success' => '[Failed] Rollback completed: :message',
    'failed_rollback_failed' => '[Failed] Rollback failed: :message',
    'failed_rollback_manual_cleanup' => '[Notice] Rollback failed. Please manually clean up the database and try installation again.',
    'failed_rollback_manual_cleanup_detail' => 'Use a database management tool (such as phpMyAdmin) to delete created tables or reset the database, then retry installation.',
    'failed_rollback_db_restart' => '[Notice] Database has been rolled back. On retry, installation will restart from database setup.',
    'failed_rollback_retry' => '[Notice] The task failed. Please try the installation again.',
    'failed_rollback_retry_detail' => 'On retry, installation will resume from the failed task. If the problem persists, refer to the manual commands above.',

    // Worker SSE Connection Messages
    'sse_connection_established' => 'SSE connection established',
    'sse_method_not_allowed' => 'Method not allowed. SSE requires GET request.',

    // DB Task No Abort Messages
    'db_task_no_abort' => 'Cannot abort during database operations. The abort will happen after the current task completes.',
    'db_task_in_progress' => 'Database operation in progress...',

    // Worker Error Detail Messages
    'error_composer_process_failed' => 'Failed to start composer install process',
    'error_composer_exit_code' => 'Composer install failed with exit code: :code',
    'error_env_example_not_found_detail' => '.env.example file not found at: :path',
    'error_env_write_failed' => 'Failed to write .env file at: :path',
    'error_complete_flag_failed' => 'Installation completion flag setting failed',
    'error_unexpected_exception' => 'An unexpected error has occurred',

    // install-process.php Messages
    'error_method_not_allowed' => 'Only POST requests are allowed.',
    'error_config_not_in_session' => 'Configuration not found in session. Please complete Step 3 first.',
    'error_required_fields_missing' => 'Required fields are missing: :fields',
    'log_installation_config_saved' => 'Installation configuration saved - Waiting for SSE connection',
    'success_installation_started' => 'Installation started successfully.',
    'error_installation_start_failed' => 'Installation process start failed',
    'error_installation_start_exception' => 'Error occurred while starting installation: :error',

    // get-install-state.php Messages
    'error_get_method_required' => 'Only GET requests are allowed.',
    'error_state_query_failed' => 'An error occurred while querying installation state.',

    // Page Refresh/Leave Warnings (Browser shows default message, but clarifying intent)
    'confirm_leave_installation' => 'Installation is in progress. Leaving this page may cause installation issues.',
    'confirm_resume_installation' => 'Installation was in progress. Would you like to resume the installation?',
    'resume_installation_btn' => 'Resume Installation',
    'cancel_installation_btn' => 'Cancel',

    // Installation Already Completed Messages
    'installation_already_completed' => 'Installation Complete',
    'installation_already_completed_message' => 'Gnuboard7 is already installed. Redirecting to homepage...',

    // URL Parameter Access Messages
    'url_parameter_not_supported' => 'URL Parameter Not Supported',
    'url_parameter_redirect_message' => 'The installation process is managed by sessions. Redirecting to the current Step :current instead of requested Step :requested.',

    // Installation Abort Related
    'abort_installation' => 'Abort Installation',
    'aborting' => 'Aborting',
    'confirm_abort_installation' => 'Are you sure you want to abort the installation? The current installation progress will be preserved, and you can resume from where you left off later.',
    'installation_aborted' => 'Installation Aborted',
    'installation_aborted_message' => 'The installation was interrupted due to browser closure or refresh. You can resume the installation from where it was interrupted.',
    'clean_state' => 'Clean State',
    'cleaning' => 'Cleaning',
    'state_cleaned' => 'State has been cleaned',
    'clean_failed' => 'Clean failed',
    'clean_error' => 'Error occurred while cleaning',
    'resuming' => 'Resuming',
    'resume_failed' => 'Resume failed',
    'resume_error' => 'Error occurred while resuming',
    'abort_failed' => 'Abort failed',
    'abort_error_occurred' => 'Error occurred while aborting',

    // Validation Error Messages (validation.php)
    'validation_required' => ':field is required.',
    'validation_email' => ':field must be a valid email address.',
    'validation_min' => ':field must be at least :min characters.',
    'validation_max' => ':field must not exceed :max characters.',
    'validation_url' => ':field must be a valid URL.',
    'validation_alpha_num' => ':field may only contain letters and numbers.',
    'validation_alpha_num_underscore' => ':field may only contain lowercase letters, numbers, and underscores (_).',
    'validation_starts_with_alpha' => ':field must start with a lowercase letter.',
    'validation_confirmed' => ':field confirmation does not match.',
    'validation_numeric' => ':field must be a number.',
    'validation_integer' => ':field must be an integer.',
    'validation_in' => ':field must be one of: :values',

    // Field Labels (validation.php)
    'fields' => [
        'db_host' => 'Database Host',
        'db_port' => 'Database Port',
        'db_database' => 'Database Name',
        'db_username' => 'Database Username',
        'db_password' => 'Database Password',
        'db_write_host' => 'Write Database Host',
        'db_write_port' => 'Write Database Port',
        'db_write_database' => 'Write Database Name',
        'db_write_username' => 'Write Database Username',
        'db_write_password' => 'Write Database Password',
        'db_prefix' => 'Table Prefix',
        'db_read_host' => 'Read Database Host',
        'db_read_port' => 'Read Database Port',
        'db_read_database' => 'Read Database Name',
        'db_read_username' => 'Read Database Username',
        'db_read_password' => 'Read Database Password',
        'app_name' => 'Site Name',
        'app_url' => 'Site URL',
        'admin_name' => 'Admin Name',
        'admin_language' => 'Admin Language',
        'admin_email' => 'Admin Email',
        'admin_password' => 'Admin Password',
        'admin_password_confirmation' => 'Admin Password Confirmation',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirmation' => 'Password Confirmation',
    ],

    // JavaScript Client Messages (installer.js)
    'testing_connection' => 'Testing connection...',
    'connection_failed_prefix' => 'Connection failed:',
    'validation_incomplete_alert' => 'Please complete all required fields and database connection tests.',
    'validation_incomplete_title' => 'Please complete the following items:',
    'confirm_leave_page' => 'Settings have not been saved. Are you sure you want to leave this page?',
    'installation_in_progress_alert' => 'Installation is in progress. Do you want to go back to the settings page?',
    'confirm_go_to_settings' => "Do you want to go back to the settings page?",
    'confirm_go_to_settings_simple' => "Do you want to go back to the settings page?\n\nInstallation state will be reset and all tasks will start from the beginning.\n\n⚠️ Database tables will NOT be deleted automatically.\nPlease clean up manually using phpMyAdmin if needed.",
    'confirm_go_to_settings_title' => 'Go to Settings Page',
    'confirm_go_to_settings_desc' => 'Installation state will be reset and all tasks will start from the beginning.\n\n⚠️ Database tables will NOT be deleted automatically. Please clean up manually using phpMyAdmin if needed.',
    'confirm_go_to_settings_btn' => 'Go',
    'reset_db_checkbox_label' => 'Reset database (delete created tables)',
    'error_state_reset_failed' => 'Failed to reset state.\n\n:message\n\nPlease refresh the page and try again.',
    'confirm_force_go_to_settings' => 'An error occurred while resetting state.\n\nDo you still want to go to the settings page?',
    'view_error_details' => 'View error details',
    'installation_error_occurred' => 'An error occurred during installation.',
    'installation_start_error' => 'Error starting installation',
    'installation_start_failed' => 'Unable to start installation.',
    'unknown_error_occurred' => 'An unknown error has occurred.',

    // SSE connection failure messages
    'sse_connection_timeout' => 'SSE real-time connection failed. Please check server configuration or refresh the page.',
    'sse_server_config_guide' => 'If you are the server administrator, please check the following settings:

[Nginx Configuration]
location /install/api/ {
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 600s;
}

[Apache Configuration]
# .htaccess or httpd.conf
<Location /install/api/>
    SetEnv no-gzip 1
</Location>

[PHP Configuration]
// Add to the top of install-worker.php
ini_set(\'output_buffering\', \'off\');
ini_set(\'zlib.output_compression\', \'off\');
@apache_setenv(\'no-gzip\', 1);

[Shared Hosting]
Contact your hosting provider about SSE (Server-Sent Events) support.
Firewalls or proxies may be blocking long-lived HTTP connections.',

    // Installation mode (SSE / polling)
    'installation_mode_label' => 'Installation Mode',
    'installation_mode_sse_title' => 'Real-time Streaming (SSE) — Recommended',
    'installation_mode_sse_desc' => 'The server streams progress in real time. Fastest and most reliable in standard environments.',
    'installation_mode_polling_title' => 'Compatibility Mode (Polling)',
    'installation_mode_polling_desc' => 'Use this when SSE connection errors occur behind an Nginx proxy, shared hosting, etc. Status is polled every second.',
    'sse_fallback_confirm' => 'SSE connection failed.\n\nWould you like to retry in compatibility mode (polling)?',
    'sse_probe_incompat_use_polling' => "SSE real-time connection is not available in this environment.\n\nProceed with installation in compatibility mode (polling)?\n\n[OK] Start installation in polling mode\n[Cancel] Return to start screen",
    'start_installation_button' => 'Start Installation',

    // Step 4 dependency warnings
    'dependency_warning_title' => 'Missing Dependencies Detected',
    'dependency_warning_description' => 'The following extensions are required. Click "Auto-select required items" to resolve them in one click.',
    'dependency_auto_select' => 'Auto-select required items',
    'dependency_missing_tooltip' => 'Please resolve dependencies first',
    'dependency_precheck_failed' => 'Dependency precheck failed — return to Step 4 and select the missing modules/plugins',

    // Step 3 existing database detection (issue #244)
    'db_existing_g7_badge' => 'Existing G7 installation detected',
    'db_existing_foreign_badge' => 'Foreign data detected',
    'db_existing_mixed_badge' => 'Mixed G7 + foreign tables',
    'db_existing_g7_title' => 'Existing G7 Installation Detected',
    'db_existing_foreign_title' => 'Foreign Data Detected',
    'db_existing_mixed_title' => 'Mixed Data Detected',
    'db_existing_generic_title' => 'Existing Tables Detected',
    'db_existing_g7_desc' => 'The selected database already contains a G7 installation. Forcing the install will delete all existing data. Back up first.',
    'db_existing_foreign_desc' => 'The selected database contains unknown tables. Forcing the install will drop all of them. Consider using another database or back up first.',
    'db_existing_mixed_desc' => 'The selected database contains a mix of G7 and other tables. Forcing the install will drop all of them.',
    'db_existing_generic_desc' => 'The selected database contains existing tables. Forcing the install will drop all of them.',
    'db_existing_tables_list' => 'Detected tables (up to 20):',
    'db_backup_guide' => 'Backup command example (verify host/user/database before running):',
    'db_backup_confirmed' => 'I have backed up and agree that all existing tables will be dropped',
    'error_db_cleanup_consent_required' => 'You must consent to dropping existing tables before proceeding to the next step.',
    'db_force_proceed_drop' => 'Drop all existing tables and install',
    'db_force_proceed_confirmed' => 'Force install mode (existing tables will be dropped)',
    'log_db_cleanup_skipped' => 'Skipping existing table cleanup (no action)',
    'log_db_cleanup_empty' => 'No existing tables. Skipping cleanup.',
    'log_db_cleanup_dropping' => 'Dropping {count} existing tables...',
    'log_db_cleanup_done' => 'Dropped {count} existing tables.',
    'error_db_cleanup_failed' => 'Failed to clean up existing database tables',

    // Dark Mode
    'toggle_theme' => 'Toggle Theme',
    'dark_mode' => 'Dark Mode',
    'light_mode' => 'Light Mode',

    // Step 4: Extension Selection
    'extension_selection_description' => 'Select the extensions to install. Admin template is required.',
    'loading_extensions' => 'Loading extensions...',
    'admin_templates' => 'Admin Templates',
    'admin_templates_description' => 'Select a template for the admin panel.',
    'user_templates' => 'User Templates',
    'user_templates_description' => 'Select a template for the front pages.',
    'modules' => 'Modules',
    'modules_description' => 'Select modules to install. Modules provide major features like boards and e-commerce.',
    'plugins' => 'Plugins',
    'plugins_description' => 'Select plugins to install. Plugins provide additional features like payments and notifications.',
    'language_packs' => 'Language Packs',
    'language_packs_description' => 'Select bundled language packs for additional language support. Each pack requires its target extension to be selected.',
    'optional' => 'Optional',
    'no_extensions_found' => 'No extensions available.',
    'no_user_templates' => 'No user templates available.',
    'no_modules' => 'No modules available.',
    'no_plugins' => 'No plugins available.',
    'no_language_packs' => 'No bundled language packs available.',
    'language_pack_disabled_by_extension' => 'Disabled — its target extension is not selected.',
    'language_pack_scope_core' => 'Core',
    'language_pack_scope_module' => 'Module',
    'language_pack_scope_plugin' => 'Plugin',
    'language_pack_scope_template' => 'Template',
    'bulk_select_all_optional' => 'Select all available items',
    'bulk_select_section' => 'Select all',

    // Brand name
    'brand_name' => 'Gnuboard7',

    // Step 3 — Vendor installation mode
    'vendor_mode_title' => 'Vendor Installation Mode',
    'vendor_mode_description' => 'Choose how to install PHP package dependencies (vendor/).',
    'vendor_mode_auto_title' => 'Auto',
    'vendor_mode_auto_description' => 'Use Composer when available, fall back to bundled vendor when not.',
    'vendor_mode_auto_status_ok' => 'Environment auto-detected',
    'vendor_mode_composer_title' => 'Run Composer',
    'vendor_mode_composer_description' => 'Install latest versions via composer install (requires internet + proc_open).',
    'vendor_mode_composer_status_ok' => 'proc_open available',
    'vendor_mode_composer_status_blocked' => 'proc_open() blocked — unavailable',
    'vendor_mode_bundled_title' => 'Use Bundled Vendor',
    'vendor_mode_bundled_description' => 'Extract vendor-bundle.zip (offline install, composer not required).',
    'vendor_mode_bundled_status_ok' => 'vendor-bundle.zip found',
    'vendor_mode_bundled_status_no_ziparchive' => 'ZipArchive extension missing — unavailable',
    'vendor_mode_bundled_status_no_zip' => 'vendor-bundle.zip not found — unavailable',
    'vendor_mode_badge_recommended' => 'Recommended',
    'vendor_mode_badge_dev' => 'Development',
    'vendor_mode_badge_shared' => 'Shared hosting',
    'vendor_mode_change_later_html' => 'You can change this later via <code>php artisan core:update --vendor-mode=...</code>.',

    // Installation stuck detection
    'error_installation_stuck' => 'Installation has stopped responding. The server worker may be stuck running a command. Please refresh and retry. If the problem persists, check storage/logs/installation.log.',
    'extension_load_failed' => 'Failed to load extension list.',
    'no_admin_template_error' => 'Admin template is required but not found. Please ensure at least one admin template exists in the templates directory.',
    'selection_summary' => 'Selection Summary',
    'selected_count' => ':count selected',
    'admin_template_required' => 'At least one admin template must be selected.',
    'proceed_to_installation' => 'Proceed to Installation',
    'version' => 'Version',
    'select' => 'Select',
    'selected' => 'Selected',
    'dependencies' => 'Dependencies',
    'dependency_type_module' => 'Module',
    'dependency_type_plugin' => 'Plugin',
    'dependency_type_admin_template' => 'Admin Template',
    'dependency_type_user_template' => 'User Template',
    'dependency_type_other' => 'Other',
    'dep_auto_badge_label' => 'Required dependency',
    'dep_lock_message' => ':names requires this item. Please deselect that extension first.',
    'dep_version_required' => 'Required',
    'dep_version_available' => 'Available',
    'author' => 'Author',
    'retry' => 'Retry',
    'select_all' => 'Select All',
    'deselect_all' => 'Deselect All',

    // install-worker.php i18n keys
        'db_task_abort_detected_before_start' => '[DB Task] Abort detected before start - skipping task.',
    'db_task_failed_rollback_start' => '[DB Task] :task failed - starting rollback.',
    'db_task_abort_reason_connection' => 'Connection lost',
    'db_task_abort_reason_user' => 'User requested',
    'db_task_completed_abort_detected' => '[DB Task] Abort detected after :task completed (:reason) - starting rollback.',
    'db_task_completed_rollback_start' => '[Aborted] Starting rollback after :task completed.',
    'log_removing_state_file' => 'Removing installer state file...',
    'log_state_file_removed' => 'Installer state file removed successfully.',
    'log_state_file_remove_failed' => 'Warning: Failed to remove installer state file.',
    'error_worker_exception' => 'Installation worker SSE exception',

    // rollback-functions.php i18n keys
    'log_prefix_rollback' => '[Rollback]',
    'log_prefix_force_rollback' => '[Force Rollback]',
    'rollback_not_needed_recreatable' => 'Rollback not needed (can be recreated)',
    'rollback_not_needed_overwritable' => 'Rollback not needed (can be overwritten on reinstall/reactivation)',
    'rollback_unknown_task' => 'Unknown task: :task',
    'rollback_error' => '[Rollback Error] :task: :error',
    'rollback_exception' => 'Exception during rollback: :error',
    'rollback_migrate_start' => 'Starting migration rollback (migrate:rollback).',
    'rollback_migrate_result' => 'migrate:rollback result: :result',
    'rollback_migrate_success' => 'Migration rollback completed',
    'rollback_migrate_failed_code' => 'Migration rollback failed (returnCode: :code)',
    'rollback_migrate_failed' => 'Migration rollback failed',
    'rollback_migrate_error' => 'Error during migration rollback: :error',
    'rollback_seed_no_config' => 'No DB configuration found. Skipping seed rollback.',
    'rollback_seed_already_done' => 'Seed already completed (rollback not needed)',
    'rollback_seed_interrupted' => 'Seed was interrupted. Truncating tables.',
    'rollback_seed_force_truncate' => 'Force truncating seed data.',
    'rollback_table_truncated' => "Table ':table' truncated.",
    'rollback_table_truncate_skipped' => "Table ':table' TRUNCATE skipped: :error",
    'rollback_seed_data_deleted' => 'Seed data deleted.',
    'rollback_db_connection_failed' => 'DB connection failed: :error',
    'rollback_db_connection_failed_skip' => 'DB connection failed. Skipping seed rollback.',
    'rollback_seed_error' => 'Error during seed processing: :error',
    'rollback_env_flag_removed' => 'Removed INSTALLER_COMPLETED from .env.',
    'rollback_installed_flag_removed' => 'Deleted g7_installed file.',
    'rollback_runtime_removed' => 'Cleaned up installer runtime config.',
    'error_worker_busy' => 'Another install worker is in progress. Please retry shortly.',
    'rollback_complete_flag_removed' => 'Installation completion flags removed: :details',
    'rollback_complete_flag_error' => 'Error removing completion flag: :error',
    'rollback_no_current_task' => 'No current task to rollback. (current_task is null)',
    'rollback_task_already_completed' => "':task' is already completed. (rollback not needed)",
    'rollback_current_task_start' => "[Aborted] Starting rollback for current task ':task'.",
    'rollback_no_completed_tasks' => 'No completed tasks to rollback.',
    'rollback_checking_tasks' => 'Checking rollbackable tasks...',
    'rollback_no_matching_tasks' => 'No tasks to rollback. (no matching tasks in rollbackable list)',
    'rollback_tasks_to_rollback' => 'Tasks to rollback: :tasks',
    'rollback_task_rolling_back' => "Rolling back ':task'...",
    'rollback_task_success' => "':task' rollback succeeded",
    'rollback_task_failed' => "':task' rollback failed: :error",
    'manual_cmd_settings_json' => 'settings.json is generated automatically. Please retry the installation.',

    // state-management.php i18n keys
    'state_reset_requested' => 'Requested navigation to settings page.',
    'state_reset_db_notice' => 'DB is not automatically reset. Please clean up manually if needed.',
    'state_save_failed' => 'Failed to save state file.',
    'state_reset_completed' => 'Installation state has been reset. (Moving to Step :step)',
    'abort_api_requested' => '[Abort API] Abort requested - user requested installation abort',
    'abort_api_current_status' => '[Abort API] Current installation_status: :status',
    'abort_api_current_task' => '[Abort API] Current current_task: :task',
    'abort_api_completed_count' => '[Abort API] Completed task count: :count',
    'abort_api_already_completed' => 'Installation is already completed and cannot be aborted.',
    'abort_api_already_aborted' => 'Installation is already aborted.',
    'abort_api_not_running' => 'No installation to abort.',
    'abort_user_requested' => 'User requested installation abort.',
    'abort_api_status_change' => '[Abort API] Changing installation_status to "aborted".',
    'abort_api_save_result' => '[Abort API] state.json save result: :result',
    'abort_api_verify_status' => '[Abort API] Verify after save - installation_status: :status',
    'error_state_management' => 'State management error',
    'error_log_prefix' => '[Error] :error',

    // functions.php i18n keys
    'error_db_name_username_required' => 'Database name and username are required.',
    'error_db_invalid_parameter' => 'Database :field contains characters that are not allowed.',
    'error_db_connection_failed' => 'Database connection failed: :error',
    'error_privilege_check_failed' => 'Error occurred during privilege check',

    // Required file setup (Step 5)
    'file_setup_title' => 'Create Required Files',
    'file_setup_description' => 'The following files are required in the project root to proceed with installation.',
    'file_setup_guide' => 'Run the following command in your terminal from the project root directory:',
    'file_check_button' => 'Verify files',
    'file_checking' => 'Checking...',
    'files_not_found_yet' => 'Required files not found yet. Please create the files and try again.',
    'copy_command' => 'Copy',
    'copied' => 'Copied!',

    // Step 2 permission guidance
    'permission_fix_guide' => 'Run the following command in your terminal or SSH to grant permissions:',
    'ownership_mismatch_option_group' => '[Recommended] Group sharing — change group to the web server user and grant write permission:',
    'ownership_mismatch_option_chown' => '[Alternative 1] Change owner — transfer ownership to the web server user:',
    'ownership_mismatch_option_777' => '[Alternative 2] Last resort — grant write permission to everyone (reduced security, not recommended):',
    'ownership_mismatch_hint' => '※ Current owner: :owner / Web server user: :web_user. Choose the option that matches your environment.',
    'permission_fallback_title' => 'If the command above does not resolve the issue',
    'permission_fallback_detail' => 'Some environments (e.g. dedicated servers) may require: chmod -R 775 :path',
    'permission_windows_hint' => '※ On Windows, open Command Prompt (cmd) as Administrator and run the command. Alternatively, you can set permissions via folder Properties → Security tab.',
    'directory_create_guide' => 'Run the following command to create the missing directories:',

    // Directory names (Step 2)
    'dir_bootstrap_cache' => 'Bootstrap cache directory',
    'dir_vendor' => 'Packages directory',
    'dir_modules' => 'Modules directory',
    'dir_modules_pending' => 'Modules pending directory',
    'dir_plugins' => 'Plugins directory',
    'dir_plugins_pending' => 'Plugins pending directory',
    'dir_templates' => 'Templates directory',
    'dir_templates_pending' => 'Templates pending directory',

    // save-extensions.php i18n keys
    'log_extensions_selected' => 'Extension selection complete: :admin admin template(s), :user user template(s), :modules module(s), :plugins plugin(s), :language_packs language pack(s)',
    'log_extensions_saved' => 'Extension selection has been saved.',
    'error_admin_template_required' => 'Please select at least one admin template.',
    'error_invalid_extension_identifier' => 'Extension identifier ":identifier" is not in an allowed format (group: :group). Only letters, digits, and `._-` are accepted.',

    // Core update settings
    'core_update_settings' => 'Core Update Settings (Optional)',
    'core_update_pending_path' => 'Update Pending Directory Path',
    'core_update_pending_path_help' => 'Leave empty to use the default (storage/app/core_pending). Enter an absolute path or a path relative to the Gnuboard7 root to use a custom location.',
    'core_update_github_url' => 'GitHub Repository URL',
    'core_update_github_url_help' => 'GitHub repository URL to check for core updates.',
    'core_update_github_token' => 'GitHub Access Token',
    'core_update_github_token_help' => 'A GitHub Personal Access Token is required for private repositories. Leave empty for public repositories.',
    'check_core_pending_path' => 'Check Path',
    'dir_core_pending' => 'Core update pending directory',
    'core_pending_will_be_created' => 'Directory does not exist. It will be created automatically during installation.',
    'core_pending_path_ok' => 'Path is valid.',
    'core_pending_info' => 'Owner: :owner, Group: :group, Permissions: :permissions',
    'error_core_pending_not_directory' => 'The specified path is not a directory.',
    'error_core_pending_not_writable' => 'Directory (:path) is not writable.',
    'error_core_pending_parent_not_writable' => 'Parent directory (:path) is not writable, cannot create automatically.',
    'error_path_required' => 'Please enter a path.',
    'error_core_pending_path_invalid' => 'The path you entered is not a valid directory or is not in an allowed format.',

    // PHP CLI / Composer Settings
    'php_cli_settings' => 'PHP CLI Settings (Optional)',
    'php_cli_settings_required' => 'PHP CLI Settings (Required)',
    'php_cli_settings_help' => 'If PHP CLI is not in the system PATH or has a different version on your hosting environment, you can specify the path directly. No changes needed for typical environments.',
    'php_cli_settings_help_required' => 'The default php command is not available on this server. Please specify the correct PHP CLI path and verify it.',
    'php_binary_path' => 'PHP CLI Path',
    'php_binary_path_help' => 'e.g. /usr/local/php82/bin/php (default: php)',
    'composer_binary_path' => 'Composer Path',
    'composer_binary_path_help' => 'Leave empty to auto-detect from system PATH. .phar file paths are also supported.',
    'verify_version' => 'Verify Version',
    'auto_detect_php' => 'Auto-detect PHP',
    'detecting_php' => 'Scanning for PHP binaries...',
    'detected_php_binaries' => 'Detected PHP binaries (click to select):',
    'no_php_detected' => 'No PHP binaries found on this server.',
    'php_detected_count' => ':count PHP binary(ies) found.',
    'success_php_binary_version' => ':path — PHP :version ✓',
    'error_php_path_empty' => 'PHP binary path is empty.',
    'error_php_path_not_exists' => 'File does not exist: :path',
    'error_php_exec_failed' => 'PHP execution failed: :path',
    'error_php_version_too_low' => ':path — PHP :version (minimum :min required)',
    'error_php_version_parse_failed' => 'Failed to parse PHP version.',
    'error_php_cli_not_verified' => 'PHP CLI path has not been verified. Please click the "Verify Version" button.',
    'error_composer_not_verified' => 'Composer execution has not been verified. Please click the "Verify Version" button.',
    'error_composer_path_not_exists' => 'File does not exist: :path',
    'error_composer_exec_failed' => 'Composer execution failed: :path',
    'error_composer_version_parse_failed' => 'Failed to parse Composer version.',
    'error_check_failed' => 'Check request failed. Please verify network or server status.',
    'session_cookie_blocked_title' => 'Session Cookie Blocked',
    'session_cookie_blocked_description' => 'Your browser is not preserving the installer session cookie (PHPSESSID), which may cause the installation to stall. You may proceed but it is not recommended — please try one of the workarounds below.',
    'session_cookie_blocked_remedy_1' => 'Try a different browser. Incognito/Private windows usually have stricter cookie policies — a regular window is recommended.',
    'session_cookie_blocked_remedy_2' => 'Disable cookie/site-data blocking in your browser and refresh the page.',
    'session_cookie_blocked_remedy_3' => 'Access via IP or localhost instead of a domain name. Example: http://localhost:port/install/',
    'session_cookie_blocked_technical_detail' => 'Technical detail: Installer session cookie round-trip verification failed. (Likely caused by SameSite policy, non-standard port, browser tracking protection, or similar combination.)',
    'session_cookie_blocked_dismiss' => 'Dismiss warning',
    'success_composer_version' => ':path — Composer :version ✓',
    'composer_install_guide_title' => 'Composer Installation Guide',
    'composer_install_guide_message' => 'Composer is not installed. You can install it using the following methods:',
    'composer_install_guide_global' => 'Global installation (recommended):',
    'composer_install_guide_local' => 'Local installation (project directory):',
    'composer_install_guide_hosting' => 'Hosting environment installation (if above methods fail):',
    'composer_install_guide_pwd_hint' => 'After installation, check the absolute path of the current directory with the command below:',
    'composer_install_guide_phar_hint' => 'Enter the full execution command with absolute path in the Composer path field above. Example: /usr/local/php84/bin/php /home/user/g7/composer.phar',
    'composer_install_guide_link' => 'For detailed instructions, visit <a href="https://getcomposer.org/download/" target="_blank" rel="noopener">getcomposer.org</a>.',
    'composer_checking' => 'Checking Composer...',
    'cli_status_checking' => 'Checking...',
    'cli_status_verified' => '✓ Verified',
    'cli_status_not_verified' => '✗ Not verified',
    'cli_status_optional_bundled' => '— Optional (bundled mode)',

    // Environment Check Enhancements
    'required_functions' => 'Required Functions',
    'required_functions_available' => 'Available',
    'required_functions_disabled' => ':count disabled',
    'success_required_functions' => 'Required functions (exec, proc_open, shell_exec) are all available.',
    'error_disabled_functions' => 'The following functions are disabled and installation cannot proceed: :functions. Please ask your hosting provider to enable them.',
    'php_cli_version' => 'PHP CLI Version',
    'php_cli_version_check_skipped' => 'exec function is disabled, CLI version check skipped.',
    'php_cli_version_unknown' => 'Unable to determine PHP CLI version.',
    'php_cli_version_matched' => 'Matched (Web: :web, CLI: :cli)',
    'php_cli_version_mismatch' => 'Mismatch (Web: :web, CLI: :cli)',
    'php_cli_version_mismatch_hint' => 'Please specify the correct PHP CLI path in Step 3.',
    'success_core_pending_path' => 'Path is valid and writable.',

    // Combined .env guidance
    'required_files_fix_guide_combined' => '.env file creation and permission setup required. Run the following command:',

    // Relative path alternative
    'or_relative_path' => 'Or from the G7 root directory:',
];
?>
