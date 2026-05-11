<?php

return [
    // 활동 로그 관련 메시지
    'fetch_success' => '활동 로그 정보를 성공적으로 가져왔습니다.',
    'fetch_failed' => '활동 로그 정보를 가져오는데 실패했습니다.',
    'delete_success' => '활동 로그가 삭제되었습니다.',
    'bulk_delete_success' => '선택한 활동 로그가 삭제되었습니다.',
    'delete_failed' => '활동 로그 삭제에 실패했습니다.',

    // 유효성 검증 메시지
    'validation' => [
        'ids_required' => '삭제할 활동 로그를 선택해주세요.',
        'ids_min' => '삭제할 활동 로그를 하나 이상 선택해주세요.',
        'id_not_found' => '선택한 활동 로그를 찾을 수 없습니다.',
    ],

    // 로그 유형 라벨
    'type' => [
        'admin' => '관리자',
        'user' => '사용자',
        'system' => '시스템',
    ],

    // 액션 라벨 (마지막 세그먼트 기준)
    'action' => [
        'created' => '생성',
        'create' => '생성',
        'updated' => '수정',
        'update' => '수정',
        'deleted' => '삭제',
        'delete' => '삭제',
        'login' => '로그인',
        'logout' => '로그아웃',
        'login_failed' => '로그인 실패',
        'account_locked' => '계정 잠금',
        'request' => '요청',
        'expired' => '만료',
        'export' => '내보내기',
        'import' => '가져오기',
        'index' => '목록 조회',
        'show' => '상세 조회',
        'search' => '검색',
        'install' => '설치',
        'activate' => '활성화',
        'deactivate' => '비활성화',
        'uninstall' => '제거',
        'save' => '저장',
        'run' => '실행',
        'duplicate' => '복제',
        'upload' => '업로드',
        'toggle_status' => '상태 전환',
        'bulk_update' => '일괄 수정',
        'bulk_delete' => '일괄 삭제',
        'bulk_update_status' => '일괄 상태 변경',
        'refund' => '환불',
        'publish' => '발행',
        'register' => '회원가입',
        'reset_password' => '비밀번호 재설정',
        'forgot_password' => '비밀번호 찾기',
        'record_consents' => '동의 기록',
        'check' => '확인',
        'sync_permissions' => '권한 동기화',
        'sync_roles' => '역할 동기화',
        'reply' => '답변',
        'toggle' => '토글',
        // 추가 액션
        'withdraw' => '탈퇴',
        'update_order' => '순서 변경',
        'refresh_layouts' => '레이아웃 갱신',
        'version_update' => '버전 업데이트',
        'version_restore' => '버전 복원',
        'reset' => '초기화',

        // 이커머스/게시판 공용
    ],

    // 설명 템플릿 (description_key로 사용)
    'description' => [
        // 사용자 관리
        'user_index' => '사용자 목록 조회',
        'user_create' => '사용자 생성 (ID: :user_id)',
        'user_show' => '사용자 상세 조회 (ID: :user_id)',
        'user_update' => '사용자 수정 (ID: :user_id)',
        'user_delete' => '사용자 삭제 (ID: :user_id)',
        'user_withdraw' => '사용자 탈퇴 (ID: :user_id)',
        'user_statistics' => '사용자 통계 조회',
        'user_recent' => '최근 사용자 조회',
        'user_search' => '사용자 검색',
        'user_check_email' => '이메일 중복 확인',
        'user_update_language' => '사용자 언어 설정 변경',
        'user_bulk_update_status' => '사용자 일괄 상태 변경 (:count건)',

        // 역할 관리
        'role_index' => '역할 목록 조회',
        'role_active' => '활성 역할 목록 조회',
        'role_show' => '역할 상세 조회 (ID: :role_id)',
        'role_create' => '역할 생성 (ID: :role_id)',
        'role_update' => '역할 수정 (ID: :role_id)',
        'role_delete' => '역할 삭제 (ID: :role_id)',
        'role_toggle_status' => '역할 상태 전환 (ID: :role_id)',
        'role_sync_permissions' => '역할 권한 동기화 (ID: :role_id)',

        // 권한 관리
        'permission_index' => '권한 목록 조회',

        // 메뉴 관리
        'menu_index' => '메뉴 목록 조회',
        'menu_hierarchy' => '메뉴 계층 조회',
        'menu_active' => '활성 메뉴 목록 조회',
        'menu_show' => '메뉴 상세 조회 (ID: :menu_id)',
        'menu_create' => '메뉴 생성 (ID: :menu_id)',
        'menu_update' => '메뉴 수정 (ID: :menu_id)',
        'menu_delete' => '메뉴 삭제 (ID: :menu_id)',
        'menu_update_order' => '메뉴 순서 변경',
        'menu_toggle_status' => '메뉴 상태 전환 (ID: :menu_id)',
        'menu_sync_roles' => '메뉴 역할 동기화 (ID: :menu_id)',
        'menu_get_by_extension' => '확장별 메뉴 조회 (:extension_type: :extension_identifier)',

        // 설정 관리
        'settings_index' => '설정 목록 조회',
        'settings_save' => '설정 저장',
        'settings_show' => '설정 조회',
        'settings_update' => '설정 수정',
        'settings_system_info' => '시스템 정보 조회',
        'settings_clear_cache' => '캐시 초기화',
        'settings_optimize_system' => '시스템 최적화',
        'settings_backup_database' => '데이터베이스 백업',
        'settings_get_app_key' => '앱 키 조회',
        'settings_app_key_regenerated' => '앱 키 재생성',
        'settings_backup' => '백업 생성',
        'settings_restore' => '백업 복원',
        'settings_test_mail' => '메일 전송 테스트',
        'settings_test_driver_connection' => '드라이버 연결 테스트',

        // 인증
        'auth_login' => '관리자 로그인',
        'auth_logout' => '관리자 로그아웃',
        'auth_register' => '회원가입',
        'auth_forgot_password' => '비밀번호 찾기 요청',
        'auth_reset_password' => '비밀번호 재설정',
        'auth_record_consents' => '이용약관 동의 기록',
        'auth_login_failed' => '로그인 실패 (:email)',
        'auth_account_locked' => '계정 잠금 (:attempts회 실패, :minutes분 차단)',

        // 스케줄 관리
        'schedule_index' => '스케줄 목록 조회',
        'schedule_show' => '스케줄 상세 조회 (ID: :schedule_id)',
        'schedule_create' => '스케줄 생성 (ID: :schedule_id)',
        'schedule_update' => '스케줄 수정 (ID: :schedule_id)',
        'schedule_delete' => '스케줄 삭제 (ID: :schedule_id)',
        'schedule_run' => '스케줄 수동 실행 (ID: :schedule_id)',
        'schedule_duplicate' => '스케줄 복제',
        'schedule_bulk_update_status' => '스케줄 일괄 상태 변경 (:count건)',
        'schedule_bulk_delete' => '스케줄 일괄 삭제 (:count건)',
        'schedule_statistics' => '스케줄 통계 조회',
        'schedule_history' => '스케줄 실행 이력 조회 (ID: :schedule_id)',
        'schedule_delete_history' => '스케줄 이력 삭제',

        // 대시보드
        'dashboard_stats' => '대시보드 통계 조회',
        'dashboard_resources' => '대시보드 리소스 조회',
        'dashboard_activities' => '대시보드 활동 조회',
        'dashboard_alerts' => '대시보드 알림 조회',

        // 첨부파일 관리
        'attachment_upload' => '파일 업로드',
        'attachment_upload_batch' => '파일 일괄 업로드',
        'attachment_delete' => '파일 삭제',
        'attachment_bulk_delete' => '파일 일괄 삭제',
        'attachment_reorder' => '파일 순서 변경',

        // 레이아웃 관리
        'layout_index' => '레이아웃 목록 조회',
        'layout_show' => '레이아웃 상세 조회 (:layout_path)',
        'layout_update' => '레이아웃 수정 (:layout_path)',
        'layout_versions_index' => '레이아웃 버전 목록 조회 (:layout_path)',
        'layout_version_show' => '레이아웃 버전 상세 조회 (:layout_path)',
        'layout_version_restore' => '레이아웃 버전 복원 (:layout_path)',

        // 메일 템플릿 관리
        'mail_template_update' => '메일 템플릿 수정 (:template_name)',
        'mail_template_toggle_active' => '메일 템플릿 활성 상태 변경 (:template_name)',

        // 모듈 관리
        'module_index' => '모듈 목록 조회',
        'module_installed' => '설치된 모듈 목록 조회',
        'module_uninstalled' => '미설치 모듈 목록 조회',
        'module_show' => '모듈 상세 조회 (:module_name)',
        'module_install' => '모듈 설치 (:module_name)',
        'module_activate' => '모듈 활성화 (:module_name)',
        'module_deactivate' => '모듈 비활성화 (:module_name)',
        'module_uninstall' => '모듈 제거 (:module_name)',
        'module_uninstall_info' => '모듈 삭제 정보 조회 (모듈: :module_name)',
        'module_check_updates' => '모듈 업데이트 확인',
        'module_update' => '모듈 업데이트 (:module_name)',
        'module_refresh_layouts' => '모듈 레이아웃 갱신 (:module_name)',
        'module_dependent_templates' => '모듈 종속 템플릿 조회',
        'module_install_from_file' => '파일에서 모듈 설치',
        'module_install_from_github' => 'GitHub에서 모듈 설치',

        // 모듈 설정
        'module_settings_save' => '모듈 설정 저장 (:module_name)',
        'module_settings_reset' => '모듈 설정 초기화 (:module_name)',

        // 플러그인 관리
        'plugin_index' => '플러그인 목록 조회',
        'plugin_installed' => '설치된 플러그인 목록 조회',
        'plugin_show' => '플러그인 상세 조회 (:plugin_name)',
        'plugin_install' => '플러그인 설치 (:plugin_name)',
        'plugin_activate' => '플러그인 활성화 (:plugin_name)',
        'plugin_deactivate' => '플러그인 비활성화 (:plugin_name)',
        'plugin_uninstall' => '플러그인 제거 (:plugin_name)',
        'plugin_uninstall_info' => '플러그인 삭제 정보 조회 (플러그인: :plugin_name)',
        'plugin_check_updates' => '플러그인 업데이트 확인',
        'plugin_update' => '플러그인 업데이트 (:plugin_name)',
        'plugin_refresh_layouts' => '플러그인 레이아웃 갱신 (:plugin_name)',
        'plugin_dependent_templates' => '플러그인 종속 템플릿 조회',

        // 플러그인 설정
        'plugin_settings_save' => '플러그인 설정 저장 (:plugin_name)',
        'plugin_settings_reset' => '플러그인 설정 초기화 (:plugin_name)',

        // 템플릿 관리
        'template_index' => '템플릿 목록 조회',
        'template_show' => '템플릿 상세 조회 (:template_name)',
        'template_install' => '템플릿 설치 (:template_name)',
        'template_activate' => '템플릿 활성화 (:template_name)',
        'template_deactivate' => '템플릿 비활성화 (:template_name)',
        'template_uninstall' => '템플릿 제거 (:template_name)',
        'template_check_updates' => '템플릿 업데이트 확인',
        'template_check_modified_layouts' => '템플릿 수정된 레이아웃 확인 (:template_name)',
        'template_update' => '템플릿 업데이트 (:template_name)',
        'template_version_update' => '템플릿 버전 업데이트 (:template_name)',
        'template_install_from_file' => '파일에서 템플릿 설치',
        'template_install_from_github' => 'GitHub에서 템플릿 설치',
        'template_refresh_layouts' => '템플릿 레이아웃 갱신 (:template_name)',

        // 코어 업데이트
        'core_update_check' => '코어 업데이트 확인',
        'core_update_update' => '코어 업데이트 실행',

        // 활동 로그
        'activity_log_index' => '활동 로그 목록 조회',
        'activity_log_delete' => '활동 로그 삭제 (ID: :log_id)',
        'activity_log_bulk_delete' => '활동 로그 일괄 삭제 (:count건)',
    ],

    // ChangeDetector 필드 라벨
    'fields' => [
        // User
        'name' => '이름',
        'nickname' => '닉네임',
        'email' => '이메일',
        'language' => '언어',
        'timezone' => '시간대',
        'country' => '국가',
        'status' => '상태',
        'is_super' => '최고관리자',
        'homepage' => '홈페이지',
        'mobile' => '휴대폰',
        'phone' => '전화번호',
        'zipcode' => '우편번호',
        'address' => '주소',
        'address_detail' => '상세주소',
        'bio' => '소개',
        'admin_memo' => '관리자 메모',

        // Role
        'identifier' => '식별자',
        'is_active' => '활성 여부',

        // Menu
        'url' => 'URL',
        'icon' => '아이콘',
        'parent_id' => '상위 항목',
        'order' => '순서',

        // Schedule
        'command' => '명령어',
        'expression' => '실행 주기',
        'without_overlapping' => '중복 실행 방지',
        'run_in_maintenance' => '점검 모드 실행',
        'timeout' => '타임아웃',

        // MailTemplate
        'subject' => '제목',
        'body' => '본문',
        'is_default' => '기본값',
    ],
];
