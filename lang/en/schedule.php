<?php

return [
    // Messages
    'fetch_success' => 'Schedules retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve schedules.',
    'already_running' => 'This schedule is already running (overlap prevention). Run it again after it finishes.',
    'run_lock_unavailable' => 'The schedule run lock store (cache) is unavailable, so it was not run.',
    'create_success' => 'Schedule created successfully.',
    'create_failed' => 'Failed to create schedule.',
    'update_success' => 'Schedule updated successfully.',
    'update_failed' => 'Failed to update schedule.',
    'delete_success' => 'Schedule deleted successfully.',
    'delete_failed' => 'Failed to delete schedule.',
    'run_success' => 'Schedule executed successfully.',
    'run_failed' => 'Failed to execute schedule.',
    'duplicate_success' => 'Schedule copied successfully.',
    'duplicate_failed' => 'Failed to copy schedule.',
    'bulk_status_updated' => 'Selected schedules status updated.',
    'bulk_update_status_failed' => 'Failed to update schedules status.',
    'bulk_delete_success' => 'Selected schedules deleted successfully.',
    'bulk_delete_failed' => 'Failed to delete schedules.',
    'statistics_success' => 'Schedule statistics retrieved successfully.',
    'statistics_failed' => 'Failed to retrieve schedule statistics.',
    'history_fetch_success' => 'Execution history retrieved successfully.',
    'history_fetch_failed' => 'Failed to retrieve execution history.',
    'history_delete_success' => 'Execution history deleted successfully.',
    'history_delete_failed' => 'Failed to delete execution history.',
    'history_not_found' => 'Execution history not found.',
    'copy' => 'Copy',
    'shell_command_failed' => 'Shell command execution failed.',
    'http_request_failed' => 'HTTP request failed with status: :status',
    'url_not_public' => 'Internal network addresses (private IPs, localhost, etc.) cannot be called. To call an address inside your network, enable "Allow internal network addresses" under Settings > Security.',
    'shell_not_allowed' => 'This Shell command is not allowed. Only executables registered on the server can be run as a schedule.',
    'artisan_not_allowed' => 'This Artisan command cannot be run as a schedule for security reasons.',
    'owned_not_deletable' => 'Tasks registered by the core or an extension cannot be deleted. Deactivate them to stop them from running.',
    'owner_inactive' => 'The extension that owns this schedule is inactive, so it cannot run. Activate the extension and try again.',
    'job_not_found' => 'Job class not found: :class',
    'artisan_command_failed' => 'The Artisan command exited with code :code.',
    'job_not_allowed' => 'This job class is not allowed. Only jobs on the security allowlist can run as schedules.',
    'owners_fetch_success' => 'Schedule owners retrieved.',
    'owners_fetch_failed' => 'Failed to retrieve schedule owners.',
    'status_fetch_success' => 'Scheduler status retrieved.',
    'status_fetch_failed' => 'Failed to retrieve scheduler status.',
    'run_queued' => 'The schedule was sent to the queue. It will be recorded in the run history once a worker processes it.',
    'security_fetch_success' => 'Schedule security policy retrieved.',
    'security_fetch_failed' => 'Failed to retrieve the schedule security policy.',

    // Security policy override file (storage/app/schedule_security.json) errors — the whole file is ignored
    'security' => [
        'override_error' => [
            'invalid_json' => 'The security policy override file is not valid JSON (:detail). The whole file is ignored and only the default policy applies.',
            'not_object' => 'The ":key" entry of the security policy override file must be an object ({ }). The whole file is ignored and only the default policy applies.',
            'unknown_key' => 'The security policy override file has an unknown entry ":key". The whole file is ignored and only the default policy applies.',
            'not_overridable' => 'The ":key" entry cannot be changed by the override file (set Shell with SCHEDULE_SHELL_ENABLED and SCHEDULE_SHELL_ALLOWED_BINARIES in .env). The whole file is ignored and only the default policy applies.',
            'invalid_command' => 'The allowlist command name ":command" is not valid. The whole file is ignored and only the default policy applies.',
            'invalid_allowlist_entry' => 'The allowlist entry ":command" may only have options (an array of option names) and max_arguments (an integer from 0 to 10). The whole file is ignored and only the default policy applies.',
            'invalid_list' => 'The ":key" entry must be an array of valid strings. The whole file is ignored and only the default policy applies.',
            'too_large' => 'The security policy override file is too large (max :max bytes). The whole file is ignored and only the default policy applies.',
            'unreadable' => 'The security policy override file cannot be read. Check the file permissions. Only the default policy applies.',
        ],
    ],

    // Task types
    'type' => [
        'artisan' => 'Artisan',
        'shell' => 'Shell',
        'url' => 'URL',
        'job' => 'Job',
    ],

    // Frequencies
    'frequency' => [
        'everyThirtySeconds' => 'Every 30 seconds',
        'everyMinute' => 'Every Minute',
        'hourly' => 'Hourly',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'custom' => 'Custom',
    ],

    // Dispatch modes
    'dispatch_mode' => [
        'sync' => 'Run immediately',
        'queue' => 'Send to queue',
    ],

    // Run history recording
    'history_mode' => [
        'all' => 'Record all runs',
        'failures_only' => 'Record failures only',
    ],

    // Owners
    'owner' => [
        'custom' => 'Custom',
    ],

    // Results
    'result' => [
        'success' => 'Success',
        'failed' => 'Failed',
        'running' => 'Running',
        'never' => 'Never Run',
        'rejected' => 'Rejected',
    ],

    // Reject reasons (why a queued manual run was not started — the "Rejected" row in the run history)
    'reject_reason' => [
        'already_running' => 'Not run because the previous run has not finished yet (overlap prevention).',
        'run_lock_unavailable' => 'Not run because the run lock store (cache) is unavailable.',
        'owner_inactive' => 'Not run because the extension that owns this schedule is inactive.',
    ],

    // Trigger types
    'trigger_type' => [
        'scheduled' => 'Scheduled',
        'manual' => 'Manual',
    ],

    // Duration
    'duration' => [
        'seconds' => 's',
        'minutes' => 'm',
    ],

    // Validation messages
    'validation' => [
        'page_integer' => 'Page number must be an integer.',
        'page_min' => 'Page number must be at least 1.',
        'per_page_integer' => 'Items per page must be an integer.',
        'per_page_min' => 'Items per page must be at least 1.',
        'per_page_max' => 'Items per page must not exceed 100.',
        'type_invalid' => 'Invalid task type.',
        'frequency_invalid' => 'Invalid frequency.',
        'status_invalid' => 'Invalid status.',
        'last_result_invalid' => 'Invalid execution result.',
        'history_status_invalid' => 'Invalid history status.',
        'trigger_type_invalid' => 'Invalid trigger type.',
        'created_from_invalid' => 'Invalid start date format.',
        'created_to_invalid' => 'Invalid end date format.',
        'created_to_after_from' => 'End date must be after start date.',
        'started_from_invalid' => 'Invalid start date format.',
        'started_to_invalid' => 'Invalid end date format.',
        'started_to_after_from' => 'End date must be after start date.',
        'sort_by_invalid' => 'Invalid sort field.',
        'sort_order_invalid' => 'Invalid sort order.',
        'name_required' => 'Identifier is required.',
        'name_max' => 'Identifier must not exceed 255 characters.',
        'type_required' => 'Task type is required.',
        'command_required' => 'Command is required.',
        'command_max' => 'Command must not exceed 2000 characters.',
        'expression_required' => 'Cron expression is required.',
        'expression_max' => 'Cron expression must not exceed 100 characters.',
        'frequency_required' => 'Frequency is required.',
        'timeout_integer' => 'Time limit must be an integer.',
        'timeout_min' => 'Time limit must be at least 1 second.',
        'timeout_max' => 'Time limit must not exceed 86400 seconds (24 hours).',
        'ids_required' => 'ID list is required.',
        'ids_array' => 'ID list must be an array.',
        'ids_min' => 'At least 1 ID is required.',
        'id_integer' => 'ID must be an integer.',
        'id_exists' => 'Schedule not found.',
        'id_exists_or_owned' => 'The schedule does not exist or cannot be deleted (registered by the core or an extension).',
        'is_active_required' => 'Active status is required.',
        'is_active_boolean' => 'Active status must be true or false.',
        'label_required' => 'Task name is required.',
        'dispatch_mode_invalid' => 'Invalid dispatch mode.',
        'history_mode_invalid' => 'Invalid history recording mode.',
        'without_overlapping_integer' => 'Overlap prevention time must be a whole number of minutes.',
        'without_overlapping_range' => 'Overlap prevention time must be between 0 (off) and :max minutes.',
        'queue_connection_max' => 'The queue connection name may not exceed 50 characters.',
        'queue_name_max' => 'The queue name may not exceed 100 characters.',
        'timezone_invalid' => 'Invalid timezone.',
        'field_not_editable' => 'This field cannot be set from the admin screen.',
        'owned_field_locked' => 'This field cannot be changed on tasks registered by the core or an extension.',
        'job_not_allowed' => 'This Job class is not allowed. Only Jobs on the security allowlist can run as a schedule.',
        'search_field_invalid' => 'Invalid search field.',
        'extension_type_invalid' => 'Invalid owner type.',
        'overlap_invalid' => 'Invalid overlap prevention filter.',
    ],

    // Validation attribute names
    'attributes' => [
        'page' => 'page',
        'per_page' => 'items per page',
        'search_field' => 'search field',
        'search_keyword' => 'search keyword',
        'filters' => 'search conditions',
        'filters_operator' => 'search operator',
        'owner' => 'owner',
        'owner_identifier' => 'owner extension',
        'type' => 'task type',
        'status' => 'status',
        'last_result' => 'last result',
        'frequency' => 'frequency',
        'dispatch_mode' => 'dispatch mode',
        'without_overlapping' => 'overlap prevention',
        'history_mode' => 'run history',
        'run_in_maintenance' => 'run in maintenance',
        'created_start_date' => 'registered from',
        'created_end_date' => 'registered to',
        'sort_by' => 'sort field',
        'sort_order' => 'sort order',
        'name' => 'identifier',
        'label' => 'task name',
        'description' => 'description',
        'command' => 'command',
        'expression' => 'cron expression',
        'timeout' => 'time limit',
        'is_active' => 'active status',
        'on_one_server' => 'run on one server',
        'notify_on_failure' => 'failure notification',
        'queue_connection' => 'queue connection',
        'queue_name' => 'queue name',
        'timezone' => 'timezone',
        'enabled_condition' => 'run condition',
        'command_class' => 'command class',
    ],

    // Web cron (URL-triggered scheduled tasks)
    'cron' => [
        'invalid_token' => 'The call number is not valid.',
        'ip_not_allowed' => 'This call came from an address that is not allowed.',
        'disabled' => 'Scheduled tasks are set to server cron only, so this URL call did not run them.',
        'throttled' => 'The minimum interval has not elapsed, so this call did not run. Pending tasks are handled on the next call.',
        'completed' => 'Scheduled tasks finished running.',
        'run_failed' => 'Failed to run scheduled tasks.',
        'token_fetch_success' => 'The call address was retrieved.',
        'token_fetch_failed' => 'Failed to retrieve the call address.',
        'token_rotated' => 'A new call number was issued. The previous address is now invalid.',
        'token_rotate_failed' => 'Failed to issue a new call number.',
        'console_summary' => 'Scheduled tasks: :executed of :due ran, :failed failed, :skipped skipped (:duration ms)',
        'console_truncated' => 'The runtime limit was reached; the remaining tasks were deferred to the next call.',
    ],

    // Dashboard alerts (scheduled tasks not running)
    'alerts' => [
        'not_running_title' => 'Scheduled tasks have not run for :days day|Scheduled tasks have not run for :days days',
        'not_running_guidance_web' => 'Copy the call address from Settings > System and register it in the scheduled task feature of your hosting control panel.',
        'not_running_guidance_cron' => 'Check whether the scheduled task (crontab) registered on the server is running.',
        'not_running_action' => 'Open schedule settings',
    ],

    // Permissions
    'permissions' => [
        'read' => 'View Schedules',
        'create' => 'Create Schedules',
        'update' => 'Update Schedules',
        'delete' => 'Delete Schedules',
        'run' => 'Run Schedules',
    ],
];
