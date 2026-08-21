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
            'name' => '비즈뿌리오 문자',
            'description' => '비즈뿌리오를 통해 문자(SMS/LMS)로 알림을 발송합니다.',
        ],
        'alimtalk' => [
            'name' => '비즈뿌리오 알림톡',
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
        'image_unreadable' => '업로드한 이미지 파일을 읽을 수 없습니다.',
    ],

    // 발송 건너뜀 (채널 드라이버 send() 사전 조건 미충족 — 코어 발송 이력에 "실패"로 기록됨)
    'send_skipped' => [
        'alimtalk_template_not_approved' => '승인된 알림톡 템플릿이 없거나 알림톡 발송이 꺼져 있어 발송을 건너뛰었습니다. (알림 유형: :type)',
        'sms_template_missing' => 'SMS 본문이 설정되지 않아 발송을 건너뛰었습니다. (알림 유형: :type)',
        'recipient_phone_missing' => '수신자 전화번호가 없어 발송을 건너뛰었습니다. (알림 유형: :type)',
        'message_body_empty' => '발송 본문이 비어 있어 발송을 건너뛰었습니다. (알림 유형: :type)',
    ],

    // 알림 템플릿 라이프사이클 (#597 — BizppurioTemplateController 응답·상태 가드)
    'template' => [
        'created' => '알림 템플릿을 저장했습니다.',
        'updated' => '알림 템플릿을 수정했습니다.',
        'requested' => '검수를 신청했습니다. 승인 결과는 자동 동기화되며 [새로고침]으로 즉시 확인할 수 있습니다.',
        'request_cancelled' => '검수 신청을 취소했습니다.',
        'approval_cancelled' => '승인을 취소했습니다. 이 알림의 알림톡 발송이 중단되었습니다.',
        'released' => '휴면 상태를 해제했습니다.',
        'synced' => '카카오 검수 상태를 동기화했습니다.',
        'deleted' => '알림 템플릿을 삭제했습니다.',
        'image_uploaded' => '이미지를 업로드했습니다.',
        'content_locked' => '현재 상태(:status)에서는 알림톡 내용을 수정할 수 없습니다. 검수중이면 신청 취소를, 승인됨이면 승인 취소를 먼저 하세요.',
        'content_missing' => '알림톡 템플릿 내용을 먼저 작성하세요.',
        'request_not_allowed' => '현재 상태(:status)에서는 검수를 신청할 수 없습니다.',
        'cancel_request_not_allowed' => '검수중 상태가 아니어서 신청을 취소할 수 없습니다. (현재: :status)',
        'cancel_approval_not_allowed' => '승인 상태가 아니어서 승인을 취소할 수 없습니다. (현재: :status)',
        'release_not_allowed' => '휴면 상태가 아니어서 해제할 수 없습니다. (현재: :status)',
        'code_generation_failed' => '템플릿 코드를 채번하지 못했습니다. 잠시 후 다시 시도하세요.',
    ],

    // 알림 템플릿 검증 (FormRequest — 문서 수치 명시 제약만, 그 외는 kapi 최종 게이트)
    'validation' => [
        'link_mo_required' => '웹링크(WL) 버튼은 모바일 링크가 필요합니다.',
        'link_and_required' => '앱링크(AL) 버튼은 Android 스킴이 필요합니다.',
        'link_ios_required' => '앱링크(AL) 버튼은 iOS 스킴이 필요합니다.',
        'tel_number_required' => '전화(TN) 버튼은 전화번호가 필요합니다.',
        'plugin_id_required' => '플러그인(P1~P3) 버튼은 플러그인 ID 가 필요합니다.',
        'highlight_title_too_long' => '아이템 하이라이트 타이틀은 :max자 이하여야 합니다.',
        'highlight_description_too_long' => '아이템 하이라이트 설명은 :max자 이하여야 합니다.',
        'image_ratio_invalid' => '이미지는 가로:세로 2:1 비율이어야 합니다. (예: 1000×500px)',

        // 검증 오류 문구에 쓰이는 필드 라벨 (FormRequest::attributes)
        'attributes' => [
            'notification_type' => '알림 유형',
            'content' => '알림톡 내용',
            'template_name' => '템플릿명',
            'message_type' => '메시지 유형',
            'emphasize_type' => '강조 유형',
            'template_content' => '본문',
            'preview_message' => '미리보기 문구',
            'category_code' => '카테고리',
            'security_flag' => '보안 템플릿 여부',
            'extra' => '부가정보',
            'title' => '강조 타이틀',
            'subtitle' => '강조 서브타이틀',
            'header' => '헤더',
            'image_name' => '이미지 파일명',
            'image_url' => '이미지 URL',
            'item' => '아이템 리스트',
            'item_list' => '아이템 목록',
            'item_title' => '아이템 제목',
            'item_description' => '아이템 설명',
            'summary' => '요약',
            'summary_title' => '요약 제목',
            'summary_description' => '요약 설명',
            'highlight' => '하이라이트',
            'highlight_title' => '하이라이트 타이틀',
            'highlight_description' => '하이라이트 설명',
            'highlight_image_url' => '하이라이트 이미지 URL',
            'represent_link' => '대표 링크',
            'buttons' => '버튼',
            'button_name' => '버튼명',
            'button_link_type' => '버튼 링크 유형',
            'quick_replies' => '바로연결',
            'quick_reply_name' => '바로연결명',
            'quick_reply_link_type' => '바로연결 링크 유형',
            'link_mo' => '모바일 링크',
            'link_pc' => 'PC 링크',
            'link_and' => 'Android 스킴',
            'link_ios' => 'iOS 스킴',
            'tel_number' => '전화번호',
            'plugin_id' => '플러그인 ID',
            'image' => '이미지 파일',
            'sms_body' => 'SMS 본문',
            'alimtalk_enabled' => '알림톡 사용',
            'fallback_sms_enabled' => '대체 SMS 사용',
            'sms_only' => 'SMS 단독 발송',
            'is_active' => '활성 여부',
            'status' => '상태',
            'search' => '검색어',
            'page' => '페이지',
            'per_page' => '페이지당 건수',
        ],
    ],

    // 연결 확인 (TokenCheckController 응답)
    'token_check' => [
        'success' => '인증이 정상적으로 확인되었습니다. 아이디와 비밀번호가 올바릅니다.',
        'failed' => '인증 확인에 실패했습니다. 상세 사유를 확인해 주세요.',
    ],
];
