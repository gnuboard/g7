<?php

return [
    // Messages
    'fetch_success' => 'Schedules retrieved successfully.',
    'fetch_failed' => 'Failed to retrieve schedules.',
    'create_success' => 'Schedule created successfully.',
    'create_failed' => 'Failed to create schedule.',
    'update_success' => 'Schedule updated successfully.',
    'update_failed' => 'Failed to update schedule.',
    'delete_success' => 'Schedule deleted successfully.',
    'delete_failed' => 'Failed to delete schedule.',
    'run_success' => 'Schedule executed successfully.',
    'run_failed' => 'Failed to execute schedule.',
    'duplicate_success' => 'Schedule duplicated successfully.',
    'duplicate_failed' => 'Failed to duplicate schedule.',
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

    // Task types
    'type' => [
        'artisan' => 'Artisan Command',
        'shell' => 'Shell Command',
        'url' => 'URL Call',
    ],

    // Frequencies
    'frequency' => [
        'everyMinute' => 'Every Minute',
        'hourly' => 'Hourly',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'custom' => 'Custom',
    ],

    // Results
    'result' => [
        'success' => 'Success',
        'failed' => 'Failed',
        'running' => 'Running',
        'never' => 'Never Run',
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
        'name_required' => 'Task name is required.',
        'name_max' => 'Task name must not exceed 255 characters.',
        'type_required' => 'Task type is required.',
        'command_required' => 'Command is required.',
        'command_max' => 'Command must not exceed 2000 characters.',
        'expression_required' => 'Cron expression is required.',
        'expression_max' => 'Cron expression must not exceed 100 characters.',
        'frequency_required' => 'Frequency is required.',
        'timeout_integer' => 'Timeout must be an integer.',
        'timeout_min' => 'Timeout must be at least 1 second.',
        'timeout_max' => 'Timeout must not exceed 86400 seconds (24 hours).',
        'ids_required' => 'ID list is required.',
        'ids_array' => 'ID list must be an array.',
        'ids_min' => 'At least 1 ID is required.',
        'id_integer' => 'ID must be an integer.',
        'id_exists' => 'Schedule not found.',
        'is_active_required' => 'Active status is required.',
        'is_active_boolean' => 'Active status must be true or false.',
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
