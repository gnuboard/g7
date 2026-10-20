<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Page Messages
    |--------------------------------------------------------------------------
    */

    // Page operation messages
    'page' => [
        'not_found' => 'Page not found.',
        'fetch_success' => 'Page information retrieved.',
        'fetch_failed' => 'Failed to retrieve page information.',
        'create_success' => 'Page has been created.',
        'create_failed' => 'Failed to create page.',
        'update_success' => 'Page has been updated.',
        'update_failed' => 'Failed to update page.',
        'delete_success' => 'Page has been deleted.',
        'delete_failed' => 'Failed to delete page.',
        'publish_success' => 'Page publish status has been changed.',
        'publish_failed' => 'Failed to change page publish status.',
        'bulk_publish_success' => 'Changed the publish status of :count page.|Changed the publish status of :count pages.',
        'bulk_publish_failed' => 'Failed to bulk change publish status.',
        'restore_success' => 'Page has been restored to the previous version.',
        'restore_failed' => 'Failed to restore page version.',
        'slug_check_success' => 'Slug availability check completed.',
    ],

    // Attachment operation messages
    'attachment' => [
        'not_found' => 'Attachment not found.',
        'file_not_found' => 'Attachment file does not exist in storage.',
        'preview_not_supported' => 'Preview is not available for this file type.',
        'upload_success' => 'Attachment has been uploaded.',
        'upload_failed' => 'Failed to upload attachment.',
        'delete_success' => 'Attachment has been deleted.',
        'delete_failed' => 'Failed to delete attachment.',
        'reorder_success' => 'Attachment order has been changed.',
        'settings_success' => 'Attachment settings loaded.',
        'reorder_failed' => 'Failed to change attachment order.',
    ],

    // Error messages (Exception)
    'errors' => [
        'not_found' => 'Page not found.',
        'version_not_found' => 'Version not found.',
        'version_belongs_to_different_page' => 'The specified version does not belong to this page.',
        'permission_denied' => 'Permission denied.',
        'validation_failed' => 'Validation failed.',
        'attachment_limit_exceeded' => 'You can attach up to :limit file. (requested: :attempted)|You can attach up to :limit files. (requested: :attempted)',
    ],
    // Console command output
    'console' => [
        'playwright_seed' => [
            'cli_only' => 'This command can only be run from the CLI.',
            'bypass_required' => 'The G7_PLAYWRIGHT_BYPASS=1 environment variable is required.',
        ],
        'seed_page' => [
            'done' => 'Page seed complete: unpublished=:unpublished / published=:published',
        ],
        'prune_temp_attachments' => [
            'retention_disabled' => 'Cleanup was skipped because the temporary attachment retention period is less than 1 day.',
            'dry_run' => 'Unlinked temporary page attachments past retention (days: :days): :count',
            'done' => 'Deleted :deleted of :scanned temporary page attachments past retention (days: :days). (Failed: :failed)',
        ],
    ],
    // Page module seeder console output
    'seeders' => [
        'activity_log_sample' => [
            'skip_no_admin' => 'No admin user found; skipping the page activity log seeder.',
            'unmarked_kept' => 'Unmarked page activity logs kept as production logs: :count (samples created by earlier seeder versions have no marker either, so they are not cleaned up).',
            'skip_no_pages' => 'No pages were newly created in this run; skipping page activity logs.',
            'done' => 'Page activity logs created: :count',
        ],
        'database' => [
            'start' => 'Running page module seeders',
            'sample_start' => 'Running page sample seeders',
            'done' => 'Page module seeders finished',
        ],
        'page_sample' => [
            'start' => 'Creating page demo samples...',
            'done' => 'Page demo samples created: :count',
            'no_gd' => 'The GD extension is not available, so no attachment images were created.',
            'created' => '[:slug] created',
            'exists_skip' => '[:slug] already exists. Skipping.',
        ],
        'page' => [
            'start' => 'Creating page data...',
            'exists_skip' => '[:slug] already exists. Skipping.',
            'status' => [
                'published' => 'published',
                'unpublished' => 'unpublished',
            ],
            'created' => '[:slug] created (:status)',
            'done' => 'Page data created: :count',
        ],
    ],
];
