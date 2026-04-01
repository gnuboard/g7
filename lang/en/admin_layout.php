<?php

return [
    'validation' => [
        'success' => 'admin_layout.json validation completed successfully.',
        'schema_failed' => 'JSON schema validation failed: :errors',
        'unexpected_error' => 'An unexpected error occurred: :message',
        'schema_not_found' => 'JSON schema file not found: :path',
        'schema_parse_error' => 'JSON schema file parsing error: :error',
        'file_not_found' => 'File not found: :path',
        'json_parse_error' => 'JSON parsing error (:file): :error',
        'version_unknown' => 'Unknown version',
        'version_error' => 'Unable to retrieve version information',
        'module_required' => 'Module name is required',
        'module_string' => 'Module name must be a string',
        'module_format' => 'Module name must start with a letter and contain only letters, numbers, underscores, and hyphens',
        'module_max_length' => 'Module name cannot exceed 50 characters',
        
        'log' => [
            'success' => 'admin_layout.json validation successful',
            'failed' => 'admin_layout.json validation failed',
            'exception' => 'Exception occurred during admin_layout.json validation',
            'version_error' => 'Failed to retrieve schema version information',
        ],
    ],
    
    'loader' => [
        'validation_failed' => 'Layout validation failed for module :module: :errors',
        'file_not_found' => 'Layout file not found: :path',
        'json_parse_error' => 'JSON parsing error (:file): :error',
        'file_load_error' => 'Error occurred while loading file (:path): :error',
        
        'log' => [
            'cache_hit' => 'Layout data loaded from cache',
            'cache_invalidated' => 'Cache invalidated',
            'loaded' => 'Layout file loaded successfully',
            'cached' => 'Layout data cached',
            'cache_manually_invalidated' => 'Cache manually invalidated',
            'all_cache_invalidated' => 'All cache invalidated',
            'file_load_error' => 'File load error',
        ],
    ],
    
    'command' => [
        'starting' => 'Starting admin_layout.json file validation',
        'validating_module' => 'Validating module :module...',
        'validating_all_modules' => 'Starting validation of :count modules',
        'module_layout_not_found' => 'admin_layout.json file not found for module :module',
        'module_valid' => 'Module :module validation successful',
        'module_invalid' => 'Module :module validation failed: :error',
        'unexpected_error' => 'Unexpected error in module :module: :error',
        'modules_directory_not_found' => 'Modules directory not found',
        'no_modules_found' => 'No modules found to validate',
        'module_summary' => 'Module :module Summary',
        'menu_name' => 'Menu Name',
        'menu_route' => 'Route',
        'data_sources_count' => 'Data Sources Count',
        'layout_type' => 'Layout Type',
        'validation_summary' => 'Validation Summary',
        'valid_modules_count' => 'Valid Modules',
        'invalid_modules_count' => 'Invalid Modules',
        'missing_layouts_count' => 'Missing Layouts',
        'invalid_modules_details' => 'Invalid Modules Details:',
        'missing_layouts_details' => 'Modules with Missing Layouts:',
        'cache_stats' => 'Cache Statistics',
        'total_cached_modules' => 'Total Cached Modules',
        'cache_expiration' => 'Cache Expiration',
        'cached_modules_details' => 'Cached Modules Details:',
        'clearing_cache' => 'Clearing cache...',
        'cache_cleared' => 'Cache has been cleared',
    ],
    
    // API related messages
    'success' => [
        'loaded' => 'admin_layout.json loaded successfully',
        'cached' => 'Layout data has been cached',
    ],
    
    'error' => [
        'module_not_found' => 'The requested module was not found',
        'json_parse_error' => 'An error occurred during JSON parsing',
        'file_not_readable' => 'File is not readable',
        'json_parse_failed' => 'JSON parsing failed',
        'validation_failed' => 'Layout validation failed',
        'server_error' => 'Internal server error occurred',
    ],
    
    'log' => [
        'cache_hit' => 'Layout data retrieved from cache',
        'cache_updated' => 'Cache has been updated',
        'layout_loaded' => 'Layout loaded successfully',
        'validation_completed' => 'Layout validation completed',
        'layout_load_error' => 'Error occurred while loading layout',
        'validation_error' => 'Error occurred during layout validation',
        'service_cache_invalidated' => 'Service cache invalidated',
        'service_all_cache_invalidated' => 'Service all cache invalidated',
        'cache_invalidated' => 'Cache invalidated',
        'all_cache_invalidated' => 'All cache invalidated',
        'file_read_error' => 'Error occurred while reading file',
    ],
];
