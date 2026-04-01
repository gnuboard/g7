<?php

return [
    // User related exceptions
    'cannot_delete_super_admin' => 'Super admin cannot be deleted.',

    'circular_reference' => 'Layout circular reference detected: :trace',
    'max_depth_exceeded' => 'Layout nesting depth exceeds maximum allowed depth (:max).',
    'template_file_copy_failed' => 'Template file copy failed: :source → :destination',
    'template_build_directory_creation_failed' => 'Template build directory creation failed: :path',
    'template_dist_directory_not_found' => 'Template dist directory not found: :path',
    'template_not_found' => 'Template not found: :identifier',
    'template_not_active' => 'Template is not active: :identifier (status: :status)',

    // Layout related exceptions
    'layout' => [
        'duplicate_data_source_id' => 'Duplicate data_sources ID: :id',
        'duplicate_data_source_id_in_file' => 'Duplicate data_sources ID in layout file: :ids (file: :file)',
        'duplicate_data_source_id_extends' => 'Duplicate data_sources ID in extends inheritance: :ids (child: :child, parent: :parent)',
        'not_found' => 'Layout not found: :name',
        'parent_not_found' => 'Parent layout not found: :parent (requested layout: :child)',

        // Include related exceptions
        'include_file_not_found' => 'Include file not found: :path (resolved: :resolved)',
        'invalid_include_json' => 'Invalid JSON in include file: :path (error: :error)',
        'circular_include' => 'Circular include detected: :trace',
        'max_include_depth_exceeded' => 'Maximum include depth exceeded (max: :max)',
        'include_outside_directory' => 'Include path outside allowed directory: :path (allowed: :allowed_dir)',
    ],

    // Layout version related exceptions
    'layout_version' => [
        'save_failed_after_retries' => 'Failed to save layout version after :attempts attempts.',
        'save_failed_unexpected' => 'Unexpected error occurred while saving layout version.',
    ],

    // Settings related exceptions
    'settings' => [
        'backup_creation_failed' => 'Failed to create settings backup file.',
        'restore_failed' => 'Failed to restore settings.',
        'category_not_found' => 'Settings category not found: :category',
        'save_failed' => 'Failed to save settings: :category',
    ],
];
