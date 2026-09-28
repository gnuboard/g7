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
    'channel_guest_not_allowed' => 'Skipped because this channel does not allow sending to guests.',

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

    // Skipped delivery reasons
    'skipped' => [
        'no_recipient_resolved' => 'No recipient matched the recipient rules (:rules), so nothing was sent.',
        'guest_email_empty' => 'The guest recipient has no email address, so nothing was sent. (Recipient rules: :rules)',
        'withdrawn_recipient' => 'The member has withdrawn, so nothing was sent over external channels (mail/SMS/AlimTalk). Withdrawal anonymises the contact details, leaving no deliverable address.',
        'member_account_missing' => 'The member this notification was meant for no longer has an account, so nothing was sent. Contact details kept elsewhere (such as on an order) are not used as a substitute. (Recipient rules: :rules)',
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

    // Notification channel metadata (referenced by config/notification.php default_channels)
    'validation' => [
        // 역할 수신자는 다중 선택이 되면서 값이 배열일 수 있다. 객체·숫자가 섞이면
        // 저장은 되고 발송 시점에 수신자 0명으로만 드러나므로 여기서 세운다.
        'role_value_shape' => 'A role recipient value must be an identifier string or an array of identifier strings.',
    ],

    'channels' => [
        'core_default' => 'Core default channel',
        'mail' => [
            'name' => 'Email',
            'description' => 'Send notification via email',
        ],
        'database' => [
            'name' => 'Site Notification',
            'description' => 'Show in site notification center',
        ],
    ],
];
