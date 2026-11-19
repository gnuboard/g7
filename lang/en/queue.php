<?php

return [
    // Response messages
    'messages' => [
        'status_loaded' => 'Queue status loaded.',
        'pending_loaded' => 'Pending jobs loaded.',
        'failed_loaded' => 'Failed jobs loaded.',
        'failed_detail_loaded' => 'Failed job details loaded.',
        'failed_not_found' => 'Failed job not found. It may have already been retried or deleted.',
        'logs_loaded' => 'Job records loaded.',
        'log_detail_loaded' => 'Job record details loaded.',
        'log_not_found' => 'Job record not found.',
        'retry_success' => 'The job was pushed back onto the queue.',
        'retry_failed' => 'Could not push the job back onto the queue.',
        'retry_no_worker' => 'The current queue driver (such as sync) has no worker, so the job cannot be pushed back. Check the queue driver and queue workers in Settings > Drivers.',
        'retry_bulk_done' => 'Retry finished. Succeeded: :succeeded, failed: :failed',
        'delete_success' => 'The failed job was deleted.',
        'delete_bulk_done' => 'Delete finished. Succeeded: :succeeded, failed: :failed',
    ],

    // Validation messages
    'validation' => [
        'page_invalid' => 'The page number is invalid.',
        'per_page_invalid' => 'Items per page must be a number between 1 and 100.',
        'search_field_invalid' => 'The search field is invalid.',
        'search_keyword_max' => 'The search keyword may not be longer than 255 characters.',
        'queue_invalid' => 'The queue name is invalid.',
        'connection_invalid' => 'The connection name is invalid.',
        'status_invalid' => 'The result is invalid.',
        'date_invalid' => 'The date format is invalid.',
        'date_range_invalid' => 'The end date must be on or after the start date.',
        'uuids_required' => 'Select at least one job.',
        'uuids_max' => 'You can process up to :max jobs at a time.',
        'uuid_invalid' => 'The job identifier (UUID) is invalid.',
        'uuid_duplicated' => 'The same job was selected twice.',
        'sort_by_invalid' => 'The sort field is invalid.',
        'sort_order_invalid' => 'The sort order is invalid.',
    ],

    // Validation field labels
    'attributes' => [
        'page' => 'page',
        'per_page' => 'items per page',
        'search_field' => 'search field',
        'search_keyword' => 'search keyword',
        'queue' => 'queue',
        'connection' => 'connection',
        'status' => 'result',
        'failed_start_date' => 'failed from',
        'failed_end_date' => 'failed until',
        'created_start_date' => 'recorded from',
        'created_end_date' => 'recorded until',
        'uuids' => 'selected jobs',
        'uuid' => 'job identifier',
        'sort_by' => 'sort field',
        'sort_order' => 'sort order',
    ],

    // Job record results
    'log_status' => [
        'processed' => 'Succeeded',
        'failed' => 'Failed',
    ],

    // Prune command (queue:prune-records)
    'prune' => [
        'description' => 'Prune queue job records and failed jobs older than the retention period.',
        'option_days' => 'Retention period in days (1-90). Defaults to the setting (7 days)',
        'invalid_days' => 'The retention period must be an integer between :min and :max days.',
        'done' => 'Pruned records older than :days days — job records: :logs, failed jobs: :failed',
    ],
];
