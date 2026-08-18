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
            'name' => 'SMS/LMS Text',
            'description' => 'Send notifications as SMS/LMS text messages via Bizppurio.',
        ],
        'alimtalk' => [
            'name' => 'Kakao Alimtalk',
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
        'template_not_sendable' => 'This template is not in a sendable (approved) state. (code: :code)',
    ],

    // Send skipped (channel driver send() precondition not met — recorded as "Failed" in core notification log)
    'send_skipped' => [
        'alimtalk_binding_missing' => 'Skipped sending: no alimtalk template is bound. (notification type: :type)',
        'alimtalk_kakao_content_unavailable' => 'Skipped sending: failed to fetch the approved Kakao template content. (notification type: :type)',
        'sms_template_missing' => 'Skipped sending: no SMS template found. (notification type: :type)',
        'recipient_phone_missing' => 'Skipped sending: recipient phone number is missing. (notification type: :type)',
        'message_body_empty' => 'Skipped sending: message body is empty. (notification type: :type)',
    ],

    // Notification-to-alimtalk template binding (NotificationBindingController responses)
    'binding' => [
        'saved' => 'Alimtalk binding saved.',
        'removed' => 'Alimtalk binding removed.',
    ],

    // Dispatch template content cache (AlimtalkTemplateController::clearCache response)
    'cache' => [
        'cleared' => 'Alimtalk template content cache cleared. The latest content will apply from the next dispatch.',
    ],

    // Connection check (TokenCheckController response)
    'token_check' => [
        'success' => 'Authentication verified successfully. The ID and password are correct.',
        'failed' => 'Authentication check failed. See the detailed reason.',
    ],
];
