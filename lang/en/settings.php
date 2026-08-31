<?php

// changelog:allow 무CLI 실행 모드 축의 신규 키 — 사용자 노출 화면 미구현 단계라 공개 CHANGELOG 는 기능 완성 릴리즈 섹션에 일괄 기재 (#609)

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
    'static_cache_republished' => 'Startup files were rebuilt.',
    'static_cache_republish_failed' => 'Startup files could not be rebuilt. The site keeps working normally.',

    // App key related messages
    'invalid_password' => 'Password does not match.',
    'app_key_env_write_failed' => 'The application key was not regenerated because the .env file could not be written. Check free disk space and file permissions.',
    'password_required' => 'Please enter your password.',
    'app_key_regenerated' => 'Application key has been successfully regenerated.',
    'app_key_regenerate_failed' => 'Failed to regenerate application key.',
    'app_key_regenerate_warning' => 'Regenerating the key will invalidate all sessions. Do you want to continue?',

    // System info related
    'seconds' => 's',

    // Execution mode (how commands run inside web requests)
    'execution_mode' => [
        'auto' => 'Detect automatically',
        'subprocess' => 'Always use CLI',
        'inprocess' => 'Always without CLI',
    ],

    // PHP binary probe reasons (App\Support\PhpBinaryResolver / ExecutionEnvironment send
    // these as `message_key` - the keys carry no namespace, so the consumer prepends one:
    // `__('settings.'.$result['message_key'], $result['message_params'])`).
    'php_probe_ok' => 'PHP :version confirmed (minimum :min_version).',
    'php_probe_version_low' => 'PHP :version is below the required minimum :min_version.',
    'php_probe_empty_path' => 'The PHP executable path is empty.',
    'php_probe_file_missing' => 'The PHP executable was not found: :path',
    'php_probe_exec_failed' => 'The PHP executable could not be run: :path',
    'php_probe_unparsable' => 'The PHP version could not be read.',
    'php_probe_exec_unavailable' => 'External command execution is blocked on this server, so the PHP executable could not be checked.',
    'php_probe_failed' => 'Checking the PHP executable failed.',
    'php_probe_functions_blocked' => 'The functions needed to run a separate process are blocked on this server, so the check was skipped.',
    'php_probe_auto_detected' => 'The PHP executable was detected automatically: :path',

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

        // Root entry / protection file notices (D25)
        'root_index_replaced' => 'The root entry file (:path) was not the core version and has been replaced with the core shim — a copy of the previous contents was saved to :copy. If this site kept its own index.php at the project root, recover it from that copy (the update backup is deleted on success).',
        'root_index_replaced_not_preserved' => 'The root entry file (:path) was not the core version and has been replaced with the core shim — the previous contents could NOT be copied aside (writing to storage/app/core-update failed). If this site kept its own index.php at the project root, those contents cannot be recovered.',
        'root_index_unreadable' => 'The root entry file (:path) could not be read, so it was replaced without confirming whether it was the core version — and because it could not be read, no copy was kept. Any custom contents cannot be recovered.',
        'already_running' => 'Another core update is already running — try again once it finishes. Overlapping runs overwrite the rollback backup of the other run, leaving nothing to restore.',
        'run_lock_unavailable' => 'Concurrency protection could not be enabled, continuing without it (:reason) — run only one update at a time while this is the case. Overlapping runs overwrite the rollback backup of the other run, leaving nothing to restore.',
        'run_lock_unknown_reason' => 'the lock file could not be opened (:path) — check permissions, free disk space and open_basedir',
        'root_file_copy_failed' => 'Could not update the root entry/protection file (:path) — that file remains at the previous version. Check write permissions on both the file and its directory, then try again. The remaining items were updated normally.',
        'root_htaccess_preserved' => 'The core-managed block markers (`# BEGIN G7` / `# END G7` at the start of a line) were not found in the root protection file (:path), so it was left untouched — the markers were removed or changed shape (indented, or followed by other text), and the core protection rules are not in effect. Unless you removed the block deliberately, keep the block from the released `.htaccess` verbatim in that file and try again.',
        'root_htaccess_created' => 'The root protection file (:path) was created — if your project root was exposed to the web, it is protected now. Note that if you have been reaching this site through `/public/…` URLs, those now redirect (301) to the canonical address; on a server whose document root cannot be changed, keeping this file is the correct setup.',
        'root_htaccess_write_failed' => 'Could not write the root protection file (:path) — the protection rules remain at the previous version. Check write permissions on both the file and its directory, then try again (the replacement writes a temporary file in the target file\'s directory and swaps it in, so that directory must be writable too). If the file is a symbolic link, the permissions that matter are those of the link target and the directory containing the target, not the link.',
        'deploy_fragment_preserved' => 'The server configuration fragment (:path) has been modified by the operator, so it was left as is and the new version was placed beside it as :dist. Compare the two files and merge the changes yourself (nginx reads this file via include, so reload afterwards), then delete the .dist file — until you merge, the new version\'s protection rules are not in effect on this server.',
        'deploy_fragment_dist_write_failed' => 'The server configuration fragment (:path) has been modified by the operator, so it was left as is — but the new version could not be written beside it as :dist (check the directory\'s write permission). Compare it with the same file in the released package and merge manually.',
        'root_htaccess_read_failed' => 'Could not read the root protection file (:path) — the update was skipped and the protection rules remain at the previous version. Check the file owner and read permissions, then try again.',
        'root_htaccess_duplicate_block' => 'The root protection file (:path) contains more than one core-managed block — the automatic update was skipped. Remove the duplicates so that exactly one remains, then try again.',
        'root_htaccess_broken_block' => 'The core-managed block in the root protection file (:path) is damaged (only one of `# BEGIN G7` / `# END G7` is present, or they are in the wrong order) — the automatic update was skipped, and the protection rules in that file are currently incomplete (an interrupted transfer or a truncated edit can cause this). Replace the block with the one from the released `.htaccess`, then try again.',
        'root_htaccess_source_invalid' => 'No core-managed block was found in the released root protection file — the update was skipped.',
        'root_htaccess_destination_invalid' => 'The root protection path (:path) is not a file — the update was skipped.',
        'root_htaccess_splice_invalid' => 'Splicing the core-managed block into the root protection file (:path) produced a result that is not intact — the update was skipped. Check the content outside the block, then try again.',
        'root_web_config_preserved' => 'The core-managed block markers (XML comment lines carrying `BEGIN G7` / `END G7` at the start of a line) were not found in the IIS root protection file (:path), so it was left untouched — the markers were removed or changed shape (indented, or followed by other text), and the core protection rules are not in effect. Unless you removed the block deliberately, keep the block from the released `web.config` verbatim in that file and try again.',
        'root_web_config_created' => 'The IIS root protection file (:path) was created — if your project root was exposed to the web, it is protected now (the URL Rewrite module must be installed for it to be read). If you have been reaching this site through `/public/…` URLs, those now redirect (301) to the canonical address.',
        'root_web_config_write_failed' => 'Could not write the IIS root protection file (:path) — the protection rules remain at the previous version. Check write permissions on both the file and its directory, then try again (the replacement writes a temporary file in the target file\'s directory and swaps it in, so that directory must be writable too). If the file is a symbolic link, the permissions that matter are those of the link target and the directory containing the target.',
        'root_web_config_read_failed' => 'Could not read the IIS root protection file (:path) — the update was skipped and the protection rules remain at the previous version. Check the file owner and read permissions, then try again.',
        'root_web_config_duplicate_block' => 'The IIS root protection file (:path) contains more than one core-managed block — the automatic update was skipped. Remove the duplicates so that exactly one remains, then try again.',
        'root_web_config_broken_block' => 'The core-managed block in the IIS root protection file (:path) is damaged (only one of the `BEGIN G7` / `END G7` marker comments is present, or they are in the wrong order) — the automatic update was skipped, and the protection rules in that file are currently incomplete (IIS returns 500 for the whole site when web.config fails to parse). Replace the block with the one from the released `web.config`, then try again.',
        'root_web_config_source_invalid' => 'No core-managed block was found in the released IIS root protection file — the update was skipped.',
        'root_web_config_destination_invalid' => 'The IIS root protection path (:path) is not a file — the update was skipped.',
        'root_web_config_splice_invalid' => 'Splicing the core-managed block into the IIS root protection file (:path) produced XML that is not well-formed — the update was skipped. There may be an unclosed tag outside the block (IIS returns 500 for the whole site when web.config fails to parse). Check the file, then try again.',
        'root_web_config_unrecognized_failure' => 'Updating the IIS root protection file (:path) ended with an unrecognized outcome (:result) — the protection rules may not have been updated, so check the file contents directly.',
        'deploy_fragment_dist_conflict' => 'The server configuration fragment (:path) has been modified by the operator, so it was left as is. The :dist beside it no longer matches the released version (you may be in the middle of merging it), so it was not overwritten — the new version was placed as :dist_new instead. Merge from the new version (.dist.new) (nginx reads this file via include, so reload afterwards), then delete both .dist and .dist.new.',
        'deploy_fragment_ledger_seeded' => 'Recorded the update baseline for :count server configuration fragment(s) under deploy/.',
        'deploy_fragment_ledger_seed_skipped' => 'The update baseline for the server configuration fragments (deploy/) already exists or there is nothing to record — left as is.',
        'deploy_fragment_ledger_seed_premise' => 'This baseline records **what is currently on disk** as if it were the version shipped by the core. If you ran this at any time other than right after installation (especially right after a rollback), server configuration fragments you edited are now recorded as unmodified, and the next update will overwrite them without warning. Keep a copy of any fragment you have edited.',
        'deploy_fragment_ledger_write_failed' => 'Could not write the server configuration fragment update record (:ledger) — the next core update will treat every fragment under deploy/ as "modified" and place the new version beside it as .dist. Check the write permission of the storage/app directory.',
        'storage_link_backup_inside_docroot' => 'Could not move the public file backup outside the document root (:path) — that copy is reachable from the web right now. Review its contents, then move or delete it.',
        'storage_link_backup_needs_review' => 'A full copy of your public files was left at :path — on layouts where the document root is the project root (the "document root cannot be changed" hosting in the install guide) it is hidden only while the root protection file is intact. Review its contents, then move or delete it.',
        'storage_link_backup_accumulated' => ':count damaged public storage backups have accumulated (:path). Review their contents, then clean them up — the core never deletes them automatically to avoid data loss, and `php artisan storage:prune-leftovers` reclaims only those past the retention period while always keeping the most recent one.',
        'root_htaccess_unrecognized_failure' => 'Updating the root protection file (:path) ended with an unrecognized outcome (:result) — the protection rules may not have been updated, so check the file contents directly.',
        'rollback_root_protection_missing' => 'After the rollback the root entry and protection files are missing (:paths). This is normal if the document root is public/ or the restored version never had them. If the document root is the project root, configuration files are now exposed on the web - restore those files or point the document root at public/ immediately.',
        'rollback_docroot_residue' => 'The rollback restored public file copies inside the document root (:paths) — those copies are served over the web as-is. Review them, then move them outside the document root or delete them.',

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
        'unzip_command_failed' => 'unzip command failed (exit code: :code). :output',
        'zip_file_not_found' => 'Specified ZIP file not found: :path',
        'source_vendor_missing' => 'No vendor directory in source. composer install may not have been executed.',
        'composer_failed_with_output' => 'composer install failed.:output',
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

];
