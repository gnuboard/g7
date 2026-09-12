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
    'current_owner' => 'Current owner',
    'current_group' => 'Current group',
    'current_permissions' => 'Current permissions',
    'required_permissions' => 'Required permissions',
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
    'opcache' => 'OPcache',
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
    'badge_info' => 'Info',
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
    'db_prefix_hint' => 'Prefix added to all table names. (e.g., g7_users, g7_posts) Up to 6 characters.',
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

    // .env permission hardening hint (Step 5 completion screen, shown when not 0600)
    'env_hardening_hint_title' => '.env Permission Hardening (Optional)',
    'env_hardening_hint_current_status' => 'Current .env permission is :permission (owner: :owner). The most secure permission is 0600 (owner read/write only). To apply additional hardening, run the appropriate command below with sudo based on your environment.',
    'env_hardening_hint_option_strict' => 'CLI user = web server user (single-user environment)',
    'env_hardening_hint_option_group' => 'CLI user ≠ web server user (self-hosted server, etc. — group-readable compromise)',
    'env_hardening_hint_note' => 'Verify your environment before running. Applying the wrong permission may prevent the web server from reading .env and cause the site to stop responding.',

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
    'error_db_username_privileged' => 'For security reasons, G7 cannot be installed with the ":username" account. If a superuser account is compromised, the entire database is at risk. Please create a dedicated database user for G7, grant it only the privileges it needs, and enter that account instead.',
    'error_db_prefix_too_long' => 'Table prefix may be up to :max characters. (entered: :current) A long prefix makes some table index names exceed the database identifier limit.',
    'error_db_privileges_insufficient' => 'Insufficient database privileges.',
    'error_db_not_tested' => 'Please test the database connection first.',
    'error_write_db_not_tested' => 'Please test Write DB connection first.',
    'error_read_db_not_tested' => 'Please test Read DB connection first.',

    // Error Messages - Validation (Database) (detailed)
    'error_db_privileges_insufficient_detail' => 'Insufficient privileges for :type database: :missing',
    'warning_db_privileges_indeterminate' => 'Could not verify privileges for the :type database. Installation can proceed; if privileges are insufficient, the affected step will stop with the reason during installation.',
    'warning_db_server_version_low' => 'The database server version (:server) is below the recommended minimum (:minimum). You can attempt the installation, but it may fail along the way.',
    'log_memory_limit_raise_shortfall' => 'Could not raise the memory limit to :target (currently :current). Installation continues with the current limit and may stop due to insufficient memory.',
    'php_limits_open_basedir' => 'An open_basedir path restriction is active — some system path probing (such as PHP binary auto-detection) may be limited.',
    'error_extension_selection_locked' => 'The installation has already started, so the extension selection cannot be changed. To change it, reset the installation with [Start over] first.',
    'log_db_cleanup_dropped_tables' => 'Dropped tables: :tables',
    'warning_disk_space_low_headroom' => 'Installation is possible, but the free space is small compared to the actual usage of the core and bundled extensions (about :recommended), so the disk may run out during installation.',
    'error_env_not_writable_start' => 'The .env file is not writable, so the installation cannot start. If it proceeded, the completion flag could not be written even after a successful install, leaving the installer permanently open. Fix the file permissions and try again.',
    'env_not_writable_label' => 'not writable',
    'db_drop_targets_list' => 'Tables that will be deleted if you consent to [Force proceed] (:count)',
    'db_nested_g7_warning' => 'Warning: the deletion scope of this prefix includes another Gnuboard site installed with a longer prefix (:prefixes). If you consent, that site\'s tables will also be deleted.',
    'error_db_connection_failed_detail' => ':type database connection failed: :error',
    'error_db_test_failed' => 'Error occurred during database testing',

    // Error Messages - Validation (Admin Account)
    'error_admin_credentials_missing' => 'The administrator account details could not be found. Go back to the configuration step and enter the administrator name, email, and password again.',
    'error_admin_email_invalid' => 'Please enter a valid email address.',
    'error_app_name_too_long' => 'The site name cannot exceed :max characters.',
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
    'https_behind_proxy' => 'This site appears to be running behind a reverse proxy. Unless TRUSTED_PROXIES is set in .env after installation, the site address and visitor IP will be recognized from the proxy instead of the real visitor. (https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md)',

    // OPcache Messages
    'opcache_enabled' => 'OPcache is enabled (recommended)',
    'opcache_disabled_short' => 'Disabled — pages load several times slower',
    'opcache_disabled_warning' => 'OPcache is disabled. In this state, every single page view re-parses all of your site\'s PHP code from scratch. On identical hardware this can make responses several times slower, and the gap widens as traffic grows. You can continue the installation as-is, but if this server will be used in production we strongly recommend setting opcache.enable=1 in php.ini and restarting your web server.',
    'opcache_unknown' => 'OPcache status could not be determined. Reading PHP settings is restricted on this server.',

    // Composer Dependency Set Messages
    'vendor_dev_packages' => 'Composer dependencies',
    'vendor_dev_packages_none_short' => 'Production set (no dev packages)',
    'vendor_dev_packages_none' => 'No development packages in vendor. (recommended)',
    'vendor_dev_packages_detected_short' => ':count development package(s) present — not recommended for production',
    'vendor_dev_packages_detected_warning' => 'The existing vendor directory contains :count development (require-dev) package(s). Installation can continue, but on a production server run composer install --no-dev --optimize-autoloader from the project root after installation to remove them. (The setup wizard installs the production set automatically only when vendor is absent.)',
    'vendor_dev_packages_unknown' => 'Could not inspect vendor (installed.json missing or unrecognized).',
    'vendor_dev_packages_no_vendor' => 'No vendor directory — the wizard installs the production set automatically.',

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
    'task_config_cache' => 'Building Configuration Cache',
    'task_static_publish' => 'Publishing Static Bootstrap Resources',
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
    'status_label_pass' => 'Passed',
    'status_label_fail' => 'Failed',
    'status_label_warning' => 'Warning',
    'status_label_info' => 'Info',
    'status_pending' => 'Pending',
    'status_in_progress' => 'In Progress',
    'status_completed' => 'Completed',
    'status_failed' => 'Failed',
    'status_skipped' => 'Skipped',
    'status_aborted' => 'Aborted',
    // Group with skipped steps — a green "Completed" would hide what was left out
    'status_completed_with_skipped' => 'Completed (:skipped skipped)',
    // Spinner label while a path/binary is being verified
    'checking' => 'Checking...',

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
    'log_composer_dev_packages_detected' => 'Existing vendor contains :count development package(s) (:packages)',
    'warning_composer_dev_packages_kept' => 'Keeping the vendor directory that contains development packages. On a production site, run "composer install --no-dev --optimize-autoloader" after installation (installation continues)',

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
    'log_composer_unavailable_fallback_bundle' => 'Composer cannot run on this server, so the bundled package is used instead.',
    'warning_best_effort_task_failed' => 'Step :task failed but is not required for installation — continuing (it will be retried on retry): :error',
    'task_vendor_bundle_extract' => 'Extract vendor bundle',
    'log_vendor_bundle_extract_start' => 'Starting vendor bundle extraction...',
    'log_vendor_bundle_verified' => 'vendor-bundle.zip verified (:count packages)',
    'log_vendor_bundle_extracting' => 'Extracting into vendor/ directory...',
    'error_vendor_bundle_extract_failed' => 'Vendor bundle extraction failed: :error',
    'log_vendor_bundle_extract_done' => 'Vendor bundle extracted (:count packages)',
    'log_vendor_bundle_extract_complete' => 'Vendor bundle extraction complete',
    'stepwise_lock_lost_retry' => 'Another request took over this step, so this one is stepping back. Continuing shortly.',
    'log_stepwise_lock_taken_over' => 'The previous request stopped responding, so this request took over the step.',
    'log_worker_lock_lost_retreat' => 'Another request took over the installation, so this one is stepping back.',
    'log_worker_disconnected_abort' => 'The browser connection was lost, so this request is stopping.',
    'log_worker_started' => 'Starting the installation tasks.',
    'log_finalize_env_generate_failed' => '[finalize-env] Could not build the base .env content (.env.example missing)',
    'log_finalize_env_backup_kept' => '[finalize-env] Existing .env preserved: :path',
    'log_finalize_env_write_failed' => '[finalize-env] Failed to write .env: path=:path, last_error=:error',
    'log_finalize_env_merged' => '[finalize-env] .env merge complete (permissions untouched): path=:path, perms=:perms, owner_uid=:owner, group_gid=:group, process_euser=:euser',
    'log_finalize_env_runtime_delete_failed' => '[finalize-env] Failed to delete runtime.php: path=:path, last_error=:error. The file remains, so temporary credentials stay in plain text - please delete it manually.',
    'log_finalize_env_runtime_removed' => '[finalize-env] runtime.php deleted',
    'log_finalize_env_config_cache_delete_failed' => '[finalize-env] Failed to delete the config cache: path=:path, last_error=:error. It still holds pre-install values - delete the file manually or run `php artisan config:clear`.',
    'log_finalize_env_config_cache_removed' => '[finalize-env] Config cache deleted (the next request rebuilds it from .env)',
    'log_finalize_env_state_delete_failed' => '[finalize-env] Failed to delete the installation state file: path=:path, last_error=:error',
    'log_finalize_env_state_removed' => '[finalize-env] Installation state file deleted',
    'log_finalize_env_state_redacted' => '[finalize-env] Installation state file remains - secret fields redacted',
    'log_finalize_env_state_redact_failed' => '[finalize-env] Failed to redact secret fields in the installation state file - please delete it manually: :path',
    'log_finalize_env_unexpected_exception' => '[finalize-env] Unexpected error: :message @ :file::line',
    'log_finalize_env_probe_sweep_unwritable' => '[finalize-env] Could not clear the exposure-check leftovers (reason: :reason) - please clean up storage/installer manually.',
    'log_finalize_env_probe_sweep_partial' => '[finalize-env] Could not delete :failed exposure-check leftover file(s) (:removed removed) - please clean up storage/installer manually.',
    'log_finalize_env_probe_sweep_done' => '[finalize-env] Exposure-check leftovers cleared (:removed file(s))',
    'log_reset_probe_sweep_unwritable' => '[reset] Could not clear the exposure-check leftovers (reason: :reason) - please clean up storage/installer manually.',
    'log_reset_probe_sweep_partial' => '[reset] Could not delete :failed exposure-check leftover file(s) (:removed removed) - please clean up storage/installer manually.',
    'log_worker_client_ip' => 'Request IP: :ip',
    'log_worker_rejected_busy' => 'Another request is already running the installation, so this one will not start.',
    'log_worker_lock_acquired' => 'This request now holds the installation.',
    'log_php_binary_path_rejected' => 'The PHP path :path has a form that cannot be used, so the default php is used instead. (Paths with spaces or special characters are not accepted.)',

    // Error Messages - Worker (Cache)
    'error_cache_clear_failed' => 'Cache clearing failed',
    'error_config_cache_failed' => 'Configuration cache build failed',
    'error_static_publish_failed' => 'Static bootstrap resource publishing failed (site still works via API fallback)',

    // Log Messages - Worker (Cache)
    'log_cache_clear_success' => 'Cache clearing completed',
    'log_config_cache_success' => 'Configuration cache built',
    'log_static_publish_success' => 'Static bootstrap resources published',

    // Error Messages - Worker (Settings JSON)
    'error_settings_json_failed' => 'Settings file creation failed',

    // Log Messages - Worker (Settings JSON)
    'log_creating_settings' => 'Creating settings JSON files...',
    'log_settings_json_category_created' => '  - :category settings file created',
    'log_settings_json_category_skipped' => '  - :category settings file skipped (no defaults)',
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
    'log_storage_link_created' => 'Created the public file link (public/storage).',
    'log_storage_link_skipped' => 'Could not create the public file link (public/storage) — public files will be served through the application instead (installation is unaffected).',
    'log_deploy_ledger_seeded' => 'Recorded the update baseline for the server configuration fragments (deploy/).',
    'log_deploy_ledger_seed_skipped' => 'Could not record the update baseline for the server configuration fragments (deploy/) — the next core update will treat those fragments conservatively (installation is unaffected).',
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
    'abort_by_user' => '[Aborted] User aborted installation. (Current task: :task)',
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
    'error_installed_flag_write_failed' => 'Could not write the installation completion marker (:path). Check write permissions on the storage directory.',
    'error_binary_probe_unavailable' => 'This server restricts external command execution, so the path cannot be verified. Installation proceeds without a CLI, so you may leave this blank.',
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
    'installation_not_controllable_message' => 'This window cannot resume or stop the installation that is currently running. Please continue in the window where the installation was started. If that window is gone, wait until the installation finishes or stops on its own, then return here.',
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
    'confirm_go_to_settings' => 'Do you want to go back to the settings page?',
    'confirm_go_to_settings_simple' => "Do you want to go back to the settings page?\n\nInstallation state will be reset and all tasks will start from the beginning.\n\n⚠️ Database tables will NOT be deleted automatically.\nPlease clean up manually using phpMyAdmin if needed.",
    'confirm_go_to_settings_title' => 'Go to Settings Page',
    'confirm_go_to_settings_desc' => "Installation state will be reset and all tasks will start from the beginning.\n\n⚠️ Database tables will NOT be deleted automatically. Please clean up manually using phpMyAdmin if needed.",
    'confirm_go_to_settings_btn' => 'Go',
    'reset_db_checkbox_label' => 'Reset database (delete created tables)',
    'error_state_reset_failed' => "Failed to reset state.\n\n:message\n\nPlease refresh the page and try again.",
    'confirm_force_go_to_settings' => "An error occurred while resetting state.\n\nDo you still want to go to the settings page?",
    'view_error_details' => 'View error details',
    'view_command_output' => 'View command output',
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
@apache_setenv(\'no-gzip\', \'1\');

[Shared Hosting]
Contact your hosting provider about SSE (Server-Sent Events) support.
Firewalls or proxies may be blocking long-lived HTTP connections.',

    // Installation mode (SSE / polling)
    'installation_mode_label' => 'Installation Mode',
    'installation_mode_sse_title' => 'Real-time Streaming (SSE) — Recommended',
    'installation_mode_sse_desc' => 'The server streams progress in real time. Fastest and most reliable in standard environments.',
    'installation_mode_polling_title' => 'Compatibility Mode (Polling)',
    'installation_mode_polling_desc' => 'Use this when SSE connection errors occur behind an Nginx proxy, shared hosting, etc. Status is polled every second.',
    'sse_fallback_confirm' => "SSE connection failed.\n\nWould you like to retry in compatibility mode (polling)?",
    'sse_probe_incompat_use_polling' => "SSE real-time connection is not available in this environment.\n\nProceed with installation in compatibility mode (polling)?\n\n[OK] Start installation in polling mode\n[Cancel] Return to start screen",
    'start_installation_button' => 'Start Installation',

    // Step 4 dependency warnings
    'dependency_warning_title' => 'Missing Dependencies Detected',
    'dependency_warning_description' => 'The following extensions are required. Click "Auto-select required items" to resolve them in one click.',
    'dependency_auto_select' => 'Auto-select required items',
    'dependency_missing_tooltip' => 'Please resolve dependencies first',
    'dependency_precheck_failed' => 'Dependency precheck failed — return to Step 4 and select the missing modules/plugins',

    // Step 3 existing database detection
    'db_existing_g7_badge' => 'Existing G7 installation detected',
    'db_existing_foreign_badge' => 'Foreign data detected',
    'db_existing_mixed_badge' => 'Mixed G7 + foreign tables',
    'db_existing_g7_title' => 'Existing G7 Installation Detected',
    'db_existing_foreign_title' => 'Foreign Data Detected',
    'db_existing_mixed_title' => 'Mixed Data Detected',
    'db_existing_generic_title' => 'Existing Tables Detected',
    'db_existing_g7_desc' => 'The selected database already contains a G7 installation. Forcing the install will drop the existing G7 tables that start with the table prefix you entered. Back up first.',
    'db_existing_foreign_desc' => 'The selected database contains unknown tables. Forcing the install will only drop tables that start with the table prefix you entered; tables with a different prefix are preserved. Back up first.',
    'db_existing_mixed_desc' => 'The selected database contains a mix of G7 and other tables. Forcing the install will only drop tables that start with the table prefix you entered; tables with a different prefix are preserved.',
    'db_existing_generic_desc' => 'The selected database contains existing tables. Forcing the install will only drop tables that start with the table prefix you entered.',
    'db_existing_tables_list' => 'Detected tables (up to 20):',
    'db_backup_guide' => 'Backup command example (verify host/user/database before running):',
    'db_backup_confirmed' => 'I have backed up and agree that existing tables starting with my table prefix will be dropped',
    'error_db_cleanup_consent_required' => 'You must consent to dropping existing tables before proceeding to the next step.',
    'log_db_cleanup_skipped' => 'Skipping existing table cleanup (no action)',
    'log_db_cleanup_empty' => 'No existing tables. Skipping cleanup.',
    'log_db_cleanup_dropping' => 'Dropping :count existing tables...',
    'log_db_cleanup_done' => 'Dropped :count existing tables.',
    'error_db_cleanup_failed' => 'Failed to clean up existing database tables',

    // Dark Mode
    'toggle_theme' => 'Toggle Theme',

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
    'vendor_mode_auto_status_inprocess' => 'Automatic selection is unavailable on this server — the bundled package is used.',
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
    'vendor_mode_change_later_admin' => 'You can change this after installation in the admin screen (Settings › System).',
    'vendor_mode_change_later_html' => 'You can change this later via <code>php artisan core:update --vendor-mode=...</code>.',

    // Installation stuck detection
    // Server returned a response with no body (gnuboard/g7#62)
    'error_empty_server_response' => 'The server returned an empty response. The response may have failed to build because of the server configuration or characters used in the account name or path. Please check storage/logs/installation.log.',
    'error_invalid_server_response' => 'The server response could not be parsed. It may contain an error message or warning mixed into the output. Please check storage/logs/installation.log.',
    'error_polling_response_invalid' => 'Could not read the installation progress. The server is not returning a correctly formatted response. Please check storage/logs/installation.log and try again.',
    'error_installation_stuck_detail' => 'The installation task may still be running. Retry resumes from the unfinished step.',
    'error_installation_stuck' => 'Installation has not responded for :seconds seconds. The server worker may be stuck running a command. Please refresh and retry. If the problem persists, check storage/logs/installation.log.',
    'extension_load_failed' => 'Failed to load extension list.',
    'scan_directory_listing_unavailable' => 'Directory listing (scandir) is disabled on this server, so bundled extensions cannot be read. Please unblock that function in your hosting settings.',
    'scan_failed_see_log' => 'Reading the bundled extensions failed. See storage/logs/installer.log for details.',
    'db_existing_check_failed' => 'The connection succeeded, but the existing table list could not be read. This database may already contain tables — please check it directly.',
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
    'dep_version_required' => 'Required version',
    'dep_version_available' => 'Available version',
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
    'error_worker_exception' => 'Installation worker exception',
    'error_worker_terminated' => 'The installation task was terminated by the server (out of memory, execution time limit, and so on).',

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
    'rollback_migrate_range_unknown' => 'Could not determine which migrations to roll back, so the rollback was skipped. Please check the database directly.',
    'rollback_migrate_nothing_applied' => 'This installation applied no migrations, so there is nothing to roll back.',
    'rollback_seed_no_config' => 'No DB configuration found. Skipping seed rollback.',
    'rollback_seed_no_target_tables' => 'No seed tables with this installation prefix were found, so there is nothing to roll back.',
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
    'error_settings_dir_create_failed' => 'Could not create the settings directory (:path). Please check write permissions on storage/app.',
    'error_settings_json_write_failed' => 'Could not save the settings file (:path). Please check write permissions on storage/app/settings.',
    'rollback_env_flag_read_failed' => 'Could not read the .env file, so the installation-completed marker was not removed. Please check file permissions.',
    'rollback_env_flag_write_failed' => 'Could not write to the .env file, so the installation-completed marker was not removed. Please check file permissions.',
    'rollback_installed_flag_remove_failed' => 'Could not delete the g7_installed file. Please check file permissions.',
    'rollback_complete_flag_partial' => 'The installation-completed markers were not fully removed (:details). While they remain, the installer will not reopen.',
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
    'installer_disabled_completed' => 'The installer is disabled because installation has been completed.',
    'manual_cmd_dependency_precheck' => 'Go back to extension selection, include the required extensions, and try again.',
    'manual_cmd_db_cleanup' => 'Drop the existing tables with your database tool and try again.',
    'manual_cmd_settings_json' => 'settings.json is generated automatically. Please retry the installation.',

    // state-management.php i18n keys
    'state_reset_requested' => 'Requested navigation to settings page.',
    'state_reset_db_notice' => 'DB is not automatically reset. Please clean up manually if needed.',
    'state_reset_discarded_corrupt' => 'The installation progress file was damaged and has been cleared as part of the reset. The damaged copy is kept for diagnostics.',
    'state_save_failed' => 'Failed to save state file.',
    'state_reset_completed' => 'Installation state has been reset. (Moving to Step :step)',
    'abort_api_requested' => '[Abort API] Abort requested - user requested installation abort',
    'abort_api_current_status' => '[Abort API] Current installation_status: :status',
    'abort_api_current_task' => '[Abort API] Current current_task: :task',
    'abort_api_completed_count' => '[Abort API] Completed task count: :count',
    'abort_api_already_completed' => 'Installation is already completed and cannot be aborted.',
    'abort_api_already_aborted' => 'Installation is already aborted.',
    'abort_api_not_running' => 'No installation to abort.',
    // Log only — the user-facing key stays placeholder-free (it is called without params).
    'abort_api_not_running_log' => 'No installation to abort. (current status: :status)',
    'abort_user_requested' => 'User requested installation abort.',
    'abort_api_worker_retreated' => 'The running task has stepped down (waited :seconds seconds). Starting rollback.',
    'abort_api_worker_wait_timeout' => 'The running task did not step down within :seconds seconds; starting rollback without waiting any longer.',
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
    'vendor_bundle_backup_revert_failed' => 'Reverting the backup failed, so some originals remain in the backup location (:items). Location: :path',
    'vendor_bundle_restore_failed' => 'Restoring vendor from the backup failed. The originals remain at :path — please move them back manually.',
    'finalize_rejected_notice' => 'Installation finished, but the finalization step was rejected. The installer is still open and temporary installation files remain — please refresh the page to retry finalization.',
    'file_setup_not_writable' => 'The file below exists but the web server cannot write to it. The final step of installation must modify this file, so grant write permission and check again.',
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

    // Shell-free permission guidance (W7) — the FTP-client procedure for accounts without a shell.
    // Ownership change (chown) is deliberately absent from this version; it is limited to permission values.
    'permission_guide_ftp_summary' => 'Change it with an FTP client',
    'permission_guide_shell_summary' => 'Change it with a command (if you have SSH)',
    'permission_guide_ftp_step_connect' => 'Connect over FTP and locate :path.',
    'permission_guide_ftp_step_open' => 'Right-click the item and open File permissions (Properties).',
    'permission_guide_ftp_step_value_directory' => 'Enter :value as the numeric value and turn on "Apply to subdirectories".',
    'permission_guide_ftp_step_value_file' => 'Enter :value as the numeric value.',
    'permission_guide_ftp_step_value_windows' => 'Open folder Properties → Security and allow "Modify" for the web server account (IIS_IUSRS), applying it to child items.',
    'permission_guide_ftp_step_repeat' => 'Repeat for the remaining items (:paths).',
    'permission_guide_ftp_step_recheck' => 'Press [Check again] below.',
    'permission_guide_ftp_fallback' => 'If write access still is not granted, try :value. On hosting where the web server runs as a different account than the file owner, :primary grants write access to the owner only.',
    'permission_guide_ftp_note_windows' => '※ Windows servers do not use numeric permission values. If your FTP client has no permission dialog, the same setting is usually available in your hosting control panel file manager.',

    // Shell-free procedure for creating a missing required file (`.env`). Unlike the sibling
    // branches (permission / ownership) this case previously offered a shell copy command only,
    // which cannot be run on hosting without a shell (measured in G8).
    'required_files_guide_ftp_step_connect' => 'Connect over FTP and open the folder where Gnuboard7 was uploaded.',
    'required_files_guide_ftp_step_download' => 'Download :source to your computer.',
    'required_files_guide_ftp_step_rename' => 'Rename the downloaded file to :target.',
    'required_files_guide_ftp_step_upload' => 'Upload the renamed file back into the same folder.',
    'required_files_guide_ftp_step_recheck' => 'Press [Check again] below.',
    'required_files_guide_ftp_note' => '※ The file name starts with a dot (.), so your FTP client may hide it. Turn on "Show hidden files".',

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

    // 자산 URL 방식 (이슈 #486)
    'asset_url_mode' => 'Asset file serving mode',
    'asset_url_mode_extension' => 'Use file extensions (recommended)',
    'asset_url_mode_extensionless' => 'No file extensions',
    'asset_url_mode_help' => 'Detected automatically from your server setup. A static-file optimization rule (e.g. nginx location ~* \\.(js|css|json|png|jpg|jpeg|gif|ico|svg|woff2?)$) can intercept extension URLs and make pages fail to load; choose no extensions in that case.',
    'asset_url_mode_detected_extension' => 'Detected: file extensions work fine on this server.',
    'asset_url_mode_detected_extensionless' => 'Detected: a static-file optimization rule (e.g. nginx location ~* \\.(js|css|json|...)$) intercepts extension URLs, so no extensions was selected.',
    'asset_url_mode_detected_unavailable' => 'Could not detect the serving mode — neither the extension nor the extensionless probe URL responded. This is usually not an asset-mode issue but a sign that the app is not yet responding at this URL (PHP/routing) or that a proxy or security rule (e.g. CSP) is blocking the probe requests. This item does not block installation, so you can continue; if pages look blank afterward, switch to no extensions under Admin > Settings > General.',
    'asset_url_mode_checking' => 'Checking…',
    'asset_url_mode_unknown' => 'Unknown',

    // Web exposure check (B-4 · D3 · D21)
    'web_exposure' => 'Web Exposure Check',
    'web_exposure_checking' => 'Checking…',
    'web_exposure_safe' => 'Protected',
    'web_exposure_exposed' => 'Exposed',
    'web_exposure_exposed_detail' => 'Configuration files and internal directories are directly readable from the web. Installing in this state would expose sensitive data such as database credentials, so installation cannot proceed.',
    'web_exposure_exposed_list_label' => 'Addresses confirmed readable from outside',
    'web_exposure_exposed_remedy' => 'Point the document root at the public/ directory, or make sure the bundled root .htaccess is honored (Apache AllowOverride), then click [Recheck].',
    'web_exposure_server_guide_label' => 'Where the protection settings live on this server',
    'web_exposure_server_guide_apache' => 'The top-level .htaccess shipped with the release does this. Your server may have .htaccess support turned off (AllowOverride), or the file may not have been uploaded. Check, then press [Recheck].',
    'web_exposure_server_guide_litespeed' => 'The top-level .htaccess shipped with the release does this. Check that LiteSpeed has .htaccess support enabled, then press [Recheck].',
    'web_exposure_server_guide_iis' => 'The top-level web.config shipped with the release does this. Check that the URL Rewrite module is installed, then press [Recheck]. Without that module the site fails to open with a 500 error.',
    'web_exposure_server_guide_nginx' => 'nginx does not read per-directory configuration files, so this must go into the server configuration. Include deploy/nginx/g7-layout2.conf from the release in your server block, reload the configuration, then press [Recheck].',
    'web_exposure_server_guide_other' => 'This server may not read per-directory configuration files. Apply the snippet from the release deploy/ directory to your server configuration, reload it, then press [Recheck]. If no snippet fits your server, pointing the document root at public/ is the surest route.',
    'web_exposure_unknown' => 'Could not determine',
    // The lead sentence differs per cause — see the ko table for why a single shared
    // sentence was wrong in four of the seven branches.
    'web_exposure_unknown_detail_control' => 'The address this check uses as its baseline is not reachable, so this measurement cannot be trusted. Find out why the addresses under "Addresses that should be reachable but are not" fail first, then open the check targets yourself in a new browser tab.',
    'web_exposure_unknown_detail_unmeasured' => 'Some of the check targets could not be prepared, so the automatic verdict is withheld. The prepared targets were measured with real responses, but if any target was never measured at all, this check does not claim "protected". Review the items below and open the addresses yourself.',
    'web_exposure_unknown_detail_probe' => 'The probe requests either did not reach the server, or came back with a response that cannot tell us whether the file was served. Open the addresses below in a new browser tab and check them yourself.',
    'web_exposure_unknown_detail_shape' => 'We could not learn what this server returns for an address with no file behind it, so we cannot tell whether the responses from the check targets were files or pages. Open the addresses below in a new browser tab and check them yourself.',
    'web_exposure_unknown_detail_no_targets' => 'No check target addresses could be built on this server at all. Follow the guidance below and check them yourself.',
    'web_exposure_unknown_detail_mixed' => 'The exposure status could not be determined automatically — there is more than one cause (see the items below). Open the addresses below in a new browser tab and check them yourself.',
    'web_exposure_manual_criteria' => 'Judge by what the page shows, not by the status code. If even one line of the file\'s own contents (configuration values, source code) is visible, that is a failure. An error page (404 or 403) is fine — and so is the installation notice or any normal site page, because that means the file itself was not served.',
    'web_exposure_absent_control_label' => 'Comparison address (an address that does not exist on this server)',
    'web_exposure_absent_control_hint' => 'There is no file behind this address. If a check target above looks the same as this address, that file was not served either, which is fine.',
    'web_exposure_manual_list_label' => 'Addresses to check manually',
    'web_exposure_control_failed_label' => 'Addresses that should be reachable but are not',
    'web_exposure_control_failed_hint' => 'This address must be reachable in a correct deployment. Failing to reach it does not mean the site is well protected. There are three possible causes. (1) The web server is not forwarding requests to the application (Apache without mod_rewrite, or .htaccess being ignored) — if you finish the installation in this state, almost every address except the front page will fail. Contact your server administrator, or install with the document root pointed at the public folder. (2) The base address the installer used for the check differs from the real project address (for example when the project folder itself is named public) — in that case the site itself is fine, so open the addresses listed below in your browser, confirm that no file contents are shown (looking the same as the comparison address is fine), and then continue. (3) A reverse proxy strips the leading part of the address (the prefix) before forwarding (for example the proxy receives /g7/install while the server sees /install) — configure the proxy to send that prefix in the X-Forwarded-Prefix header and the check address will be corrected.',
    'web_exposure_unmeasured_label' => 'Items that could not be checked (setup failed — verify manually)',
    'web_exposure_unmeasured_reason_not_writable' => 'the storage folder is not writable, so the probe file could not be created',
    'web_exposure_unmeasured_reason_rate_limited' => 'checked too many times in a short period, so this run was skipped (try again shortly)',
    'web_exposure_unmeasured_reason_no_random' => 'this server cannot generate random values, so the probe file could not be created',
    'web_exposure_unmeasured_reason_no_marker' => 'no usable marker was found in that file, so this family could not be measured — open the addresses below and check them yourself',
    'web_exposure_unmeasured_reason_missing' => 'The file to check is not present, so this series could not be measured - open the address below to check it yourself',
    'web_exposure_unmeasured_reason_missing_env' => 'the target file does not exist — if you renamed .env.example to .env, that family may actually be reachable, so verify it yourself',
    'web_exposure_unmeasured_reason_missing_install_assets' => 'The installer asset file is missing, so the baseline control could not be built - check that the release was uploaded completely (the public/install/assets folder)',
    'web_exposure_unmeasured_reason_not_deployed' => 'the files of this family have not been deployed yet (installation flow that unpacks the dependency archive later), so it could not be measured this time — after finishing the installation, open that path in a browser and make sure no file contents are shown',
    // The check could not clean up after itself. This does not affect the verdict (the
    // per-window cap bounds the total), but the operator has to know files will pile up.
    'web_exposure_unmeasured_reason_not_listable' => 'this server cannot read directory listings, so the temporary files used by the check cannot be cleaned up automatically — after finishing the installation, delete any leftover check files in the storage folder yourself',
    // The proxy-supplied prefix header was discarded as unsafe. The base address may then
    // lie outside the real mount, so the verdict cannot be trusted — the value itself is
    // never shown on screen or written to logs.
    'web_exposure_unmeasured_reason_forwarded_prefix_rejected' => 'the address prefix sent by the reverse proxy (X-Forwarded-Prefix) was ignored because it is not in an allowed form — the base address used for this check may differ from the real address, so fix the proxy to send that header as a plain path (/prefix) only, or open the addresses below yourself',
    'web_exposure_manual_hint' => 'If any address shows raw file contents, stop the installation and fix the document root or protection rules (.htaccess) first.',
    'web_exposure_manual_confirm' => 'I have checked every address above and confirmed that none of them shows file contents.',
    'web_exposure_manual_confirm_no_list' => 'No addresses could be prepared for this check. I have opened `.env`, `storage/` and `vendor/` directly in the browser and confirmed that none of them shows file contents.',
    'log_web_exposure_verdict' => '[web-exposure] Web exposure check verdict: :verdict',
    'log_web_exposure_blocked' => '[web-exposure] A next-step request arrived while the verdict was "exposed"; the server refused to proceed',
    'error_web_exposure_blocked' => 'Installation cannot proceed while the web exposure check reports "Exposed". Fix the document root or the protection rules, then click [Recheck].',
    'web_exposure_verdict_confirmed' => 'undetermined — verified manually by the operator',
    'web_exposure_verdict_unreported' => 'not reported',
    'web_exposure_verdict_pending' => 'not yet judged (the check had not finished)',
    'storage_link_unavailable_detail' => 'This server cannot create symbolic links, so public files (attached images and the like) are served through the read-only delivery address of the application instead of a static path. Installation continues normally and no action is required.',
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
    'error_env_value_line_break' => 'Line breaks are not allowed.',
    'error_env_value_invalid_url' => 'This is not a valid URL (:value). Enter an address starting with http:// or https://.',
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
    'warning_php_binary_not_cli' => 'PHP :version detected (:path) — not the CLI build (:sapi). Installation tasks cannot run with this binary; specify a CLI executable path.',
    'success_php_binary_version' => ':path — PHP :version ✓',
    'error_php_path_empty' => 'PHP binary path is empty.',
    'error_php_path_not_exists' => 'File does not exist: :path',
    'error_php_exec_failed' => 'PHP execution failed: :path',
    'error_php_binary_path_not_allowed' => 'This PHP path format cannot be used (:path). Enter the absolute path of an executable on this server only — options (starting with -), relative paths, .., network paths (\\\\server\\share or //server/share) and scheme:// forms are not allowed.',
    'error_composer_binary_path_not_allowed' => 'This Composer path format cannot be used (:path). Enter the absolute path of a composer executable on this server — the file name must be a composer variant (composer, composer.phar, composer2.phar and so on); other .phar names, network paths (\\\\server\\share or //server/share) and scheme:// forms are not allowed. For multi-PHP environments use the "absolute-php-path absolute-composer-path" format.',
    'error_execution_mode_subprocess_unavailable' => 'This server cannot use the "run external commands" method. The required functions (exec, proc_open, shell_exec) are blocked, so no separate process can be started. Choose "Automatic" or "Install without CLI" instead.',
    'error_execution_mode_inprocess_unavailable' => 'This server cannot use the "install without CLI" method. That method extracts the bundled dependency archive (vendor-bundle.zip), but the archive is missing or the zip extension is unavailable to extract it.',
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

    // Execution mode judgment (A-1 — mode branch for CLI-less environments)
    'execution_mode_title' => 'Execution Method',
    'execution_mode_inprocess_notice' => 'Installing without CLI',
    'execution_mode_inprocess_detail' => 'External command execution is restricted on this server.',
    'execution_mode_reason_prefix' => 'Judgment basis:',
    'execution_mode_reason_functions' => ':functions blocked',
    'execution_mode_reason_php_probe' => 'PHP executable could not be verified',
    'execution_mode_changes_intro' => 'Installation proceeds as usual, but the following will differ:',
    'execution_mode_change_vendor' => 'Dependencies are installed from the bundled archive',
    'execution_mode_change_cron' => 'Scheduled tasks must be set up via URL calls',
    'execution_mode_change_update' => 'Core updates are applied with a button in the admin screen',
    'execution_mode_learn_more' => 'Learn more',
    'error_no_cli_and_no_bundle' => 'External command execution is restricted on this server and the bundled dependency archive (vendor-bundle.zip) was not found, so installation cannot proceed. Make sure you uploaded the full release archive as-is.',
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

    // Execution mode (Step 3 selector · D33 · W2) — what is saved is a "choice", default auto
    'execution_mode_card_title' => 'Execution Method',
    'execution_mode_card_description' => 'Choose how installation commands (creating the database, installing extensions, etc.) are run. You can change this later in the admin settings.',
    'execution_mode_auto_title' => 'Auto',
    'execution_mode_auto_description' => 'Detects the server: uses the command line when available, runs without CLI when it is blocked.',
    'execution_mode_auto_status' => 'Detected: :mode',
    'execution_mode_php_binary_substituted' => '(judged with the default php — the PHP path you entered cannot be used)',
    'vendor_bundle_hint_run_user' => '(running as: :user)',
    'vendor_bundle_hint_owner_same' => '(running as: :user, same owner)',
    'vendor_bundle_hint_owner_differs' => '— The process user (:user) differs from the directory owner (:owner). This happens when the account that uploaded the files is not the account the web server runs as. Follow the permission guidance on the requirements screen to grant write access to this directory, then try again.',
    'execution_mode_subprocess_title' => 'Command line',
    'execution_mode_subprocess_description' => 'Runs php artisan commands as separate processes (the default on standard servers).',
    'execution_mode_subprocess_status_ok' => 'Command line available',
    'execution_mode_subprocess_status_blocked' => 'Not available on this server',
    'execution_mode_inprocess_title' => 'Without CLI',
    'execution_mode_inprocess_description' => 'Runs commands inside the web request. For shared hosting where external commands are blocked; dependencies are installed from the bundled archive.',
    'execution_mode_inprocess_status_ok' => 'Bundled archive (vendor-bundle.zip) found',
    'execution_mode_inprocess_status_no_bundle' => 'vendor-bundle.zip not found — unavailable',
    'execution_mode_badge_default' => 'Default',
    'execution_mode_badge_cli' => 'CLI',
    'execution_mode_badge_no_cli' => 'Shared hosting',
    'execution_mode_label_subprocess' => 'Command line',
    'execution_mode_label_inprocess' => 'Without CLI',
    'execution_mode_source_explicit' => 'chosen manually',
    'execution_mode_source_detected' => 'auto-detected',
    'execution_mode_detected_subprocess' => 'This server can run command-line processes.',
    'execution_mode_detected_inprocess' => 'External command execution is restricted on this server; installation runs without CLI.',
    'execution_mode_vendor_fixed_notice' => 'This server cannot run Composer, so dependencies are installed from the bundled archive. This choice cannot be changed.',
    'vendor_mode_composer_status_inprocess' => 'Installing without CLI — unavailable',

    // Installation mode — stepwise (A-2 · W3)
    'installation_mode_stepwise_title' => 'Step by step',
    'installation_mode_stepwise_desc' => 'Runs one short request per step. Suits hosting with limited execution time, and resumes after a page refresh.',
    'installation_mode_unavailable_inprocess' => 'No CLI environment — unavailable',
    'installation_mode_unavailable_composer' => 'Unavailable with Composer installation',
    'installation_mode_stepwise_composer_reason' => 'Installing dependencies with Composer makes a single step too long to finish within one request. Choose the bundled archive to use this method.',
    'installation_mode_forced_notice' => 'External command execution is restricted on this server, so installation runs step by step.',
    'stepwise_resume_notice' => 'Step-by-step installation resumes after a page refresh.',
    'stepwise_waiting_busy' => 'Waiting for the previous request to finish its step...',
    'stepwise_request_retry' => 'No response from the server — retrying (:attempt/:max)',
    'stepwise_step_progress' => ':done / :total steps',
    'abort_waiting_for_task' => 'Abort requested. We wait for the running step to finish so the rollback cannot collide with it — please leave this window open.',
    'stepwise_task_elapsed' => 'Running :name (:elapsed s elapsed)',
    'stepwise_task_unnamed' => 'the current step',
    'stepwise_long_task_notice' => ':name is still running (:elapsed s elapsed). This step can take a while on the server, so please leave this window open.',
    'log_truncated_notice' => ':count older lines were folded away on screen — the full record is in storage/logs/installation.log',
    'log_rotated_notice' => 'The previous record grew large and was moved to installation.log.1 — this file continues from there',
    'error_stepwise_request_failed' => 'The server is not responding to step requests. Press [Retry] in a moment to continue from the interrupted step. If it keeps happening, check storage/logs/installation.log.',
    'error_polling_request_failed' => 'The server is not responding to installation status requests. Wait a moment and reload the page to continue. If this keeps happening, check storage/logs/installation.log.',
    'error_stepwise_token_invalid' => 'The step-by-step token is invalid. Refresh the installer page and start again.',
    // "No stored token" and "wrong token" need different actions — refreshing does not fix the former (round 11 #A10)
    'error_stepwise_token_missing' => 'The server no longer holds the information needed to resume this installation. Please start the installation over.',
    // Finalize endpoint guard (round 11 #A1)
    'error_finalize_method_not_allowed' => 'The installation cannot be finalized through this kind of request.',
    'error_finalize_not_completed' => 'The installation has not finished yet, so it cannot be finalized.',
    // The installation lock is still within its budget (round 11 #A2 / #A4, wording replaced by D7)
    //
    // The old wording asserted "already running in another window", which is only true while the
    // holder is alive. A fatal exit (disk full, OOM, signal) cannot record the release, so a dead
    // holder keeps the lock. In the field (2026-09-12) that budget was 600s, so the screen pointed
    // at a window that no longer existed for ten minutes and the only way out was "Start over".
    'error_installation_lock_held' => 'A previous run is still marked as in progress. If another window is installing, it continues there. If that window was closed or stopped by an error, this will resume automatically in :seconds seconds.',
    // Countdown while waiting (D7) — actually counts down the ":seconds" the notice promised
    'installation_lock_wait_countdown' => 'Resuming automatically in :seconds seconds.',
    // The wait is over and the screen picks it up again (D7)
    'installation_lock_wait_resuming' => 'Resuming…',
    // Response could not be built (round 11 #A9) — an empty body makes the screen report a network error instead
    'error_response_encoding_failed' => 'The server could not build a response. Please refresh the installer page.',
    // The task finished but its completion record could not be saved (round 11 #A5)
    'error_task_marker_not_persisted' => 'Could not save the completion record for :task. Check free disk space and write permission on the storage folder.',
    // Abort reasons — the server already distinguishes these; the aborted screen now translates them (round 11 #A12)
    'abort_reason_user' => 'You stopped the installation.',
    'abort_reason_connection_lost' => 'The connection to the installer page was lost, so the installation stopped.',
    'abort_reason_label' => 'Reason',
    'error_stepwise_mode_mismatch' => 'This installation was not started in step-by-step mode.',
    'error_stepwise_not_running' => 'No installation is in progress. (current status: :status)',
    'error_stepwise_unexpected_response' => 'The server sent an unexpected response. Refresh the installer page to continue from the interrupted step.',
    'error_monitor_script_not_loaded' => 'The installation progress screen could not be loaded. Please refresh the page.',
    'log_installation_mode_forced_stepwise' => 'External command execution is restricted on this server; proceeding step by step.',
    'log_installation_mode_downgraded_sse' => 'Installing dependencies with Composer takes longer than one request allows, so the installation proceeds with real-time streaming.',
    'log_execution_mode_resolved' => 'Execution method: :mode (:source)',

    // Skip a failed step (W3 [Skip and continue])
    'skip_and_continue' => 'Skip and continue',
    'skip_task_confirm' => 'Skip installing this extension and continue with the next step? You can install it again from the admin screen after installation.',
    'skip_task_done' => 'The step was skipped.',
    'skip_task_not_failed' => 'There is no failed step to skip.',
    'skip_task_not_allowed' => 'This step is required and cannot be skipped.',
    'log_task_best_effort_skipped' => ':task — failed in an earlier run but is not required, so it is being passed over.',
    'log_dependency_skipped_after_install' => ':dependent depends on :skipped, which was skipped. It is already installed and cannot be undone — review it after installation.',
    'log_task_skipped_by_user' => ':task — skipped by the operator.',
    // Failure screen "go back" — clears the installation state and returns to Step 3 (W3 mockup label)
    'failure_go_back' => 'Start over',

    // Progress-mode mismatch — an installation fixed to step-by-step never uses the streaming worker (R1)
    'error_sse_mode_mismatch' => 'This installation is running step by step and cannot continue in streaming mode.',
    // Entry precondition for the streaming worker (13th round, security #5) — same rule as its sibling entry (run-task)
    'error_sse_not_running' => 'No installation is in progress. (current status: :status)',

    // Origin check for state-changing requests (13th round, security #4)
    'error_cross_origin_request' => 'Requests sent from another site are not accepted. Please try again from the installer screen.',

    // Call limit for unauthenticated probe requests (13th round, security #6 and #7)
    'error_probe_rate_limited' => 'Too many check requests. Please try again in a moment.',

    // Database connection failure reasons (classified only as far as the remedy differs — 13th round, security #6)
    'error_db_failure_credentials' => 'the username or password is incorrect',
    'error_db_failure_unknown_database' => 'no database with that name was found',
    'error_db_failure_unreachable' => 'the database server could not be reached (check the address, port and firewall)',
    'error_db_failure_denied' => 'this account has no access to that database',
    'error_db_failure_generic' => 'the connection failed (see the installation log for details)',

    // Runtime budget check (recommendation — never blocks the installation, 13th round, environment #8)
    'php_limits' => 'PHP runtime budget',
    'php_limits_unknown' => 'The PHP runtime budget (memory and time) could not be determined.',
    'php_limits_detail' => 'Memory limit :memory / execution time limit :time s (:recommended or more recommended)',

    // Configuration difference between the web PHP and the CLI PHP (13th round, environment #7)
    'php_cli_extensions_missing' => 'The CLI PHP is missing these extensions: :extensions — it is using a different php.ini than the web server.',

    // The path the operator typed is not a CLI binary (13th round, security #7)
    'error_php_binary_not_cli_rejected' => ':path is a :sapi binary, not the CLI one. It cannot run the installation tasks, so please point to a CLI binary.',

    // Bundled dependency archive integrity check (vendor-bundle.zip)
    'log_vendor_bundle_integrity_failed' => 'Bundled archive integrity check failed: :errors',
    'error_vendor_bundle_integrity_failed' => 'vendor-bundle.zip failed the integrity check: :errors',

    // Installer runner (artisan-executor)
    'error_subprocess_unavailable' => 'This server cannot run external commands (exec/proc_open blocked).',
    'error_subprocess_signal_terminated' => 'The command was terminated by signal :signal. Check the memory limit (OOM) or the execution time limit.',
    'error_inprocess_vendor_missing' => 'Dependencies (vendor/) are not installed yet, so the command cannot run.',
    'error_inprocess_boot_failed' => 'The application could not be started: :error',
];
