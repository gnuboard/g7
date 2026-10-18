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
    'definition_created' => 'The identity policy message has been added.',
    'definition_create_failed' => 'Failed to add the identity policy message.',
    'definition_deleted' => 'The identity policy message has been deleted.',
    'definition_delete_failed' => 'Failed to delete the identity policy message.',
    'definition_delete_forbidden' => 'Only identity policy messages added by an administrator can be deleted.',

    // Channel dispatch skip
    'channel_skipped_no_template' => 'Skipped :channel channel for :type — no active template found.',
    'channel_disabled_by_extension' => 'Skipped because the channel is disabled in extension settings.',
    'channel_guest_not_allowed' => 'Skipped because this channel does not allow sending to guests.',
    'channel_unavailable' => 'Skipped because the extension providing this channel is inactive or removed.',

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

    // Recipient rule names written into skip reasons
    'recipient_rules' => [
        'trigger_user' => 'Event trigger user',
        'related_user' => 'Related user (:relation)',
        'role' => 'Role (:roles)',
        'specific_users' => 'Specific users (:count)',
        'exclude_trigger_user' => ':rule, excluding the event trigger user',
        'legacy' => 'Default recipients of the definition',
    ],

    // Skipped delivery reasons
    'skipped' => [
        'no_recipient_resolved' => 'No recipient matched the recipient rules, so nothing was sent. Rules applied: :rule_names (rule codes: :rules)',
        'guest_email_empty' => 'The guest recipient has no email address, so nothing was sent. (Recipient rules: :rules)',
        'withdrawn_recipient' => 'The member has withdrawn, so nothing was sent over external channels (mail/SMS/AlimTalk). Withdrawal anonymises the contact details, leaving no deliverable address.',
        'member_account_missing' => 'The member this notification was meant for no longer has an account, so nothing was sent. Contact details kept in other records are not used as a substitute. (Recipient rules: :rules)',
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
        'attributes' => [
            'recipients' => 'recipient rules',
            'recipient_type' => 'recipient type',
            'recipient_value' => 'recipient value',
            'recipient_relation' => 'related user relation',
            'recipient_exclude_trigger_user' => 'exclude trigger user',
            // Display names of multilingual fields — used as the field name in the required-locale message when saving a template
            'subject' => 'notification subject',
            'body' => 'notification body',
        ],
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
    // Notification template editor "Available variables" descriptions — common: default per variable key, by_type: variables whose meaning differs for that notification, identity: shared identity verification variables. Missing keys fall back to the declared string.
    'variables' => [
        'common' => [
            'name' => 'Recipient name',
            'app_name' => 'Site name',
            'action_url' => 'Login page URL',
            'site_url' => 'Site URL',
            'expire_minutes' => 'Link expiry time (minutes)',
            'url_count' => 'Total number of URLs generated',
            'child_count' => 'Number of sitemap files generated',
            'error' => 'Failure reason message',
            'dormant_days' => 'Days of inactivity before dormancy',
            'scheduled_date' => 'Scheduled dormancy date',
            'run_type' => 'Aggregation type name (e.g. daily aggregation)',
            'period' => 'Target period',
            'diagnostics_url' => 'Collection diagnostics page URL',
            'visits' => 'Number of visits',
            'actions' => 'Current action row count',
            'threshold_visits' => 'Visit warning threshold',
            'threshold_actions' => 'Action warning threshold',
            'retention_days' => 'Current ledger retention days',
            'settings_url' => 'Retention settings page URL',
            'visitor_key' => 'Visitor key (first 8 characters)',
            'limit' => 'Action limit per visit',
            'ip_display' => 'Display IP (masked depending on settings)',
            'period_label' => 'Period label (e.g. 2026-09-08 ~ 2026-09-14)',
            'visitors' => 'Number of unique visitors',
            'pageviews' => 'Number of page views',
            'change_pct' => 'Change from the previous period (e.g. +12.5%; - if the previous period was 0)',
            'top_pages' => 'Top pages table (HTML)',
            'top_referrers' => 'Referrers table (HTML)',
            'report_url' => 'Statistics overview page URL',
            'expires_at' => 'Password expiry date',
            'days_left' => 'Days until expiry',
            'expired_at' => 'Password expiry date',
        ],
        'by_type' => [
            'reset_password' => [
                'action_url' => 'Password reset URL',
            ],
            'sitemap_regenerated' => [
                'action_url' => 'SEO settings page URL',
            ],
            'sitemap_regenerate_failed' => [
                'action_url' => 'SEO settings page URL',
            ],
            'dormant_withdraw_notice' => [
                'scheduled_date' => 'Scheduled withdrawal date',
            ],
            'analytics_ledger_limit_exceeded' => [
                'visits' => 'Current visit row count',
            ],
            'analytics_visit_limit_exceeded' => [
                'actions' => 'Number of recorded actions',
            ],
            'analytics_monthly_report' => [
                'period_label' => 'Period label (e.g. 2026-08-01 ~ 2026-08-31)',
            ],
            'password_expiry_notice' => [
                'action_url' => 'Password change page URL',
            ],
        ],
        'identity' => [
            'code' => 'Verification code (text_code flow)',
            'action_url' => 'Verification link URL (link flow)',
            'expire_minutes' => 'Minutes until expiry',
            'purpose_label' => 'Verification purpose label (multilingual)',
            'app_name' => 'Site name',
            'site_url' => 'Site URL',
            'recipient_email' => 'Recipient email',
        ],
    ],
];
