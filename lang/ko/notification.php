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

    // 채널 발송 건너뛰기
    'channel_skipped_no_template' => ':channel 채널에 :type 타입의 활성 템플릿이 없어 발송을 건너뛰었습니다.',
    'channel_disabled_by_extension' => '확장 환경설정에서 해당 채널이 비활성화되어 있어 발송을 건너뛰었습니다.',

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
];
