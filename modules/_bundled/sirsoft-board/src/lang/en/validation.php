<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Board Validation Messages
    |--------------------------------------------------------------------------
    */

    // bulk apply fields validation messages
    'fields_invalid' => 'The selected :value field is not allowed.',

    // slug validation messages
    'slug' => [
        'required' => 'Board slug is required.',
        'format' => 'Board slug must start with a lowercase letter and can only contain lowercase letters, numbers, and hyphens.',
        'unique' => 'Board slug is already in use.',
        'reserved' => ':value is a reserved slug. Please use a different name.',
        'max' => 'Board slug cannot exceed :max characters.',
    ],

    // name validation messages
    'name' => [
        'required' => 'Board name is required.',
        'string' => 'Board name must be a string.',
        'max' => 'Board name cannot exceed :max characters.',
    ],

    // type validation messages
    'type' => [
        'required' => 'Board type is required.',
    ],

    // list settings validation messages
    'per_page' => [
        'required' => 'Posts per page is required.',
        'min' => 'Posts per page must be at least :min.',
        'max' => 'Posts per page cannot exceed :max.',
    ],
    'per_page_mobile' => [
        'required' => 'Posts per page (mobile) is required.',
        'min' => 'Posts per page (mobile) must be at least :min.',
        'max' => 'Posts per page (mobile) cannot exceed :max.',
    ],

    // sort settings validation messages
    'order_by' => [
        'required' => 'Sort order is required.',
        'in' => 'Sort order must be one of: created_at, view_count, title, author.',
    ],
    'order_direction' => [
        'required' => 'Sort direction is required.',
        'in' => 'Sort direction must be either ASC or DESC.',
    ],

    // categories validation messages
    'categories' => [
        'array' => 'Categories must be an array.',
        'item_max' => 'Category name cannot exceed :max characters.',
    ],

    // feature settings validation messages
    'show_view_count' => [
        'required' => 'Show view count is required.',
    ],
    'secret_mode' => [
        'required' => 'Secret mode is required.',
        'in' => 'Secret mode must be one of: disabled, enabled, always.',
    ],
    'use_comment' => [
        'required' => 'Comment usage is required.',
    ],
    'use_reply' => [
        'required' => 'Reply usage is required.',
    ],
    'use_report' => [
        'required' => 'Report feature usage is required.',
    ],

    // file upload validation messages
    'use_file_upload' => [
        'required' => 'File upload usage is required.',
    ],
    'max_file_size' => [
        'min' => 'Maximum file size must be at least :min MB.',
        'max' => 'Maximum file size cannot exceed :max MB.',
    ],
    'max_file_count' => [
        'min' => 'Maximum file count must be at least :min.',
        'max' => 'Maximum file count cannot exceed :max.',
    ],

    // permissions validation messages
    'permissions' => [
        'required' => 'Permissions are required.',
        'roles_required' => 'Each permission requires at least one role. Please set roles for: :permissions',
        'roles' => [
            'required' => 'Please select roles for this permission.',
            'min' => 'Please select at least one role for this permission.',
            'exists' => 'Role does not exist.',
        ],
    ],

    // reply/comment depth validation messages
    'max_reply_depth' => [
        'min' => 'Max reply depth must be at least :min.',
        'max' => 'Max reply depth can be up to :max.',
    ],
    'max_comment_depth' => [
        'min' => 'Max comment depth must be at least :min.',
        'max' => 'Max comment depth can be up to :max.',
    ],

    // notification settings validation messages
    'notify_admin_on_post' => [
        'required' => 'Admin notification on post is required.',
    ],
    'notify_author' => [
        'required' => 'Author email notification is required.',
    ],
    'notify_author_channels' => [
        'array' => 'Author notification channels must be an array.',
    ],
    'notify_admin_on_post_channels' => [
        'array' => 'Admin notification channels must be an array.',
    ],

    // title length limit validation messages
    'min_title_length' => [
        'min' => 'Minimum title length must be at least :min characters.',
        'max' => 'Minimum title length cannot exceed :max characters.',
    ],
    'max_title_length' => [
        'min' => 'Maximum title length must be at least :min characters.',
        'max' => 'Maximum title length cannot exceed :max characters.',
    ],

    // content length limit validation messages
    'min_content_length' => [
        'min' => 'Minimum content length must be at least :min characters.',
        'max' => 'Minimum content length cannot exceed :max characters.',
    ],
    'max_content_length' => [
        'min' => 'Maximum content length must be at least :min characters.',
        'max' => 'Maximum content length cannot exceed :max characters.',
    ],

    // comment length limit validation messages
    'min_comment_length' => [
        'min' => 'Minimum comment length must be at least :min characters.',
        'max' => 'Minimum comment length cannot exceed :max characters.',
    ],
    'max_comment_length' => [
        'min' => 'Maximum comment length must be at least :min characters.',
        'max' => 'Maximum comment length cannot exceed :max characters.',
    ],

    // security settings validation messages
    'blocked_keywords' => [
        'string' => 'Blocked keywords must be a string.',
        'max' => 'Blocked keywords cannot exceed :max characters.',
    ],

    // cooldown validation messages
    'cooldown_required' => 'Please wait :time before posting again.',
    'cooldown_required_report' => 'Reports can be submitted at :time intervals. Please try again later.',
    'cooldown_duration' => [
        'seconds' => ':seconds seconds',
        'minutes' => ':minutes minutes',
        'minutes_seconds' => ':minutes minutes :seconds seconds',
        'hours' => ':hours hours',
        'hours_minutes' => ':hours hours :minutes minutes',
    ],

    // Board validation (backward compatibility)
    'board' => [
        'name' => [
            'required' => 'Board name is required.',
            'string' => 'Board name must be a string.',
            'max' => 'Board name cannot exceed :max characters.',
        ],
        'slug' => [
            'required' => 'Board slug is required.',
            'string' => 'Board slug must be a string.',
            'max' => 'Board slug cannot exceed :max characters.',
            'alpha_dash' => 'Board slug may only contain letters, numbers, dashes, and underscores.',
            'unique' => 'Board slug is already in use.',
            'regex' => 'Board slug must start with a letter.',
        ],
        'type' => [
            'required' => 'Board type is required.',
            'in' => 'Invalid board type.',
        ],
        'description' => [
            'string' => 'Board description must be a string.',
            'max' => 'Board description cannot exceed :max characters.',
        ],
        'per_page' => [
            'integer' => 'Posts per page must be an integer.',
            'min' => 'Posts per page must be at least :min.',
            'max' => 'Posts per page cannot exceed :max.',
        ],
        'per_page_mobile' => [
            'integer' => 'Posts per page (mobile) must be an integer.',
            'min' => 'Posts per page (mobile) must be at least :min.',
            'max' => 'Posts per page (mobile) cannot exceed :max.',
        ],
        'secret_mode' => [
            'in' => 'Invalid secret mode.',
        ],
    ],

    // Post validation
    'post' => [
        'title' => [
            'required' => 'Title is required.',
            'string' => 'Title must be a string.',
            'min' => 'Title must be at least :min characters.',
            'max' => 'Title cannot exceed :max characters.',
        ],
        'content' => [
            'required' => 'Content is required.',
            'string' => 'Content must be a string.',
            'min' => 'Content must be at least :min characters.',
            'max' => 'Content cannot exceed :max characters.',
        ],
        'category' => [
            'max' => 'Category cannot exceed :max characters.',
        ],
        'category_id' => [
            'exists' => 'Category does not exist.',
        ],
        'is_secret' => [
            'boolean' => 'Secret flag must be true or false.',
        ],
        'secret_password' => [
            'required_if' => 'Secret password is required.',
            'string' => 'Secret password must be a string.',
            'min' => 'Secret password must be at least :min characters.',
            'max' => 'Secret password cannot exceed :max characters.',
        ],
        'parent_id' => [
            'exists' => 'Parent post does not exist.',
            'not_found' => 'Parent post not found.',
            'blinded' => 'Cannot create reply on blinded post.',
            'deleted' => 'Cannot create reply on deleted post.',
            'depth_exceeded' => 'This board allows replies up to :max level(s) only.',
            'notice_not_allowed' => 'Replies cannot be created on notice posts.',
        ],
        'reply_not_allowed' => 'Reply feature is disabled for this board.',
        'status' => [
            'in' => 'Invalid post status.',
        ],
        'user_id' => [
            'exists' => 'User does not exist.',
        ],
        'author_name' => [
            'required' => 'Guest users must enter author name.',
            'max' => 'Author name cannot exceed :max characters.',
        ],
        'password' => [
            'required' => 'Guest users must enter password.',
            'min' => 'Password must be at least :min characters.',
        ],
        'is_notice' => [
            'guest_not_allowed' => 'Guest users cannot create notice posts.',
        ],
        'blocked_keyword' => 'Content contains blocked keyword: ":keyword".',
        'files' => [
            'array' => 'Attachments must be an array.',
            'max' => 'You can upload up to :max files only.',
            'file' => 'Invalid file.',
            'file_max' => 'File size exceeds the allowed limit.',
            'mimes' => 'File type is not allowed.',
        ],
    ],

    // Field attributes
    'attributes' => [
        'settings' => [
            // basic_defaults
            'basic_defaults.type' => 'Board Type',
            'basic_defaults.per_page' => 'Posts Per Page',
            'basic_defaults.per_page_mobile' => 'Posts Per Page (Mobile)',
            'basic_defaults.order_by' => 'Sort By',
            'basic_defaults.order_direction' => 'Sort Direction',
            'basic_defaults.secret_mode' => 'Secret Mode',
            'basic_defaults.use_comment' => 'Use Comment',
            'basic_defaults.use_reply' => 'Use Reply',
            'basic_defaults.max_reply_depth' => 'Max Reply Depth',
            'basic_defaults.max_comment_depth' => 'Max Comment Depth',
            'basic_defaults.comment_order' => 'Comment Order',
            'basic_defaults.show_view_count' => 'Show View Count',
            'basic_defaults.use_report' => 'Use Report',
            'basic_defaults.min_title_length' => 'Min Title Length',
            'basic_defaults.max_title_length' => 'Max Title Length',
            'basic_defaults.min_content_length' => 'Min Content Length',
            'basic_defaults.max_content_length' => 'Max Content Length',
            'basic_defaults.min_comment_length' => 'Min Comment Length',
            'basic_defaults.max_comment_length' => 'Max Comment Length',
            'basic_defaults.use_file_upload' => 'Use File Upload',
            'basic_defaults.max_file_size' => 'Max File Size',
            'basic_defaults.max_file_count' => 'Max File Count',
            'basic_defaults.allowed_extensions' => 'Allowed Extensions',
            'basic_defaults.notify_admin_on_post' => 'Notify Admin on Post',
            'basic_defaults.notify_author' => 'Notify Author',
            'basic_defaults.new_display_hours' => 'New Display Hours',
            'basic_defaults.default_board_permissions' => 'Default Board Permissions',
            // report_policy
            'report_policy.auto_hide_threshold' => 'Auto Hide Threshold',
            'report_policy.auto_hide_target' => 'Auto Hide Target',
            'report_policy.daily_report_limit' => 'Daily Report Limit',
            'report_policy.rejection_limit_count' => 'Rejection Limit Count',
            'report_policy.rejection_limit_days' => 'Rejection Limit (Days)',
            // spam_security
            'spam_security.blocked_keywords' => 'Blocked Keywords',
            'spam_security.post_cooldown_seconds' => 'Post Cooldown (Seconds)',
            'spam_security.comment_cooldown_seconds' => 'Comment Cooldown (Seconds)',
            'spam_security.report_cooldown_seconds' => 'Report Cooldown (Seconds)',
            'spam_security.view_count_cache_ttl' => 'View Count Cache TTL (Seconds)',
            // notification_channels
            'notification_channels.notify_admin_on_post_channels' => 'Admin Notification Channels',
            'notification_channels.notify_author_channels' => 'Author Notification Channels',
        ],
        'post' => [
            'title' => 'Title',
            'content' => 'Content',
            'category' => 'Category',
            'is_notice' => 'Notice',
            'is_secret' => 'Secret',
            'content_mode' => 'Content Mode',
            'status' => 'Status',
            'user_id' => 'User ID',
            'author_name' => 'Author Name',
            'password' => 'Password',
            'parent_id' => 'Parent Post',
            'files' => 'Attachments',
            'file' => 'Attachment',
        ],
        'comment' => [
            'content' => 'Comment Content',
            'author_name' => 'Author Name',
            'password' => 'Password',
            'is_secret' => 'Secret Comment',
            'parent_id' => 'Parent Comment',
            'user_id' => 'User ID',
            'ip_address' => 'IP Address',
            'status' => 'Status',
        ],
        'report' => [
            'reason_type' => 'Report Type',
            'reason_detail' => 'Detailed Reason',
            'status' => 'Report Status',
            'process_note' => 'Process Note',
            'ids' => 'Report IDs',
        ],
        'blind' => [
            'reason' => 'Blind Reason',
        ],
        'restore' => [
            'reason' => 'Restore Reason',
        ],
    ],

    // Blind validation messages
    'blind' => [
        'reason' => [
            'required' => 'Blind reason is required.',
            'min' => 'Blind reason must be at least :min characters.',
            'max' => 'Blind reason cannot exceed :max characters.',
            'string' => 'Blind reason must be a string.',
        ],
    ],

    // Restore validation messages
    'restore' => [
        'reason' => [
            'required' => 'Restore reason is required.',
            'min' => 'Restore reason must be at least :min characters.',
            'max' => 'Restore reason cannot exceed :max characters.',
            'string' => 'Restore reason must be a string.',
        ],
    ],

    // Comment validation
    'comment' => [
        'content' => [
            'required' => 'Comment content is required.',
            'string' => 'Comment content must be a string.',
            'min' => 'Comment content must be at least :min characters.',
            'max' => 'Comment content cannot exceed :max characters.',
        ],
        'post_id' => [
            'not_found' => 'Post not found.',
            'blinded' => 'Cannot create comment on blinded post.',
            'deleted' => 'Cannot create comment on deleted post.',
        ],
        'parent_id' => [
            'exists' => 'Parent comment does not exist.',
            'integer' => 'Parent comment ID must be an integer.',
            'not_found' => 'Parent comment not found.',
            'blinded' => 'Cannot create reply on blinded comment.',
            'deleted' => 'Cannot create reply on deleted comment.',
        ],
        'depth' => [
            'integer' => 'Comment depth must be an integer.',
            'min' => 'Comment depth must be at least :min.',
            'max' => 'Replies can only be nested up to :max levels.',
            'exceeded' => 'This board allows replies up to :max level(s) only.',
        ],
        'user_id' => [
            'exists' => 'User does not exist.',
        ],
        'author_name' => [
            'required' => 'Guest users must enter an author name.',
            'max' => 'Author name cannot exceed :max characters.',
        ],
        'password' => [
            'required' => 'Please enter a password.',
            'min' => 'Password must be at least :min characters.',
        ],
        'ip_address' => [
            'required' => 'IP address is required.',
        ],
        'blocked_keyword' => 'Content contains blocked keyword: ":keyword".',
    ],

    // Attachment validation
    'attachment' => [
        'file' => [
            'required' => 'File is required.',
            'file' => 'Invalid file.',
            'max' => 'File size cannot exceed :max KB.',
            'mimes' => 'File type is not allowed.',
        ],
        // Flat keys for FormRequest messages()
        'file_required' => 'File is required.',
        'file_invalid' => 'Invalid file.',
        'file_max' => 'File size cannot exceed :max MB.',
        'file_mimes' => 'File type is not allowed.',
        'post_id_required' => 'Post ID is required.',
        'post_id_invalid' => 'Invalid post ID.',
        'max_count_exceeded' => 'Maximum number of files (:max) exceeded.',
        'extension_not_allowed' => 'File extension is not allowed: :extension',
        // Reorder validation messages
        'orders_required' => 'Order information is required.',
        'orders_array' => 'Order information must be an array.',
        'order_id_required' => 'Attachment ID is required.',
        'order_id_integer' => 'Attachment ID must be an integer.',
        'order_value_required' => 'Order value is required.',
        'order_value_integer' => 'Order value must be an integer.',
    ],

    // Category validation
    'category' => [
        'name' => [
            'required' => 'Category name is required.',
            'string' => 'Category name must be a string.',
            'max' => 'Category name cannot exceed :max characters.',
        ],
        'max_count_exceeded' => 'Maximum number of categories (:max) exceeded.',
    ],

    // manager settings validation messages
    'board_manager_ids' => [
        'required' => 'Board manager is required.',
        'min' => 'At least :min board manager must be assigned.',
    ],

    // Custom Rule messages
    'category_in_use' => 'The ":category" category is currently in use by :count post(s).',
    'board_type_invalid' => 'Invalid board type. Available types: :types',

    // Board type management validation
    'board_type' => [
        'slug_required' => 'Slug is required.',
        'slug_format' => 'Slug must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens.',
        'slug_unique' => 'This slug is already in use.',
        'name_required' => 'Type name is required.',
        'name_ko_required' => 'Korean type name is required.',
    ],

    // Multilingual field validation
    'multilingual_default_locale_required' => 'The :locale language value is required.',

    // Permission validation
    'permission' => [
        'invalid_role' => 'Invalid role: :role',
    ],

    // Permission names (for validation messages)
    'permission_names' => [
        'admin' => [
            'posts' => [
                'read' => 'View Posts (Admin)',
                'write' => 'Create/Edit/Delete Posts (Admin)',
                'read-secret' => 'View Secret Posts (Admin)',
            ],
            'comments' => [
                'read' => 'View Comments (Admin)',
                'write' => 'Create/Edit/Delete Comments (Admin)',
            ],
            'manage' => 'Manage Others\' Posts/Comments (Admin)',
            'attachments' => [
                'upload' => 'Upload Files (Admin)',
                'download' => 'Download Files (Admin)',
            ],
        ],
        'posts' => [
            'read' => 'View Posts',
            'write' => 'Create Posts',
        ],
        'comments' => [
            'read' => 'View Comments',
            'write' => 'Create Comments',
        ],
        'attachments' => [
            'upload' => 'Upload Files',
            'download' => 'Download Files',
        ],
        'manager' => 'Board Manager',
    ],

    // Permission field attribute suffix
    'role_field_suffix' => 'Roles',

    // Report validation messages
    'report' => [
        'status' => [
            'required' => 'Report status is required.',
            'in' => 'Invalid report status.',
        ],
        'process_note' => [
            'max' => 'Process note cannot exceed :max characters.',
        ],
        'ids' => [
            'required' => 'Report ID is required.',
            'array' => 'Report IDs must be an array.',
            'min' => 'At least one report must be selected.',
            'integer' => 'Report ID must be an integer.',
            'exists' => 'Report does not exist.',
        ],
        'reason_type' => [
            'required' => 'Report reason is required.',
            'in' => 'Invalid report reason.',
        ],
        'reason_detail' => [
            'required' => 'Report detail is required.',
            'min' => 'Report detail must be at least :min characters.',
            'max' => 'Report detail cannot exceed :max characters.',
        ],
        'daily_limit_exceeded' => 'You have exceeded the daily report limit (:limit reports).',
        'rejection_limit_exceeded' => 'Reporting is restricted due to :count rejections within the last :days days. Please try again later.',
    ],
];
