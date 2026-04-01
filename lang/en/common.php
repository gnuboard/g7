<?php

return [
    // General response messages
    'success' => 'Successfully processed.',
    'failed' => 'Processing failed.',
    'error_occurred' => 'An error occurred.',
    'not_found' => 'The requested resource was not found.',
    'unauthorized' => 'Authentication is required.',
    'forbidden' => 'Access denied.',
    'validation_failed' => 'Input validation failed.',

    // Button states
    'saving' => 'Saving...',
    'deleting' => 'Deleting...',
    'processing' => 'Processing...',

    // System labels
    'system' => 'System',
    'yes' => 'Yes',
    'no' => 'No',

    // Common error messages
    'errors' => [
        'github_url_empty' => 'GitHub URL is empty.',
        'github_url_invalid' => 'Invalid GitHub URL format.',
        'github_api_failed' => 'GitHub API call failed.',
        'github_download_failed' => 'Failed to download from GitHub.',
        'github_archive_download_failed' => 'Failed to download archive. (:url)',
        'zip_file_not_found' => 'ZIP file not found.',
        'zip_open_failed' => 'Failed to open ZIP file.',
    ],

    // Common validation error label
    'validation_error' => 'Validation Error',

    // Changelog validation
    'changelog_validation' => [
        'source_in' => 'Source must be one of: active, bundled, github.',
        'version_format' => 'The :attribute format is invalid. (e.g., 1.0.0, 1.0.0-beta.1)',
        'to_version_required' => 'End version is required when start version is specified.',
    ],
];
