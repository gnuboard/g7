<?php

return [
    // 발송 채널 (DispatchChannel enum)
    'channel' => [
        'sms' => 'SMS',
        'lms' => 'LMS',
        'alimtalk' => '알림톡',
    ],

    // 채널 그룹 라벨 (SMS/LMS 를 "문자" 로 묶음 — 잔액부족 알림 등 채널군 표기용)
    'channel_group' => [
        'text' => '문자',
        'alimtalk' => '알림톡',
    ],

    // 발송 상태 (DispatchStatus enum)
    'status' => [
        'pending' => '대기',
        'sent' => '발송중',
        'success' => '성공',
        'failed' => '실패',
    ],

    // 발송 출처 (DispatchSource enum)
    'source' => [
        'auto' => '자동',
        'manual' => '수동',
        'bulk' => '대량',
    ],

    // 결과코드 분류 (ResultCategory enum)
    'result_category' => [
        'success' => '성공',
        'retry' => '재시도',
        'permanent_failure' => '영구 실패',
        'balance_low' => '잔액 부족',
    ],

    // 알림 채널 메타 (core.notification.filter_available_channels)
    'channels' => [
        'source_label' => '비즈뿌리오',
        'sms' => [
            'name' => 'SMS/LMS 문자',
            'description' => '비즈뿌리오를 통해 문자(SMS/LMS)로 알림을 발송합니다.',
        ],
        'alimtalk' => [
            'name' => '카카오 알림톡',
            'description' => '비즈뿌리오를 통해 카카오 알림톡으로 알림을 발송합니다.',
        ],
    ],

    // 채널 준비 상태 사유 (core.notification.channel_readiness)
    'readiness' => [
        'sms_credentials_missing' => '비즈뿌리오 아이디와 비밀번호를 설정하세요.',
        'sms_sender_number_missing' => '발신번호를 설정하세요.',
        'alimtalk_api_key_missing' => '카카오 관리 API 키를 설정하세요.',
        'alimtalk_sender_key_missing' => '알림톡 발신프로필 키를 설정하세요.',
    ],

    // 설정 검증 — 운영(live) 환경 필수 자격증명 항목 라벨 (validation.attributes 병합용)
    'settings' => [
        'bizppurio_id_attribute' => '비즈뿌리오 아이디',
        'password_attribute' => '비밀번호',
        'sender_number_attribute' => '발신번호',
    ],

    // webhook(URL PUSH) 리포트 수신
    'webhook' => [
        'received' => '리포트를 수신했습니다.',
    ],

    // 발송 엔진 오류 (API 클라이언트·토큰·발송 Job)
    'error' => [
        'credentials_missing' => '비즈뿌리오 아이디와 비밀번호를 먼저 설정하세요.',
        'token_issue_failed' => '비즈뿌리오 인증 토큰 발급에 실패했습니다.',
        'token_issue_failed_with_reason' => '비즈뿌리오 인증 토큰 발급에 실패했습니다. (:reason)',
        'send_failed' => '메시지 발송 요청에 실패했습니다.',
        'send_retryable' => '메시지 발송이 일시적으로 실패했습니다. (코드: :code)',
        'invalid_response' => '비즈뿌리오 응답을 해석할 수 없습니다.',
        'connection_failed' => '비즈뿌리오 서버에 연결할 수 없습니다. 잠시 후 다시 시도해주세요.',
        'kakao_credentials_missing' => '카카오 관리 API 사용을 위해 아이디와 API 키를 먼저 설정하세요.',
        'kakao_request_failed' => '카카오 관리 API 요청에 실패했습니다.',
        'sender_key_missing' => '알림톡 발신프로필 키를 먼저 설정하세요.',
        'template_not_sendable' => '발송 가능(승인) 상태가 아닌 템플릿입니다. (코드: :code)',
    ],

    // 발송 건너뜀 (채널 드라이버 send() 사전 조건 미충족 — 코어 발송 이력에 "실패"로 기록됨)
    'send_skipped' => [
        'alimtalk_binding_missing' => '알림톡 템플릿이 연결되지 않아 발송을 건너뛰었습니다. (알림 유형: :type)',
        'alimtalk_kakao_content_unavailable' => '카카오 승인 템플릿 내용을 조회하지 못해 발송을 건너뛰었습니다. (알림 유형: :type)',
        'sms_template_missing' => 'SMS 템플릿이 없어 발송을 건너뛰었습니다. (알림 유형: :type)',
        'recipient_phone_missing' => '수신자 전화번호가 없어 발송을 건너뛰었습니다. (알림 유형: :type)',
        'message_body_empty' => '발송 본문이 비어 있어 발송을 건너뛰었습니다. (알림 유형: :type)',
    ],

    // 알림↔알림톡 템플릿 연동 (NotificationBindingController 응답)
    'binding' => [
        'saved' => '알림톡 연동을 저장했습니다.',
        'removed' => '알림톡 연동을 해제했습니다.',
    ],

    // 발송용 템플릿 내용 캐시 (AlimtalkTemplateController::clearCache 응답)
    'cache' => [
        'cleared' => '알림톡 템플릿 내용 캐시를 초기화했습니다. 다음 발송부터 최신 내용이 반영됩니다.',
    ],

    // 연결 확인 (TokenCheckController 응답)
    'token_check' => [
        'success' => '인증이 정상적으로 확인되었습니다. 아이디와 비밀번호가 올바릅니다.',
    ],
];
