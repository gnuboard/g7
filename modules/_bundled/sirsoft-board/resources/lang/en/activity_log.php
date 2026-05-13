<?php

return [
    'description' => [
        // Board management (Admin)
        'board_index' => 'Board list viewed',
        'board_show' => 'Board details viewed (:board_name)',
        'board_create' => 'Board created (:board_name)',
        'board_update' => 'Board updated (:board_name)',
        'board_delete' => 'Board deleted (:board_name)',
        'board_copy' => 'Board copied (:board_name)',
        'board_add_to_menu' => 'Board added to menu (:board_name)',

        // Board type (Admin)
        'board_type_index' => 'Board type list viewed',
        'board_type_show' => 'Board type details viewed (:type_name)',
        'board_type_create' => 'Board type created (:type_name)',
        'board_type_update' => 'Board type updated (:type_name)',
        'board_type_delete' => 'Board type deleted (:type_name)',

        // Posts
        'post_create' => 'Post created (Board: :board_name, Title: :title)',
        'post_update' => 'Post updated (Board: :board_name, Title: :title)',
        'post_delete' => 'Post deleted (Board: :board_name, ID: :post_id)',
        'post_blind' => 'Post blinded (Board: :board_name, ID: :post_id)',
        'post_restore' => 'Post restored (Board: :board_name, ID: :post_id)',

        // Comments
        'comment_create' => 'Comment created (Board: :board_name, Post: :post_id)',
        'comment_update' => 'Comment updated (ID: :comment_id)',
        'comment_delete' => 'Comment deleted (ID: :comment_id)',
        'comment_blind' => 'Comment blinded (ID: :comment_id)',
        'comment_restore' => 'Comment restored (ID: :comment_id)',

        // Attachments
        'board_attachment_upload' => 'Attachment uploaded (Post: :post_id)',
        'board_attachment_delete' => 'Attachment deleted (Post: :post_id)',
        'board_attachment_reorder' => 'Attachment order changed (Post: :post_id)',

        // Report management (Admin)
        'report_create' => 'Report created (ID: :report_id)',
        'report_update_status' => 'Report status changed (ID: :report_id)',
        'report_bulk_update_status' => 'Report statuses bulk changed (:count items)',
        'report_delete' => 'Report deleted (ID: :report_id)',
        'report_restore_content' => 'Report content restored (ID: :report_id)',
        'report_blind_content' => 'Report content blinded (ID: :report_id)',
        'report_delete_content' => 'Report content deleted (ID: :report_id)',

        // Board settings
        'board_settings_index' => 'Board settings viewed',
        'board_settings_bulk_apply' => 'Board settings bulk applied',
    ],
];
