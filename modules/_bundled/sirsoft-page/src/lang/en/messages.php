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
        'bulk_publish_success' => ':count page(s) publish status has been changed.',
        'bulk_publish_failed' => 'Failed to bulk change publish status.',
        'restore_success' => 'Page has been restored to the previous version.',
        'restore_failed' => 'Failed to restore page version.',
        'slug_check_success' => 'Slug availability check completed.',
    ],

    // Attachment operation messages
    'attachment' => [
        'not_found' => 'Attachment not found.',
        'file_not_found' => 'Attachment file does not exist in storage.',
        'upload_success' => 'Attachment has been uploaded.',
        'upload_failed' => 'Failed to upload attachment.',
        'delete_success' => 'Attachment has been deleted.',
        'delete_failed' => 'Failed to delete attachment.',
        'reorder_success' => 'Attachment order has been changed.',
        'reorder_failed' => 'Failed to change attachment order.',
    ],

    // Error messages (Exception)
    'errors' => [
        'not_found' => 'Page not found.',
        'version_not_found' => 'Version not found.',
        'version_belongs_to_different_page' => 'The specified version does not belong to this page.',
        'permission_denied' => 'Permission denied.',
        'validation_failed' => 'Validation failed.',
    ],
];
