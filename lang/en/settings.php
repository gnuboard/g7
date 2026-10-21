<?php

return [
    // Settings related messages
    'fetch_success' => 'Settings retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve settings.',
    'save_success' => 'Settings saved successfully.',
    'save_failed' => 'Failed to save settings.',
    'update_success' => 'Settings updated successfully.',
    'update_failed' => 'Failed to update settings.',
    'delete_success' => 'Settings deleted successfully.',
    'delete_failed' => 'Failed to delete settings.',
    'save_error' => 'An error occurred while saving settings.',
    'update_error' => 'An error occurred while updating settings.',
    'cache_clear_success' => 'Cache cleared successfully.',
    'cache_clear_failed' => 'Failed to clear cache.',
    'cache_clear_error' => 'An error occurred while clearing cache.',
    'optimize_success' => 'System optimized successfully.',
    'optimize_failed' => 'Failed to optimize system.',
    'optimize_error' => 'An error occurred while optimizing system.',
    'backup_success' => 'Database backup started successfully.',
    'backup_failed' => 'Failed to start database backup.',
    'backup_error' => 'An error occurred while backing up database.',
    'database_backup_unavailable' => 'Database backup is not available yet. Use the settings backup feature to back up your settings.',
    'backup_path_required' => 'Please enter a backup path.',
    'restore_success' => 'Settings restored successfully.',
    'restore_failed' => 'Failed to restore settings.',
    'restore_error' => 'An error occurred while restoring settings.',
    'save_individual_failed' => 'Settings save failed: :error',
    'static_cache_status_loaded' => 'Startup file status loaded.',
    'queue_status_loaded' => 'Queue worker status loaded.',
    'static_cache_republished' => 'Startup files were rebuilt.',
    'static_cache_republish_failed' => 'Startup files could not be rebuilt. The site keeps working normally.',

    // App key related messages
    'invalid_password' => 'Password does not match.',
    'password_required' => 'Please enter your password.',
    'app_key_regenerated' => 'Application key has been successfully regenerated.',
    'app_key_regenerate_failed' => 'Failed to regenerate application key.',
    'app_key_regenerate_warning' => 'Regenerating the key will invalidate all sessions. Do you want to continue?',

    // System info related
    'seconds' => 's',

    // Driver labels (resolved per active language pack by DriverRegistryService)
    'drivers' => [
        'storage' => [
            'local' => 'Local',
            's3' => 'Amazon S3',
        ],
        'public_asset' => [
            'none' => 'Disabled (streaming)',
            'public' => 'Public disk',
            's3' => 'Amazon S3',
        ],
        'cache' => [
            'file' => 'File',
            'redis' => 'Redis',
        ],
        'session' => [
            'file' => 'File',
            'database' => 'Database',
            'redis' => 'Redis',
        ],
        'queue' => [
            'sync' => 'Sync',
            'database' => 'Database',
            'redis' => 'Redis',
        ],
        'log' => [
            'single' => 'Single File',
            'daily' => 'Daily File',
        ],
        'websocket' => [
            'reverb' => 'Laravel Reverb',
        ],
        'mail' => [
            'smtp' => 'SMTP',
            'mailgun' => 'Mailgun',
            'ses' => 'SES (Amazon)',
        ],
        'search' => [
            'mysql-fulltext' => 'MySQL Full-Text',
        ],
    ],

    // Driver connection test messages
    'driver_test_success' => 'All driver connection tests passed.',
    'driver_test_partial' => 'Some driver connection tests failed.',
    'driver_test_error' => 'An error occurred while testing driver connections.',
    'unknown_driver' => 'Unknown driver.',

    // Outbound proxy connection test messages
    'outbound_proxy_test_success' => 'Connected through the proxy. External services will see this IP address.',
    'outbound_proxy_test_failed' => 'Could not connect through the proxy. Check the address and the proxy server status.',
    'outbound_proxy_test_invalid_url' => 'The proxy address format is invalid.',
    'outbound_proxy_test_no_lookup_url' => 'No egress IP lookup target is configured, so the address could not be determined.',

    // S3 test messages
    's3_test_success' => 'Successfully connected to S3 bucket.',
    's3_test_failed' => 'Failed to connect to S3 bucket.',
    's3_missing_config' => 'S3 configuration is missing. (bucket, region, access key, secret key)',
    's3_sdk_missing' => 'AWS SDK is not installed.',
    's3_adapter_missing' => 'S3 storage adapter (league/flysystem-aws-s3-v3) is not installed.',
    's3_bucket_not_found' => 'S3 bucket not found.',
    's3_access_denied' => 'Access to S3 bucket was denied.',
    's3_invalid_credentials' => 'S3 credentials are invalid.',

    // Redis test messages
    'redis_test_success' => 'Successfully connected to Redis server.',
    'redis_test_failed' => 'Failed to connect to Redis server.',
    'redis_extension_missing' => 'Redis PHP extension is not installed.',
    'redis_connection_failed' => 'Could not connect to Redis server.',
    'redis_auth_failed' => 'Redis authentication failed.',
    'redis_ping_failed' => 'No response from Redis PING.',

    // Memcached test messages
    'memcached_test_success' => 'Successfully connected to Memcached server.',
    'memcached_test_failed' => 'Failed to connect to Memcached server.',
    'memcached_extension_missing' => 'Memcached PHP extension is not installed.',
    'memcached_connection_failed' => 'Could not connect to Memcached server.',

    // Websocket test messages
    'websocket_test_success' => 'Successfully connected to Websocket server.',
    'websocket_test_failed' => 'Failed to connect to Websocket server.',
    'websocket_server_test_failed' => 'Failed to connect to the Websocket server-side (backend broadcast) endpoint. The client endpoint is reachable.',
    'driver_unusable_s3_adapter' => 'S3 storage adapter (league/flysystem-aws-s3-v3) is not installed.',
    'driver_unusable_redis_client' => 'Neither the phpredis extension nor the predis library is available.',
    'driver_unusable_memcached_extension' => 'The memcached PHP extension is not installed.',
    'websocket_invalid_host' => 'The Websocket host setting is invalid. Enter the address only — credentials (@) and protocols other than http/https are not allowed.',
    'websocket_connection_refused' => 'Could not connect to Websocket server. Please check if the server is running.',

    // Test mail related messages
    'invalid_email' => 'Invalid email address.',
    'test_mail_subject' => ':app_name Test Mail',
    'test_mail_body' => 'This is a test email from Gnuboard7. If you received this email, your mail settings are configured correctly.',
    'test_mail_sent' => 'Test email sent successfully.',
    'test_mail_failed' => 'Failed to send test email.',
    'test_mail_error' => 'An error occurred while sending the test email.',

    // Core update related messages
    'core_update' => [
        'check_success' => 'Update check completed.',
        'check_failed' => 'Update check failed.',
        'update_available' => 'A new update is available.',
        'no_update' => 'You are on the latest version.',
        'maintenance_mode_active' => 'System is under maintenance. Please try again later.',
        'invalid_github_url' => 'Invalid GitHub repository URL.',
        'download_failed' => 'Failed to download version :version.',
        'zip_extract_failed' => 'Failed to extract ZIP file.',
        'invalid_package' => 'Downloaded package is invalid.',
        'invalid_package_not_g7' => 'The specified directory is not a Gnuboard7 project. config/app.php file with version setting is required.',
        'composer_failed' => 'composer install failed.',
        'pending_path_create_failed' => 'Failed to create pending directory (:path): :error',
        'pending_path_not_writable' => 'Pending directory (:path) is not writable.',
        'downloading' => 'Downloading update...',
        'extracting' => 'Extracting...',
        'validating' => 'Validating package...',
        'running_composer' => 'Running composer install...',
        'step_check' => 'Checking for updates...',
        'step_validate_pending' => 'Validating environment...',
        'step_maintenance' => 'Enabling maintenance mode...',
        'step_download' => 'Downloading...',
        'step_backup' => 'Creating backup...',
        'step_apply' => 'Applying files...',
        'step_composer' => 'Running composer install...',
        'step_migration' => 'Running migrations...',
        'step_upgrade' => 'Running upgrade steps...',
        'step_composer_prod' => 'Running composer install in production directory...',
        'step_cleanup' => 'Cleaning up...',

        // File apply mode summary (Step 7)
        'apply_mode_incremental' => 'File apply: only files actually changed by the core were applied (:added new, :changed changed). All other files were left untouched as-is.',
        'apply_mode_incremental_prune_hint' => 'To also remove files deleted in the new version, re-run the same update with the `--prune` option.',
        'apply_mode_prune' => 'File apply: full overwrite plus cleanup of removed files was performed (--prune).',
        'apply_mode_fallback' => 'File apply: no backup available, so incremental apply was skipped and a full overwrite was performed. To preserve custom files, keep the backup enabled on future updates.',

        // GitHub API error messages
        'github_url_not_configured' => 'GitHub repository URL is not configured.',
        'github_api_failed' => 'Unable to connect to GitHub API.',
        'github_token_required' => 'This is a private repository. Please configure a GitHub access token.',
        'github_token_invalid' => 'GitHub access token is invalid or has insufficient permissions. (HTTP :status — :message)',
        'github_repo_not_found' => 'GitHub repository not found. (HTTP :status — :message)',
        'github_repo_not_found_no_token' => 'GitHub repository not found. If the repository is private, please configure an access token. (HTTP :status — :message)',
        'github_api_error' => 'GitHub API error occurred. (HTTP :status — :message)',
        'no_releases_found' => 'No releases found in the GitHub repository. (HTTP :status — :message)',

        // Log messages
        'log_api_call_failed' => 'Core update: GitHub API call failed',
        'log_auth_failed' => 'Core update: GitHub authentication failed',
        'log_not_found' => 'Core update: GitHub repository/release not found',
        'log_unexpected_status' => 'Core update: Unexpected HTTP status code',
        'log_version_check_error' => 'Core update: Error checking latest version',

        'unknown_error' => 'An unknown error occurred.',

        // System requirements
        'system_requirements_failed' => 'System requirements are not met:',
        'no_extract_method_available' => 'No archive extraction method available. Requires one of: PHP zip extension (ZipArchive) or unzip command (Linux). You can also use the --source option for manual update.',
        'manual_update_guide' => 'Manual update: Download the release ZIP from GitHub, extract it, then run: php artisan core:update --source=/path/to/extracted',

        // Extraction fallback chain
        'archive_url_not_found' => ':type archive URL not found. Trying next method.',
        'extracting_with' => 'Extracting with :method...',
        'extract_empty' => 'Extracted directory is empty.',
        'extract_fallback' => ':method failed: :error — Trying next method.',
        'all_extract_methods_failed' => 'All archive extraction methods failed. Try manual update with --source option.',
        'release_not_found' => 'Could not find the core :version release on GitHub — the version tag does not exist yet or GitHub could not be reached. Specify the release yourself with --source or --zip.',
        'extension_release_not_found' => 'Could not find release :version in GitHub repository :repository — the version tag does not exist yet or GitHub could not be reached.',
        'unzip_command_failed' => 'unzip command failed (exit code: :code). :output',
        'zip_file_not_found' => 'Specified ZIP file not found: :path',
        'source_vendor_missing' => 'No vendor directory in source. composer install may not have been executed.',
        'composer_failed_with_output' => 'composer install failed.:output',
        // `core:update` command console output
        'console' => [
            'source_zip_conflict' => '--source and --zip cannot be used together. Choose only one.',
            'local_source_conflict' => '--local cannot be used together with --source / --zip.',
            'zip_not_found' => 'The specified ZIP file does not exist: :path',
            'source_not_found' => 'The specified source directory does not exist: :path',
            'check_failed' => 'Update check failed: :error',
            'already_latest' => 'You are on the latest version: :version',
            'current_version' => 'Current version: :version',
            'target_version_pending' => 'Update version: (determined after ZIP extraction)',
            'target_version' => 'Update version: :version',
            'confirm_update' => 'Do you want to update the core?',
            'pending_invalid' => '_pending directory problem:',
            'pending_path' => 'Path: :path',
            'pending_owner' => 'Owner: :owner, group: :group, permissions: :permissions',
            'progress' => [
                'validate_source' => 'Validating source directory...',
                'extract_zip' => 'Extracting ZIP file...',
                'copy_local' => 'Copying local source...',
                'composer_skipped' => 'No Composer dependency changes — skipped',
                'manifest' => 'Generating new-file manifest...',
                'vendor_copy_skipped' => 'vendor copy skipped',
            ],
            'dev_packages_kept' => 'Detected :count development packages in the production vendor — composer.json/lock did not change, so vendor is not reinstalled and they will remain. Running \'composer install --no-dev --optimize-autoloader\' after the update is recommended.',
            'dev_packages_replaced' => 'Detected :count development packages in the production vendor — this update replaces it with a --no-dev vendor.',
            'vendor_install_failed' => 'Vendor installation failed: :error',
            'upgrade_steps_title' => '── Running upgrade steps ──',
            'upgrade_step_running' => 'Running upgrade step: :version',
            'updated' => 'Gnuboard7 core has been updated to version :version!',
            'restoring_ownership' => 'Restoring ownership of files created by the bulk update...',
            'handoff_cleanup_failed' => 'Error during handoff cleanup — manual inspection required: :error',
            'handoff_incomplete' => 'Upgrade files were applied, but some upgrade steps could not run in the current process and were stopped.',
            'handoff_after_version' => 'Completed up to step: :version',
            'handoff_file_version' => 'Files/config version: :version (already applied)',
            'handoff_reason' => 'Reason: :reason',
            'handoff_resume_intro' => 'To apply the remaining steps, run the command below (runs only the steps, without re-downloading):',
            'handoff_resume_warning' => 'If you do not run the command above, the version will show as latest but some steps will remain unexecuted.',
            'backup_kept' => 'The backup has been kept: :path',
            'restoring_backup' => 'Restoring from backup...',
            'restored' => 'Restored from backup.',
            'restored_partial' => 'Partially restored from backup.',
            'restore_failed_targets' => 'Items that failed to restore: :targets',
            'restore_failed' => 'Backup restore failed: :error',
            'cache_clear_failed' => 'Cache cleanup failed — run \'php artisan optimize:clear\' manually after boot: :error',
            'update_failed' => 'Core update failed: :error',
            'failure_report' => 'Failure report: :path',
            'maintenance_disabled' => 'Restore complete: maintenance mode has been turned off.',
            'maintenance_disable_failed' => 'Failed to turn off maintenance mode. Turn it off manually: php artisan up',
            'maintenance_kept' => 'Maintenance mode remains on.',
            'manual_recovery_required' => 'Manual recovery is required:',
            'manual_restore_backup' => 'Restore manually from backup: :command',
            'manual_reinstall_composer' => 'Reinstall Composer packages: composer install --no-dev --optimize-autoloader',
            'manual_disable_maintenance' => 'Turn off maintenance: php artisan up',
            'run_previous_version' => 'To run the site on the previous version: php artisan up',
            'admin_access' => 'Admin access: :secret',
            'manifest_clear_failed' => 'Failed to delete the package manifest before spawn — the child may boot with the previous installation\'s provider list (check permissions/ownership): :paths',
            'hook_cache_failed' => 'Could not rebuild the hook cache in a fresh process — run `php artisan hooks:cache` after the update: :output',
            'spawn' => [
                'proc_open_disabled' => 'proc_open disabled',
                'proc_open_failed' => 'failed to create proc_open resource',
                'steps_signal_missing' => 'spawn child did not emit the [STEPS_EXECUTED] signal — suspected older child or silent skip',
                'zero_steps' => 'spawn child exited with exit=0 but ran 0 steps — not the intended behavior (from=:from to=:to)',
                'zero_steps_discovered' => 'spawn child exited with exit=0 but ran 0 steps — not the intended behavior (from=:from to=:to, :count discovered)',
                'abnormal_exit' => 'spawn exited abnormally (exit=:code)',
                'abort_reason' => 'spawn child failed — :reason. fail-fast mode. Continue by resuming manually.',
            ],
            'owner_mismatch' => 'The running user differs from the core file owner.',
            'owner_mismatch_current' => 'Running user: :user (uid=:uid)',
            'owner_mismatch_owner' => 'Core owner: :user (uid=:uid)',
            'owner_mismatch_hint' => 'Permission denied errors may occur. Normally you should log in over SSH as the core file owner and run it. If vendor/ is owned by another user, run \'chown -R $(whoami) vendor/\' and retry.',
            'resume_root_known_warning' => 'You are currently running as sudo (root). If you run the command above as root, files created by the upgrade steps become owned by root and later web server (:user) requests may fail to write. Be sure to rerun it as the web server account (:user).',
            'resume_command_unknown_user' => 'sudo -u <web-server-account> :command',
            'resume_root_unknown_warning' => 'You are currently running as sudo (root). The web server account could not be identified automatically.',
            'resume_root_unknown_hint' => 'If you run the command above as root, files created by the upgrade steps become owned by root and later web server (php-fpm) requests may fail to write. Check the web server account (e.g. www-data, nginx, apache) and rerun it as that account.',
            'permission_failed' => 'Permission normalization failed — could not restore ownership/group write permission on some paths.',
            'permission_kinds' => [
                'chown' => 'ownership',
                'group_write' => 'group write',
            ],
            'permission_failed_item' => ':target [:kind]: :count failed',
            'permission_more_paths' => '… (:count in total, showing the first 5 only)',
            'permission_recovery_example' => 'Recovery example:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Official bundled source placement (core:update --bundled-sources, core:bundled-sources) (#146)
    |--------------------------------------------------------------------------
    */
    'bundled_sources' => [
        'types' => [
            'module' => 'Module',
            'plugin' => 'Plugin',
            'template' => 'Template',
            'language_pack' => 'Language pack',
        ],
        'sample_suffix' => ' (sample)',
        'item_line' => '  [:type] :identifier — :name (:version):sample',

        // core:update prompt
        'prompt_intro' => 'The new release contains :count official bundled extensions that are not in this project (installed extensions and their dependencies are updated automatically).',
        'maintenance_warning' => 'The site is in maintenance mode — please answer quickly.',
        'choice_question' => 'What would you like to do?',
        'choice_none' => 'Do not place (default)',
        'choice_all' => 'Place all',
        'choice_pick' => 'Choose which to place',
        'pick_question' => 'Choose the bundles to place (comma-separated for multiple)',
        'dependency_added' => 'Also placed as dependencies: :items',
        'noninteractive_default' => 'Non-interactive run without --bundled-sources: the :count official bundles not in this project will not be placed.',
        'option_applied' => '--bundled-sources=:value applied: :unplaced not placed, :selected placed by selection',
        'inventory_deferred' => 'Cannot read the ZIP listing; the bundled placement question will be asked after extraction.',

        // core:update summary
        'summary_title' => '── Official bundle results ──',
        'summary_installed' => '1) Installed extension updates: :success succeeded, :failed failed, :skipped skipped',
        'summary_installed_none' => '1) Installed extension updates: no new versions',
        'summary_dependency' => '2) Bundles placed as dependencies: :count',
        'summary_dependency_item' => '     :label ← :required_by',
        'summary_selected' => '3) Bundles placed by your selection: :count',
        'summary_selected_item' => '     :label',
        'summary_unplaced' => '4) Bundles not placed: :count',
        'summary_unplaced_item' => '     :label',
        'summary_place_hint' => '   Place them when needed: php artisan core:bundled-sources place <identifier>',
        'summary_on_disk' => ':count uninstalled bundles already on disk were updated to the new version.',
        'summary_selection_aborted' => 'The update was stopped because the bundle selection is invalid. No files were changed and maintenance mode was turned off.',

        // core:bundled-sources
        'source_resolving_github' => 'Downloading the core :version release from GitHub to read the bundle list...',
        'source_used' => 'Release: :source (core :version)',
        'source_failed' => 'Could not read the release: :error',
        'record_fallback' => 'Could not read the release; showing the record file (:recorded_at).',
        'disk_db_only' => 'Neither a release nor a record file is available; showing disk and install state only. Use --source or --zip to compare with a release.',
        'record_unplaced_count' => ':count not placed according to the record file — every bundle on disk is listed above.',
        'list_headers' => [
            'type' => 'Type',
            'identifier' => 'Identifier',
            'version' => 'Version',
            'disk' => 'On disk',
            'installed' => 'Installed',
            'required' => 'Required by',
        ],
        'yes' => 'Yes',
        'no' => 'No',
        'list_record_updated' => 'Record file updated (:count not placed).',
        'place_done' => 'Placed: :label',
        'place_already' => 'Already on disk, skipped: :label',
        'place_summary' => 'Placed :count bundles. Install them from the admin screen or with php artisan {module|plugin|template}:install.',
        'prune_preview_title' => 'Prune preview (nothing deleted — use --force to delete):',
        'prune_none' => 'There are no bundles to prune.',
        'prune_removed' => 'Deleted: :label',
        'prune_modified_kept' => 'Modified, kept: :label (:count files differ from the release)',
        'prune_modified_file' => '     :file',
        'prune_modified_hint' => 'Add --include-modified to delete modified folders as well.',
        'prune_extra_only_kept' => 'Only files missing from the release differ, kept: :label (files not in the release: :count)',
        'prune_extra_only_hint' => 'Folders that differ only by files missing from the release may hold files left over from an earlier version (a default update does not delete files removed in the new version). After checking, add --include-modified to remove them.',
        'prune_protected_dependency' => 'Kept as a dependency: :label (:required_by)',
        'prune_no_release_for_modified' => 'No release is available to check for modifications — add --include-modified to delete regardless of modifications.',
        'prune_summary' => 'Deleted :count bundles.',
        'unknown_action' => 'Unknown action: :action (list|place|prune)',

        'errors' => [
            'unknown_type' => 'Unknown bundle type: :type (module|plugin|template|language_pack)',
            'not_in_release' => 'Not an official bundle in the release: :identifier',
            'ambiguous' => 'Identifier :identifier exists in several types. Specify it with a type: :candidates',
            'version_mismatch' => 'The release core version (:source) differs from the current core version (:current). Specify a release of the same version.',
            'identifiers_required' => 'Specify at least one bundle identifier to place.',
            'release_required' => 'Cannot place without a readable release. Specify it with --source or --zip.',
            'release_required_prune' => 'Cannot determine what to prune without a readable release. Specify it with --source or --zip.',
            'not_unplaced_target' => 'Not a prune target (installed or not in the release): :identifier',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxy Diagnostic (#124)
    |--------------------------------------------------------------------------
    |
    | The value is edited in .env only — the screen shows a read-only diagnostic.
    |
    */

    'trusted_proxy' => [
        'alert_title' => 'Trusted proxies are not configured',
        'alert_message' => 'Proxy headers (:headers) are being received but no trusted proxy is configured, so every visitor is recorded with the same address (:ip). Set TRUSTED_PROXIES in .env. Details: https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md',
    ],
    // `settings:*` command output
    'console' => [
        'install' => [
            'defaults_not_found' => 'Default settings file not found: :path',
            'defaults_check_hint' => 'Check that the :path file exists.',
            'defaults_parse_failed' => 'Failed to parse the default settings JSON: :error',
            'defaults_section_missing' => 'The default settings file has no defaults section.',
            'start' => 'Installing settings...',
            'no_defaults' => 'No defaults. Skipping.',
            'already_exists' => 'Already exists. Skipping. (use --force or --merge)',
            'merged' => 'Merged (:count new keys added)',
            'merge_failed' => 'Merge failed',
            'action_overwritten' => 'Overwritten',
            'action_installed' => 'Installed',
            'install_failed' => 'Install failed',
            'done' => 'Settings installed!',
            'summary_installed' => 'Installed: :count',
            'summary_merged' => 'Merged: :count',
            'summary_skipped' => 'Skipped: :count',
        ],
        'migrate_to_json' => [
            'start' => 'Starting settings migration...',
            'table_missing' => 'The :table table does not exist. Initializing with defaults.',
            'defaults_created' => 'Default settings files have been created.',
            'confirm_overwrite' => 'JSON settings files already exist. Overwrite them?',
            'cancelled' => 'Migration cancelled.',
            'nothing_to_migrate' => 'No settings to migrate. Initializing with defaults.',
            'found' => 'Found :count settings in total.',
            'category_saved' => 'Saved the :category category',
            'done' => 'Migration complete!',
            'location' => 'Settings file location: :path',
        ],
    ],
];
