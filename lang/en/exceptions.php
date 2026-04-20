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

    // Vendor bundle / installation related exceptions
    'vendor' => [
        'composer_not_available' => 'Composer cannot be executed in this environment. Use bundled mode instead.',
        'composer_not_available_for_build' => 'Building vendor-bundle requires Composer execution. Install/configure Composer in the development environment and retry.',
        'bundle_build_composer_failed' => 'composer install failed while building vendor-bundle (exit :exit): :message',
        'composer_execution_failed' => 'Composer execution failed: :message',
        'bundle_zip_missing' => 'vendor-bundle.zip not found: :path',
        'bundle_manifest_missing' => 'vendor-bundle.json not found: :path',
        'bundle_manifest_invalid' => 'vendor-bundle.json could not be read: :error',
        'bundle_integrity_failed' => 'Bundle integrity check failed: :details',
        'bundle_schema_unsupported' => 'Unsupported vendor-bundle.json schema version: :version',
        'zip_archive_not_available' => 'PHP ZipArchive extension is not installed.',
        'zip_hash_mismatch' => 'vendor-bundle.zip hash mismatch (expected: :expected, actual: :actual).',
        'composer_json_sha_mismatch' => 'composer.json has changed since the bundle was built. Rebuild with vendor-bundle:build.',
        'composer_lock_sha_mismatch' => 'composer.lock has changed since the bundle was built. Rebuild with vendor-bundle:build.',
        'bundle_contains_unsafe_path' => 'Bundle zip contains unsafe file path: :path',
        'extraction_failed' => 'Failed to extract bundle zip: :message',
        'no_vendor_strategy_available' => 'Cannot determine vendor install strategy. Neither Composer nor bundle zip is available.',
        'source_dir_not_found' => 'Installation source directory not found: :path',
        'target_not_writable' => 'Target directory is not writable: :path',
        'target_not_writable_owner_hint' => "Current user (:current_user) differs from directory owner (:owner). Run 'chown -R :current_user_name :path' and retry, or SSH in as the owner (:owner_name) before running this command.",
        'composer_json_not_found' => 'composer.json not found: :path',
        'no_composer_lock' => 'composer.lock is required. Run composer install first: :path',
        'vendor_dir_not_found' => 'vendor/ directory does not exist. Run composer install first: :path',
    ],
];
