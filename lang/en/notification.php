<?php

return [
    // Notification definitions
    'definition_list_success' => 'Notification definitions loaded successfully.',
    'definition_list_failed' => 'Failed to load notification definitions.',
    'definition_show_success' => 'Notification definition loaded successfully.',
    'definition_show_failed' => 'Failed to load notification definition.',
    'definition_updated' => 'Notification definition has been updated.',
    'definition_update_failed' => 'Failed to update notification definition.',
    'definition_toggled' => 'Notification definition active status has been changed.',
    'definition_toggle_failed' => 'Failed to change notification definition active status.',
    'definition_not_found' => 'Notification definition not found.',
    'definition_reset' => 'All templates of the notification definition have been reset to defaults.',
    'definition_reset_failed' => 'Failed to reset notification definition.',

    // Channel dispatch skip
    'channel_skipped_no_template' => 'Skipped :channel channel for :type — no active template found.',
    'channel_disabled_by_extension' => 'Skipped because the channel is disabled in extension settings.',

    // Notification templates
    'template_updated' => 'Notification template has been updated.',
    'template_update_failed' => 'Failed to update notification template.',
    'template_toggled' => 'Notification template active status has been changed.',
    'template_toggle_failed' => 'Failed to change notification template active status.',
    'template_reset' => 'Notification template has been reset to default.',
    'template_reset_failed' => 'Failed to reset notification template to default.',
    'template_inactive' => 'Notification template for this channel is inactive.',
    'template_not_found' => 'Notification template for this channel not found.',
    'default_data_not_found' => 'Default template data not found.',

    // Preview
    'preview_success' => 'Preview generated successfully.',
    'preview_failed' => 'Failed to generate preview.',

    // Channels
    'channels_success' => 'Notification channels loaded successfully.',
    'channels_failed' => 'Failed to load notification channels.',

    // Field names
    'definition' => 'Notification Definition',
    'subject' => 'Subject',
    'body' => 'Body',

    // Channel Readiness
    'readiness' => [
        'mail_from_address_not_configured' => 'Sender email address is not configured.',
        'mail_smtp_host_empty' => 'SMTP host is not configured.',
        'mail_smtp_port_empty' => 'SMTP port is not configured.',
        'mail_mailgun_domain_empty' => 'Mailgun domain is not configured.',
        'mail_mailgun_secret_empty' => 'Mailgun secret is not configured.',
        'mail_ses_key_empty' => 'SES key is not configured.',
        'mail_ses_secret_empty' => 'SES secret is not configured.',
        'database_table_missing' => 'Notifications table does not exist.',
        'unknown' => 'Channel configuration is incomplete.',
    ],

    // User notifications
    'user' => [
        'list_success' => 'Notifications loaded successfully.',
        'list_failed' => 'Failed to load notifications.',
        'unread_count_success' => 'Unread count loaded successfully.',
        'unread_count_failed' => 'Failed to load unread count.',
        'read_success' => 'Notification marked as read.',
        'read_failed' => 'Failed to mark notification as read.',
        'read_all_success' => 'All notifications marked as read.',
        'read_all_failed' => 'Failed to mark all notifications as read.',
        'delete_success' => 'Notification deleted successfully.',
        'delete_failed' => 'Failed to delete notification.',
        'delete_all_success' => 'All notifications deleted.',
        'delete_all_failed' => 'Failed to delete all notifications.',
        'not_found' => 'Notification not found.',
    ],
];
