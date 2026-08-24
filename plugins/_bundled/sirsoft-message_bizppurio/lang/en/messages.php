<?php

return [
    // 발송 채널 (DispatchChannel enum)
    'channel' => [
        'sms' => 'SMS',
        'lms' => 'LMS',
        'alimtalk' => 'Alimtalk',
    ],

    // 채널 그룹 라벨 (SMS/LMS 를 "문자" 로 묶음 — 잔액부족 알림 등 채널군 표기용)
    'channel_group' => [
        'text' => 'SMS',
        'alimtalk' => 'Alimtalk',
    ],

    // 발송 상태 (DispatchStatus enum)
    'status' => [
        'pending' => 'Pending',
        'sent' => 'Sending',
        'success' => 'Success',
        'failed' => 'Failed',
    ],

    // 발송 출처 (DispatchSource enum)
    'source' => [
        'auto' => 'Automatic',
        'manual' => 'Manual',
        'bulk' => 'Bulk',
    ],

    // 결과코드 분류 (ResultCategory enum)
    'result_category' => [
        'success' => 'Success',
        'retry' => 'Retry',
        'permanent_failure' => 'Permanent Failure',
        'balance_low' => 'Insufficient Balance',
    ],

    // 알림 채널 메타 (core.notification.filter_available_channels)
    'channels' => [
        'source_label' => 'Bizppurio',
        'sms' => [
            'name' => 'Bizppurio SMS',
            'description' => 'Send notifications as SMS/LMS text messages via Bizppurio.',
        ],
        'alimtalk' => [
            'name' => 'Bizppurio Alimtalk',
            'description' => 'Send notifications as Kakao Alimtalk messages via Bizppurio.',
        ],
    ],

    // 채널 준비 상태 사유 (core.notification.channel_readiness)
    'readiness' => [
        'sms_credentials_missing' => 'Please set the Bizppurio ID and password.',
        'sms_sender_number_missing' => 'Please set the sender number.',
        'alimtalk_api_key_missing' => 'Please set the Kakao management API key.',
        'alimtalk_sender_key_missing' => 'Please set the Alimtalk sender profile key.',
    ],

    // 설정 검증 — 운영(live) 환경 필수 자격증명 항목 라벨 (validation.attributes 병합용)
    'settings' => [
        'bizppurio_id_attribute' => 'Bizppurio ID',
        'password_attribute' => 'Password',
        'sender_number_attribute' => 'Sender Number',
    ],

    // webhook(URL PUSH) 리포트 수신
    'webhook' => [
        'received' => 'Report received.',
    ],

    // 발송 엔진 오류 (API 클라이언트·토큰·발송 Job)
    'error' => [
        'credentials_missing' => 'Please set the Bizppurio ID and password first.',
        'token_issue_failed' => 'Failed to issue the Bizppurio authentication token.',
        'token_issue_failed_with_reason' => 'Failed to issue the Bizppurio authentication token. (:reason)',
        'send_failed' => 'Failed to send the message.',
        'send_retryable' => 'Message delivery temporarily failed. (code: :code)',
        'invalid_response' => 'Unable to parse the Bizppurio response.',
        'connection_failed' => 'Unable to connect to the Bizppurio server. Please try again later.',
        'kakao_credentials_missing' => 'Please set the ID and API key to use the Kakao management API.',
        'kakao_request_failed' => 'The Kakao management API request failed.',
        'sender_key_missing' => 'Please set the alimtalk sender profile key first.',
        'image_unreadable' => 'The uploaded image file could not be read.',
    ],

    // Send skipped (channel driver send() precondition not met — recorded as "Failed" in core notification log)
    'send_skipped' => [
        'alimtalk_template_not_approved' => 'Skipped sending: no approved alimtalk template or alimtalk delivery is off. (notification type: :type)',
        'sms_template_missing' => 'Skipped sending: no SMS body is configured. (notification type: :type)',
        'recipient_phone_missing' => 'Skipped sending: recipient phone number is missing. (notification type: :type)',
        'message_body_empty' => 'Skipped sending: message body is empty. (notification type: :type)',
    ],

    // Notification template lifecycle (#597 — BizppurioTemplateController responses and state guards)
    'template' => [
        'created' => 'Notification template saved.',
        'updated' => 'Notification template updated.',
        'requested' => 'Inspection requested. The approval result syncs automatically, or use [Refresh] to check now.',
        'request_cancelled' => 'Inspection request cancelled.',
        'approval_cancelled' => 'Approval cancelled. Alimtalk delivery for this notification has stopped.',
        'released' => 'Dormant state released.',
        'synced' => 'Kakao inspection status synchronized.',
        'deleted' => 'Notification template deleted.',
        'image_uploaded' => 'Image uploaded.',
        'content_locked' => 'The alimtalk content cannot be edited in the current state (:status). Cancel the inspection request first, or cancel the approval if approved.',
        'content_missing' => 'Please compose the alimtalk template content first.',
        'request_not_allowed' => 'Inspection cannot be requested in the current state (:status).',
        'cancel_request_not_allowed' => 'The request cannot be cancelled because the template is not under inspection. (current: :status)',
        'cancel_approval_not_allowed' => 'Approval cannot be cancelled because the template is not approved. (current: :status)',
        'release_not_allowed' => 'The template is not dormant. (current: :status)',
        'code_generation_failed' => 'Failed to allocate a template code. Please try again later.',
    ],

    // Notification template validation (FormRequest — only documented limits; kapi is the final gate)
    'validation' => [
        'link_mo_required' => 'A web link (WL) button requires a mobile link.',
        'link_and_required' => 'An app link (AL) button requires an Android scheme.',
        'link_ios_required' => 'An app link (AL) button requires an iOS scheme.',
        'tel_number_required' => 'A phone (TN) button requires a phone number.',
        'plugin_id_required' => 'A plugin (P1–P3) button requires a plugin ID.',
        'highlight_title_too_long' => 'The item highlight title must be at most :max characters.',
        'highlight_description_too_long' => 'The item highlight description must be at most :max characters.',
        'image_ratio_invalid' => 'The image must have a 2:1 width-to-height ratio. (e.g. 1000×500px)',

        // Field labels used in validation messages (FormRequest::attributes)
        'attributes' => [
            'notification_type' => 'notification type',
            'content' => 'AlimTalk content',
            'template_name' => 'template name',
            'message_type' => 'message type',
            'emphasize_type' => 'emphasis type',
            'template_content' => 'body',
            'comment' => 'reviewer comment',
            'preview_message' => 'preview message',
            'category_code' => 'category',
            'security_flag' => 'secure template flag',
            'extra' => 'supplementary information',
            'title' => 'emphasis title',
            'subtitle' => 'emphasis subtitle',
            'header' => 'header',
            'image_name' => 'image file name',
            'image_url' => 'image URL',
            'item' => 'item list',
            'item_list' => 'items',
            'item_title' => 'item title',
            'item_description' => 'item description',
            'summary' => 'summary',
            'summary_title' => 'summary title',
            'summary_description' => 'summary description',
            'highlight' => 'highlight',
            'highlight_title' => 'highlight title',
            'highlight_description' => 'highlight description',
            'highlight_image_url' => 'highlight image URL',
            'represent_link' => 'representative link',
            'buttons' => 'buttons',
            'button_name' => 'button label',
            'button_link_type' => 'button link type',
            'quick_replies' => 'quick replies',
            'quick_reply_name' => 'quick reply label',
            'quick_reply_link_type' => 'quick reply link type',
            'link_mo' => 'mobile link',
            'link_pc' => 'PC link',
            'link_and' => 'Android scheme',
            'link_ios' => 'iOS scheme',
            'tel_number' => 'phone number',
            'plugin_id' => 'plugin ID',
            'image' => 'image file',
            'sms_body' => 'SMS body',
            'alimtalk_enabled' => 'AlimTalk enabled',
            'fallback_sms_enabled' => 'fallback SMS enabled',
            'sms_only' => 'SMS only',
            'is_active' => 'active',
            'status' => 'status',
            'search' => 'search keyword',
            'page' => 'page',
            'per_page' => 'items per page',
        ],
    ],

    // Connection check (TokenCheckController response)
    'token_check' => [
        'success' => 'Authentication verified successfully. The ID and password are correct.',
        'failed' => 'Authentication check failed. See the detailed reason.',
    ],
];
