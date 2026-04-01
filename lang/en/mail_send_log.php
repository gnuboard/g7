<?php

return [
    // List
    'fetch_success' => 'Mail send logs loaded successfully.',
    'fetch_failed' => 'Failed to load mail send logs.',

    // Statistics
    'stats_success' => 'Mail send statistics loaded successfully.',
    'stats_failed' => 'Failed to load mail send statistics.',

    // Delete
    'delete_success' => 'Mail send log deleted successfully.',
    'bulk_delete_success' => 'Selected mail send logs deleted successfully.',
    'delete_failed' => 'Failed to delete mail send log.',

    // Validation
    'validation' => [
        'status_invalid' => 'Invalid send status.',
        'date_range_invalid' => 'End date must be after or equal to start date.',
        'per_page_min' => 'Items per page must be at least 1.',
        'per_page_max' => 'Items per page must not exceed 100.',
        'ids_required' => 'Please select items to delete.',
        'ids_min' => 'Please select at least one item to delete.',
        'id_not_found' => 'Mail send log not found.',
    ],
];
