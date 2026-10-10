<?php

return [
    // 알림 정의
    'definition_list_success' => '알림 정의 목록을 조회했습니다.',
    'definition_list_failed' => '알림 정의 목록 조회에 실패했습니다.',
    'definition_show_success' => '알림 정의를 조회했습니다.',
    'definition_show_failed' => '알림 정의 조회에 실패했습니다.',
    'definition_updated' => '알림 정의가 수정되었습니다.',
    'definition_update_failed' => '알림 정의 수정에 실패했습니다.',
    'definition_toggled' => '알림 정의 활성 상태가 변경되었습니다.',
    'definition_toggle_failed' => '알림 정의 활성 상태 변경에 실패했습니다.',
    'definition_not_found' => '알림 정의를 찾을 수 없습니다.',
    'definition_reset' => '알림 정의의 모든 템플릿이 기본값으로 복원되었습니다.',
    'definition_reset_failed' => '알림 정의 초기화에 실패했습니다.',
    'definition_created' => '본인인증 정책별 문구가 추가되었습니다.',
    'definition_create_failed' => '본인인증 정책별 문구 추가에 실패했습니다.',
    'definition_deleted' => '본인인증 정책별 문구가 삭제되었습니다.',
    'definition_delete_failed' => '본인인증 정책별 문구 삭제에 실패했습니다.',
    'definition_delete_forbidden' => '운영자가 추가한 본인인증 정책별 문구만 삭제할 수 있습니다.',

    // 채널 발송 건너뛰기
    'channel_skipped_no_template' => ':channel 채널에 :type 타입의 활성 템플릿이 없어 발송을 건너뛰었습니다.',
    'channel_disabled_by_extension' => '확장 환경설정에서 해당 채널이 비활성화되어 있어 발송을 건너뛰었습니다.',
    'channel_guest_not_allowed' => '해당 채널은 비회원 발송을 허용하지 않아 발송을 건너뛰었습니다.',
    'channel_unavailable' => '해당 채널을 제공하는 확장이 비활성 또는 삭제되어 발송을 건너뛰었습니다.',

    // 알림 템플릿
    'template_updated' => '알림 템플릿이 수정되었습니다.',
    'template_update_failed' => '알림 템플릿 수정에 실패했습니다.',
    'template_toggled' => '알림 템플릿 활성 상태가 변경되었습니다.',
    'template_toggle_failed' => '알림 템플릿 활성 상태 변경에 실패했습니다.',
    'template_reset' => '알림 템플릿이 기본값으로 복원되었습니다.',
    'template_reset_failed' => '알림 템플릿 기본값 복원에 실패했습니다.',
    'template_inactive' => '해당 채널의 알림 템플릿이 비활성 상태입니다.',
    'template_not_found' => '해당 채널의 알림 템플릿을 찾을 수 없습니다.',
    'default_data_not_found' => '기본 템플릿 데이터를 찾을 수 없습니다.',

    // 미리보기
    'preview_success' => '미리보기를 생성했습니다.',
    'preview_failed' => '미리보기 생성에 실패했습니다.',

    // 채널
    'channels_success' => '알림 채널 목록을 조회했습니다.',
    'channels_failed' => '알림 채널 목록 조회에 실패했습니다.',

    // 필드명
    'definition' => '알림 정의',
    'subject' => '제목',
    'body' => '본문',

    // 채널 Readiness
    'readiness' => [
        'mail_from_address_not_configured' => '발신자 이메일이 설정되지 않았습니다.',
        'mail_smtp_host_empty' => 'SMTP 호스트가 설정되지 않았습니다.',
        'mail_smtp_port_empty' => 'SMTP 포트가 설정되지 않았습니다.',
        'mail_mailgun_domain_empty' => 'Mailgun 도메인이 설정되지 않았습니다.',
        'mail_mailgun_secret_empty' => 'Mailgun 시크릿이 설정되지 않았습니다.',
        'mail_ses_key_empty' => 'SES 키가 설정되지 않았습니다.',
        'mail_ses_secret_empty' => 'SES 시크릿이 설정되지 않았습니다.',
        'database_table_missing' => '알림 테이블이 존재하지 않습니다.',
        'unknown' => '채널 설정이 완료되지 않았습니다.',
    ],

    // 발송 건너뜀 사유에 적는 수신자 규칙 이름
    'recipient_rules' => [
        'trigger_user' => '이벤트 유발자',
        'related_user' => '관련 사용자(:relation)',
        'role' => '역할(:roles)',
        'specific_users' => '특정 사용자 :count명',
        'exclude_trigger_user' => ':rule — 이벤트 유발자 제외',
        'legacy' => '정의의 기본 수신자',
    ],

    // 발송 건너뜀 사유
    'skipped' => [
        'no_recipient_resolved' => '수신자 규칙으로 받을 사람을 찾지 못해 발송하지 않았습니다. 적용 규칙: :rule_names (규칙 코드: :rules)',
        'guest_email_empty' => '비회원 수신자의 이메일이 없어 발송하지 않았습니다. (수신자 규칙: :rules)',
        'withdrawn_recipient' => '탈퇴한 회원이라 바깥 채널(메일·SMS·알림톡)로는 발송하지 않았습니다. 탈퇴 시 연락처가 익명화되어 받을 수 있는 주소가 없습니다.',
        'member_account_missing' => '알림 대상 회원의 계정이 삭제되어 발송하지 않았습니다. 다른 기록에 남은 연락처로 대신 보내지 않습니다. (수신자 규칙: :rules)',
    ],

    // 사용자 알림
    'user' => [
        'list_success' => '알림 목록을 조회했습니다.',
        'list_failed' => '알림 목록 조회에 실패했습니다.',
        'unread_count_success' => '미읽음 알림 수를 조회했습니다.',
        'unread_count_failed' => '미읽음 알림 수 조회에 실패했습니다.',
        'read_success' => '알림을 읽음 처리했습니다.',
        'read_failed' => '알림 읽음 처리에 실패했습니다.',
        'read_all_success' => '모든 알림을 읽음 처리했습니다.',
        'read_all_failed' => '전체 읽음 처리에 실패했습니다.',
        'delete_success' => '알림이 삭제되었습니다.',
        'delete_failed' => '알림 삭제에 실패했습니다.',
        'delete_all_success' => '모든 알림이 삭제되었습니다.',
        'delete_all_failed' => '전체 알림 삭제에 실패했습니다.',
        'not_found' => '알림을 찾을 수 없습니다.',
    ],

    // 알림 채널 메타 (config/notification.php default_channels 참조)
    'validation' => [
        // 역할 수신자는 다중 선택이 되면서 값이 배열일 수 있다. 객체·숫자가 섞이면
        // 저장은 되고 발송 시점에 수신자 0명으로만 드러나므로 여기서 세운다.
        'role_value_shape' => '역할 수신자 값은 식별자 문자열 또는 문자열 배열이어야 합니다.',
        'attributes' => [
            'recipients' => '수신자 규칙',
            'recipient_type' => '수신자 종류',
            'recipient_value' => '수신자 값',
            'recipient_relation' => '관련 사용자 관계',
            'recipient_exclude_trigger_user' => '이벤트 유발자 제외',
            // 다국어 칸 표시 이름 — 템플릿 저장 때 다국어 필수 문구가 칸 이름으로 쓴다
            'subject' => '알림 제목',
            'body' => '알림 본문',
        ],
    ],

    'channels' => [
        'core_default' => '코어 기본 채널',
        'mail' => [
            'name' => '메일',
            'description' => '이메일로 알림 발송',
        ],
        'database' => [
            'name' => '사이트내 알림',
            'description' => '사이트내 알림 센터에 표시',
        ],
    ],
    // 알림 템플릿 편집 창 「사용 가능한 변수」 설명 — common 은 변수 키별 기본, by_type 은 그 알림에서만 뜻이 다른 변수, identity 는 본인인증 알림 공통 변수. 없는 키는 선언 문자열을 그대로 쓴다.
    'variables' => [
        'common' => [
            'name' => '수신자 이름',
            'app_name' => '사이트명',
            'action_url' => '로그인 페이지 URL',
            'site_url' => '사이트 URL',
            'expire_minutes' => '링크 만료 시간(분)',
            'url_count' => '생성된 총 URL 수',
            'child_count' => '생성된 사이트맵 파일 수',
            'error' => '실패 사유 메시지',
            'dormant_days' => '휴면 전환 기준 미접속 일수',
            'scheduled_date' => '휴면 전환 예정일',
            'run_type' => '집계 종류 이름(예: 일 집계)',
            'period' => '대상 기간',
            'diagnostics_url' => '수집 진단 화면 URL',
            'visits' => '방문 수',
            'actions' => '현재 액션 행수',
            'threshold_visits' => '방문 경고 임계치',
            'threshold_actions' => '액션 경고 임계치',
            'retention_days' => '현재 원장 보존일',
            'settings_url' => '보존 설정 화면 URL',
            'visitor_key' => '방문자 키(앞 8자)',
            'limit' => '방문당 액션 상한',
            'ip_display' => '표시용 IP(설정에 따라 마스킹)',
            'period_label' => '기간 표기(예: 2026-09-08 ~ 2026-09-14)',
            'visitors' => '순방문자 수',
            'pageviews' => '페이지뷰 수',
            'change_pct' => '직전 기간 대비 증감률(예: +12.5%, 직전 기간이 0이면 -)',
            'top_pages' => '인기 페이지 표(HTML)',
            'top_referrers' => '유입 경로 표(HTML)',
            'report_url' => '통계 개요 화면 URL',
            'expires_at' => '비밀번호 만료일',
            'days_left' => '만료까지 남은 일수',
            'expired_at' => '비밀번호 만료일',
        ],
        'by_type' => [
            'reset_password' => [
                'action_url' => '비밀번호 재설정 URL',
            ],
            'sitemap_regenerated' => [
                'action_url' => 'SEO 설정 페이지 URL',
            ],
            'sitemap_regenerate_failed' => [
                'action_url' => 'SEO 설정 페이지 URL',
            ],
            'dormant_withdraw_notice' => [
                'scheduled_date' => '탈퇴 처리 예정일',
            ],
            'analytics_ledger_limit_exceeded' => [
                'visits' => '현재 방문 행수',
            ],
            'analytics_visit_limit_exceeded' => [
                'actions' => '기록된 액션 수',
            ],
            'analytics_monthly_report' => [
                'period_label' => '기간 표기(예: 2026-08-01 ~ 2026-08-31)',
            ],
            'password_expiry_notice' => [
                'action_url' => '비밀번호 변경 화면 URL',
            ],
        ],
        'identity' => [
            'code' => '인증 코드 (text_code 흐름)',
            'action_url' => '검증 링크 URL (link 흐름)',
            'expire_minutes' => '만료까지 남은 분',
            'purpose_label' => '인증 목적 라벨 (다국어)',
            'app_name' => '사이트명',
            'site_url' => '사이트 URL',
            'recipient_email' => '수신자 이메일',
        ],
    ],
];
