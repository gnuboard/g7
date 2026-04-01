<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Board Messages
    |--------------------------------------------------------------------------
    */

    // Board operation messages
    'boards' => [
        'not_found' => 'Board not found.',
        'already_exists' => 'Board slug already exists.',
        'has_posts' => 'Cannot delete board with posts.',
        'fetch_success' => 'Board information retrieved.',
        'create_success' => 'Board has been created.',
        'update_success' => 'Board has been updated.',
        'delete_success' => 'Board has been deleted.',
        'copy_success' => 'Board has been copied.',
        'config_retrieved' => 'Board configuration retrieved.',
        'types_retrieved' => 'Board types retrieved.',
        'form_data_retrieved' => 'Board form data retrieved.',
        'copy_data_retrieved' => 'Board copy data retrieved.',
        'create_failed' => 'Failed to create board.',
        'update_failed' => 'Failed to update board.',
        'delete_failed' => 'Failed to delete board.',
        'table_creation_failed' => 'Failed to create board tables.',
        'validation_failed' => 'Validation failed.',
        'menu_added_success' => 'Added to admin menu.',
        'menu_add_failed' => 'Failed to add to menu.',
        'menu_already_exists' => 'Menu already exists.',
        'module_not_found' => 'Board module not found.',
        'error_404' => 'Board not found: :slug',
    ],

    // Post operation messages
    'post' => [
        'not_found' => 'Post not found.',
        'permission_denied' => 'You do not have permission for this post.',
        'secret_password_required' => 'Secret post password is required.',
        'secret_password_incorrect' => 'Secret post password is incorrect.',
        // Secret post filtering messages
        'secret_post_content' => 'This is a secret post. Please enter the password to view the content.',
        'deleted_post_title' => 'Deleted post',
        'deleted_post_content' => 'This post has been deleted.',
        'blinded_post_content' => 'This post has been blinded by administrator.',
        // Post type labels
        'notice' => 'Notice',
        'reply' => 'Reply',
        // Action labels (for action_logs)
        'action' => [
            'blind' => 'Blinded',
            'delete' => 'Deleted',
            'restore' => 'Restored',
        ],
    ],

    // Posts management messages (admin)
    'posts' => [
        'fetch_success' => 'Posts retrieved.',
        'fetch_failed' => 'Failed to retrieve posts.',
        'form_data_retrieved' => 'Post form data retrieved.',
        'form_data_failed' => 'Failed to retrieve post form data.',
        'form_meta_retrieved' => 'Post form meta data retrieved.',
        'form_meta_failed' => 'Failed to retrieve post form meta data.',
        'create_success' => 'Post has been created.',
        'create_failed' => 'Failed to create post.',
        'update_success' => 'Post has been updated.',
        'update_failed' => 'Failed to update post.',
        'delete_success' => 'Post has been deleted.',
        'delete_failed' => 'Failed to delete post.',
        'blind_success' => 'Post has been blinded.',
        'blind_failed' => 'Failed to blind post.',
        'restore_success' => 'Post has been restored.',
        'restore_failed' => 'Failed to restore post.',
        'blinded_post_access_denied' => 'This post has been blinded.',
        'deleted_post_access_denied' => 'This post has been deleted.',
        'secret_post_access_denied' => 'You do not have permission to view this secret post.',
        'guest_read_not_allowed' => 'Guest reading is not allowed on this board.',
        'guest_write_not_allowed' => 'Guest posting is not allowed on this board.',
        'file_upload_not_allowed' => 'File upload is not allowed on this board.',
        'guest_upload_not_allowed' => 'Guest file upload is not allowed on this board.',
        'modify_permission_denied' => 'You do not have permission to modify this post.',
        'delete_permission_denied' => 'You do not have permission to delete this post.',
        'error_404' => 'Post not found.',
        // Password verification messages
        'password_verified' => 'Password verified successfully.',
        'password_verify_failed' => 'Failed to verify password.',
        'password_required' => 'Please enter the password.',
        'password_incorrect' => 'The password is incorrect.',
        'password_verify_not_allowed' => 'Password verification is not available for member posts.',
        'no_password_set' => 'No password has been set for this post.',
    ],

    // Comment operation messages
    'comment' => [
        'not_found' => 'Comment not found.',
        'permission_denied' => 'You do not have permission for this comment.',
        'create_success' => 'Comment has been created.',
        'create_failed' => 'Failed to create comment.',
        'update_success' => 'Comment has been updated.',
        'update_failed' => 'Failed to update comment.',
        'delete_success' => 'Comment has been deleted.',
        'delete_failed' => 'Failed to delete comment.',
        'update_forbidden' => 'You do not have permission to update this comment.',
        'delete_forbidden' => 'You do not have permission to delete this comment.',
        'blind_success' => 'Comment has been blinded.',
        'blind_failed' => 'Failed to blind comment.',
        'restore_success' => 'Comment has been restored.',
        'restore_failed' => 'Failed to restore comment.',
        'blinded_comment_content' => 'This comment has been hidden according to the community guidelines.',
    ],

    // Additional comment messages
    'comments' => [
        'comments_disabled' => 'Comments are disabled for this board.',
    ],

    // Attachment operation messages
    'attachment' => [
        'uploaded' => 'File has been uploaded.',
        'upload_success' => 'File has been uploaded.',
        'upload_failed' => 'Failed to upload file.',
        'upload_disabled' => 'File upload is disabled for this board.',
        'deleted' => 'File has been deleted.',
        'delete_success' => 'File has been deleted.',
        'delete_failed' => 'Failed to delete file.',
        'delete_forbidden' => 'You do not have permission to delete this file.',
        'not_found' => 'File not found.',
        'file_not_found' => 'File not found.',
        'download_started' => 'File download has started.',
        'download_failed' => 'Failed to download file.',
        'permission_denied' => 'You do not have permission for this file.',
        'not_image' => 'Only image files can be previewed.',
        'preview_failed' => 'Failed to preview the image.',
        'reorder_success' => 'Attachment order has been updated.',
        'reorder_failed' => 'Failed to update attachment order.',
    ],

    // Permission messages (Role-Permission System)
    'permission' => [
        // General permission messages
        'denied' => 'Access denied.',
        'insufficient_permission' => 'Insufficient permission.',
        'login_required' => 'Login is required for this action.',

        // Post permissions
        'posts_read_denied' => 'You do not have permission to view posts.',
        'posts_write_denied' => 'You do not have permission to create posts.',
        'posts_update_denied' => 'You do not have permission to update posts.',
        'posts_delete_denied' => 'You do not have permission to delete posts.',
        'posts_manage_others_denied' => 'You do not have permission to manage others\' posts.',
        'posts_secret_read_denied' => 'You do not have permission to view secret posts.',

        // Comment permissions
        'comments_read_denied' => 'You do not have permission to view comments.',
        'comments_write_denied' => 'You do not have permission to create comments.',
        'comments_update_denied' => 'You do not have permission to update comments.',
        'comments_delete_denied' => 'You do not have permission to delete comments.',
        'comments_manage_others_denied' => 'You do not have permission to manage others\' comments.',

        // Attachment permissions
        'attachments_upload_denied' => 'You do not have permission to upload files.',
        'attachments_download_denied' => 'You do not have permission to download files.',

        // Board management permissions (module level)
        'boards_read_denied' => 'You do not have permission to view boards.',
        'boards_create_denied' => 'You do not have permission to create boards.',
        'boards_update_denied' => 'You do not have permission to update boards.',
        'boards_delete_denied' => 'You do not have permission to delete boards.',

        // Report management permissions (module level)
        'reports_view_denied' => 'You do not have permission to view reports.',
        'reports_manage_denied' => 'You do not have permission to manage reports.',
    ],

    // Confirmation messages
    'confirm' => [
        'delete_board' => 'Are you sure you want to delete this board? This action cannot be undone.',
        'delete_post' => 'Are you sure you want to delete this post?',
        'delete_comment' => 'Are you sure you want to delete this comment?',
        'delete_attachment' => 'Are you sure you want to delete this file?',
    ],

    // Info messages
    'info' => [
        'no_posts' => 'No posts found.',
        'no_comments' => 'No comments found.',
        'no_attachments' => 'No attachments found.',
        'login_required' => 'Login is required.',
        'secret_post' => 'This is a secret post.',
        'deleted_post' => 'This post has been deleted.',
        'deleted_comment' => 'This comment has been deleted.',
    ],

    // Error messages
    'error' => [
        'unexpected' => 'An unexpected error occurred.',
        'table_creation_failed' => 'Failed to create board table.',
        'table_deletion_failed' => 'Failed to delete board table.',
    ],

    // Exception messages
    'errors' => [
        'table_creation_failed' => 'Failed to create board table (:table): :error',
        'category_in_use' => 'Category :category is in use by :count post(s).',
        'board_not_found' => 'Board not found.',
        'post_not_found' => 'Post not found.',
        'permission_denied' => 'Permission denied.',
        'validation_failed' => 'Validation failed.',
        'duplicate_report' => 'You have already reported this content.',
    ],

    // Warning messages
    'warnings' => [
        'category_removal_attempted' => 'Attempted to remove category in use.',
    ],

    // Report operation messages
    'reports' => [
        'permission_denied' => 'You do not have permission to view reports.',
        'fetch_success' => 'Reports retrieved.',
        'fetch_failed' => 'Failed to retrieve reports.',
        'show_success' => 'Report details retrieved.',
        'show_failed' => 'Failed to retrieve report details.',
        'not_found' => 'Report not found.',
        'no_reports_selected' => 'No reports selected.',
        'status_counts_success' => 'Status counts retrieved.',
        'status_counts_failed' => 'Failed to retrieve status counts.',
        'status_updated' => 'Report status has been updated.',
        'status_update_failed' => 'Failed to update report status.',
        'bulk_status_updated' => 'Selected reports status has been updated.',
        'bulk_status_updated_with_count' => 'Changed :count report(s) to :status.',
        'manual_blind_restored_notice' => ':count manually blinded content(s) were also restored.',
        'bulk_status_update_failed' => 'Failed to update bulk status.',
        'bulk_status_change_blocked' => 'Some reports cannot be changed to the selected status.',
        'delete_failed' => 'Failed to delete report.',
        'delete_success' => 'Report has been deleted.',
        'create_failed' => 'Failed to submit report.',
        'create_success' => 'Report has been submitted.',
        'duplicate_report' => 'You have already reported this content.',
        'report_disabled' => 'Report feature is disabled for this board.',
        'target_not_reportable' => 'This content cannot be reported.',
        'cannot_report_own' => 'You cannot report your own content.',
        'post_not_found' => 'Post not found.',
        'comment_not_found' => 'Comment not found.',
        'error_404' => 'Report not found.',
        'cannot_change_to_same_status' => 'Already in this status.',
        'invalid_status_transition_with_labels' => 'Cannot change from :from to :to.',
        'cannot_change_deleted_status' => 'Cannot change status of permanently deleted reports.',
        // Report undo messages
        'undo_success' => 'Status change has been undone.',
        'undo_failed' => 'Failed to undo status change.',
        'no_history_to_undo' => 'No history available to undo.',
        'already_undone' => 'This action has already been undone.',
        'undo_reason' => 'Restored from :from to :to',
        // Report reason format
        'reason_count_format' => '{reason} {count}',
        'reason_others_format' => '+ {count} more',
        // Content status change reasons by report processing
        'restore_by_report' => 'Restored by report processing',
        'blind_by_report' => 'Blinded by report processing',
        'blind_by_auto_hide' => 'Automatically blinded due to accumulated reports.',
        // Modal notice messages
        'blinded_will_be_public' => '{count} blinded content(s) will be made public.',
        'already_reported' => 'You have already reported this content.',
    ],

    // Board type management messages
    'board_type' => [
        'list' => 'Board types retrieved.',
        'created' => 'Board type has been created.',
        'updated' => 'Board type has been updated.',
        'deleted' => 'Board type has been deleted.',
        'not_found' => 'Board type not found.',
        'delete_in_use' => 'Cannot delete board type in use by :count board(s).',
        'delete_is_default' => 'Cannot delete the default board type.',
    ],

    // User activities messages
    'user_activities' => [
        'fetch_success' => 'User activities fetched successfully.',
        'fetch_failed' => 'Failed to fetch user activities.',
        'stats_success' => 'Activity statistics fetched successfully.',
        'stats_failed' => 'Failed to fetch activity statistics.',
    ],

    // Mail template management messages
    'mail_template_fetch_success' => 'Mail templates retrieved.',
    'mail_template_fetch_failed' => 'Failed to retrieve mail templates.',
    'mail_template_save_success' => 'Mail template has been saved.',
    'mail_template_save_error' => 'Failed to save mail template.',
    'mail_template_toggle_success' => 'Mail template status has been changed.',
    'mail_template_toggle_failed' => 'Failed to change mail template status.',
    'mail_template_reset_success' => 'Mail template has been reset to default.',
    'mail_template_reset_failed' => 'Failed to reset mail template.',
    'mail_template_reset_no_default' => 'Default template data not found.',
    'mail_template_preview_success' => 'Mail template preview generated.',
    'mail_template_preview_failed' => 'Failed to generate mail template preview.',

    // Table related messages
    'invalid_slug_format' => 'Invalid slug format. Must start with lowercase letter and contain only lowercase letters, numbers, and hyphens. (slug: :slug)',
    'invalid_slug_length' => 'Invalid slug length. Must be between 1-50 characters. (slug: :slug)',
    'table_already_exists' => 'Table already exists. (table: :table)',
    'posts_table_not_found' => 'Posts table not found. (table: :table)',
    'table_creation_failed' => 'Failed to create table. (table: :table, error: :error)',
    'table_drop_failed' => 'Failed to drop table. (slug: :slug, error: :error)',

    // Common
    'common' => [
        'guest' => 'Guest',
        'status' => [
            'published' => 'Published',
            'blinded' => 'Blinded',
            'deleted' => 'Deleted',
        ],
        'blind_type' => [
            'auto' => 'Auto',
            'manual' => 'Manual',
        ],
    ],
    'inquiry' => [
        'default_title' => 'Product Inquiry',
    ],
];
