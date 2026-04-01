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
    'save_individual_failed' => 'Settings save failed: :error',

    // App key related messages
    'invalid_password' => 'Password does not match.',
    'password_required' => 'Please enter your password.',
    'app_key_regenerated' => 'Application key has been successfully regenerated.',
    'app_key_regenerate_failed' => 'Failed to regenerate application key.',
    'app_key_regenerate_warning' => 'Regenerating the key will invalidate all sessions. Do you want to continue?',

    // System info related
    'seconds' => 's',

    // Driver connection test messages
    'driver_test_success' => 'All driver connection tests passed.',
    'driver_test_partial' => 'Some driver connection tests failed.',
    'driver_test_error' => 'An error occurred while testing driver connections.',
    'unknown_driver' => 'Unknown driver.',

    // S3 test messages
    's3_test_success' => 'Successfully connected to S3 bucket.',
    's3_test_failed' => 'Failed to connect to S3 bucket.',
    's3_missing_config' => 'S3 configuration is missing. (bucket, region, access key, secret key)',
    's3_sdk_missing' => 'AWS SDK is not installed.',
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
    ],
];
