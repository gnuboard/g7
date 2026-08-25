<?php

// /install/lang/ko.php

return [
    // 공통
    'next' => '다음',
    'previous' => '이전',
    'install' => '설치하기',
    'cancel' => '취소',
    'yes' => '예',
    'no' => '아니오',
    'loading' => '로딩 중...',
    'saving' => '저장 중...',
    'save_failed' => '저장 실패',
    'error' => '오류',

    // Step 0: 환영
    'welcome_title' => '그누보드7 시작하기',
    'welcome_desc' => '그누보드7에 오신 것을 환영합니다. 이곳은 설치 첫 단계이며,<br>순서대로 안내를 따라 하시면 필요한 환경 설정을 빠르고 안정적으로 완료하실 수 있습니다.',
    'select_language' => '언어를 선택하세요',
    'storage_permission_check' => '디렉토리 권한 체크',
    'storage_permission_required' => 'Storage 폴더 권한 설정 필요',
    'storage_permission_failed' => 'Storage 폴더 권한이 부족합니다',
    'storage_not_exists' => 'Storage 폴더가 없습니다',
    'storage_not_exists_message' => 'Storage 폴더가 존재하지 않습니다. 프로젝트 루트에 storage 폴더를 생성해주세요.',
    'storage_not_writable' => 'Storage 폴더 쓰기 권한 없음',
    'storage_not_writable_message' => 'Storage 폴더에 쓰기 권한이 없습니다. 폴더 소유자 또는 그룹 권한을 확인해주세요.',
    'storage_ownership_mismatch' => 'Storage 폴더 소유자 불일치',
    'storage_ownership_mismatch_message' => 'Storage 폴더의 소유자(:owner)가 웹서버 실행 사용자(:web_user)와 다릅니다. 현재 권한(:permissions)은 소유자만 쓰기 가능하므로 웹서버가 쓸 수 없습니다. 아래 방법 중 하나를 적용해주세요.',
    'storage_permission_guide' => 'Storage 폴더에 <strong>755</strong> 권한을 부여해주세요.',
    'storage_permission_guide_detail' => '아래 명령어를 실행하거나 FTP를 통해 권한을 변경하세요:',
    'storage_permission_command_linux' => 'chmod -R 755 :path',
    'current_owner' => '현재 소유자',
    'current_group' => '현재 소유그룹',
    'current_permissions' => '현재 권한',
    'required_permissions' => '필요 권한',
    'unknown' => '알 수 없음',
    'recheck_permission' => '다시 확인',
    'permission_checking' => '권한 확인 중...',

    // Step 1: 라이선스
    'license_title' => '라이선스 동의',
    'license_agreement' => '라이선스 약관',
    'i_agree' => '위 라이선스 약관에 동의합니다',
    'must_agree' => '계속하려면 라이선스에 동의해 주십시오.',
    'license_not_found' => '라이선스 정보를 찾을 수 없습니다.',

    // Step 2: 설치 환경 확인
    'checking_requirements' => '서버 요구사항을 확인하는 중...',
    'php_version' => 'PHP 버전',
    'php_modules' => 'PHP 모듈',
    'directory_permissions' => '디렉토리 권한',
    'disk_space' => '디스크 공간',
    'https' => 'HTTPS',
    'opcache' => 'OPcache',
    'required' => '필수',
    'enabled' => '활성화됨',
    'not_enabled' => '비활성화됨',
    'recommended' => '권장사항',
    'needs_fix' => '수정 필요',
    'requirements_check_failed' => '요구사항 검증 실패',
    'all_requirements_met' => '모든 요구사항이 충족되었습니다.',
    'some_requirements_failed' => '일부 요구사항이 충족되지 않았습니다. 서버 설정을 확인하세요.',
    'recheck' => '다시 검증',
    'requirements_not_met' => '필수 요구사항을 충족하지 못했습니다. 환경을 확인하고 다시 시도해주세요.',
    'badge_required' => '필수',
    'badge_optional' => '선택',
    'badge_info' => '정보',
    'or_above' => '이상',
    'fix_permission' => '쓰기 권한 부족',
    'including_subdirectories' => '하위 포함',
    'and_more' => '외 :count개',
    'error_php_version' => 'PHP 버전',
    'error_missing_extensions' => '필수 PHP 확장 미설치',
    'error_disk_space' => '디스크 공간',
    'error_directory_permissions' => '디렉토리 쓰기 권한 부족',
    'error_required_files' => '필수 파일 누락',
    'error_required_files_missing_label' => '필수 파일 누락',
    'error_required_files_not_writable_label' => '필수 파일 쓰기 권한 없음',
    'error_required_files_ownership_mismatch_label' => '필수 파일 소유자 불일치',
    'ownership_mismatch_option_666' => '[대안 2] 최후 수단 — 파일에 모든 사용자 쓰기 허용 (보안 약화, 비권장):',
    'required_files' => '필수 파일',
    'file_exists' => '존재',
    'file_missing' => '누락',
    'file_not_writable' => '쓰기 불가',
    'required_files_fix_guide' => '아래 명령어를 프로젝트 루트에서 실행하세요:',
    'minimum' => '최소',
    'required_text' => '필요',

    // Step 3: 설정
    'database_settings' => '데이터베이스 설정',
    'database_type' => '데이터베이스 타입',
    'db_host' => '데이터베이스 호스트',
    'db_port' => '포트',
    'db_name' => '데이터베이스 이름',
    'db_username' => '사용자명',
    'db_password' => '비밀번호',
    'db_prefix' => '테이블 접두사',
    'db_prefix_hint' => '모든 테이블명 앞에 붙는 접두사입니다. (예: g7_users, g7_posts) 최대 6자까지 입력할 수 있습니다.',
    'db_prefix_placeholder' => 'g7_',
    'test_write_db_connection' => 'Write DB 연결 테스트',
    'test_read_db_connection' => 'Read DB 연결 테스트',
    'use_read_db' => 'Read DB 설정 사용',
    'database_settings_read' => '데이터베이스 설정 (Read DB) - 선택사항',

    'site_settings' => '사이트 설정',
    'app_name' => '사이트 이름',
    'app_url' => '사이트 URL',

    'admin_account' => '관리자 계정',
    'admin_name' => '관리자 이름',
    'admin_language' => '관리자 언어',
    'admin_email' => '관리자 이메일',
    'admin_password' => '관리자 비밀번호',
    'admin_password_confirm' => '비밀번호 확인',

    'start_installation' => '설치 시작',

    // Step 5: 설치
    'installation_title' => '설치 진행 중',
    'installation_in_progress' => '그누보드7을 설치하고 있습니다. 잠시만 기다려 주세요...',
    'installation_in_progress_message' => '설치가 진행 중입니다. 설치 화면으로 이동합니다.',
    'overall_progress' => '전체 진행률',
    'preparing' => '준비 중...',
    'waiting_installation' => '설치 시작을 기다리는 중...',
    'processing' => '처리 중...',
    'installation_complete' => '설치 완료',
    'installation_failed' => '설치 실패',
    'retry_installation' => '재시도',
    'do_not_close_page' => '설치가 완료될 때까지 페이지를 닫지 마세요.',
    'installation_log' => '설치 로그',
    'hide_log' => '로그 숨기기',
    'show_log' => '로그 보기',
    'back_to_settings' => '설정으로 돌아가기',
    'progress_status' => '설치 목록',
    'hide_progress' => '설치 목록 숨기기',
    'show_progress' => '설치 목록 보기',
    'installation_completed' => '설치가 완료되었습니다!',
    'recommendations' => '설치 후 권장사항',
    'recommendation_env_permission' => '.env 파일 권한을 644로 설정하세요 (chmod 644 .env)',
    'recommendation_https' => '프로덕션 환경에서는 반드시 HTTPS를 사용하세요',

    // .env 권한 보안 강화 안내 (Step 5 완료 화면, 0600 미적용 시 조건부 노출)
    'env_hardening_hint_title' => '.env 권한 보안 강화 (선택)',
    'env_hardening_hint_current_status' => '현재 .env 권한은 :permission 입니다 (소유자: :owner). 가장 안전한 권한은 0600 (소유자만 읽기·쓰기) 이며, 다음 두 명령 중 환경에 맞는 쪽을 sudo 로 실행하여 추가 보안 강화를 적용할 수 있습니다.',
    'env_hardening_hint_option_strict' => '명령줄 사용자 = 웹 서버 사용자 (단일 사용자 환경)',
    'env_hardening_hint_option_group' => '명령줄 사용자 ≠ 웹 서버 사용자 (자체 구축 서버 등 — 그룹 읽기 허용 절충안)',
    'env_hardening_hint_note' => '명령 실행 전 환경을 확인하세요. 잘못된 권한 적용 시 웹 서버가 .env 를 읽지 못해 사이트가 응답하지 않을 수 있습니다.',

    // Step 5: 완료
    'installation_info' => '설치 정보',
    'installation_complete_message' => '그누보드7이 성공적으로 설치되었습니다!',
    'installed_version_label' => '설치된 버전:',
    'site_url' => '사이트 URL',
    'installed_at' => '설치 완료 일시',
    'go_to_admin_login' => '관리자 로그인',

    // 재진입
    'completed_tasks' => '완료된 작업',
    'interrupted_task' => '중단된 작업',
    'pending_tasks' => '미완료 작업',
    'interrupted_during_execution' => '진행 중 중단됨',
    'resume_continue' => '계속 진행',
    'installation_resuming' => '설치 재개',

    // 에러 메시지 - 공통
    'error_occurred' => '오류가 발생했습니다',
    'please_try_again' => '다시 시도해 주세요.',
    'invalid_input' => '입력값이 올바르지 않습니다.',

    // 에러 메시지 - 검증 (데이터베이스)
    'error_db_host_required' => '데이터베이스 호스트는 필수입니다.',
    'error_db_name_required' => '데이터베이스 이름은 필수입니다.',
    'error_db_username_required' => '데이터베이스 사용자명을 입력해주세요.',
    'error_db_credentials_required' => '데이터베이스명과 사용자명은 필수입니다.',
    'error_db_username_privileged' => '보안상 :username 계정으로는 설치할 수 없습니다. 최고 권한 계정이 유출되면 데이터베이스 전체가 위험해집니다. G7 전용 데이터베이스 사용자를 새로 만들어 필요한 권한만 부여한 뒤 입력해주세요.',
    'error_db_prefix_too_long' => '테이블 접두사는 최대 :max자까지 가능합니다. (입력: :current자) 접두사가 길면 일부 테이블의 인덱스명이 데이터베이스 한도를 초과합니다.',
    'error_db_privileges_insufficient' => '데이터베이스에 필요한 권한이 부족합니다.',
    'error_db_not_tested' => '데이터베이스 연결 테스트를 먼저 수행해주세요.',
    'error_write_db_not_tested' => 'Write DB 연결 테스트를 먼저 수행해주세요.',
    'error_read_db_not_tested' => 'Read DB 연결 테스트를 먼저 수행해주세요.',

    // 에러 메시지 - 검증 (데이터베이스) (상세)
    'error_db_privileges_insufficient_detail' => ':type 데이터베이스에 필요한 권한이 부족합니다: :missing',
    'warning_db_privileges_indeterminate' => ':type 데이터베이스의 권한을 확인하지 못했습니다. 설치는 진행할 수 있으며, 권한이 부족하면 설치 중 해당 단계에서 사유와 함께 중단됩니다.',
    'warning_db_server_version_low' => '데이터베이스 서버 버전(:server)이 권장 최소 버전(:minimum)보다 낮습니다. 설치를 시도할 수는 있으나 진행 중 실패할 수 있습니다.',
    'log_memory_limit_raise_shortfall' => '메모리 한도를 :target 으로 올리지 못했습니다 (현재 :current). 설치는 현재 한도로 진행하며, 메모리 부족으로 중단될 수 있습니다.',
    'php_limits_open_basedir' => 'open_basedir 경로 제한이 활성화되어 있습니다 — 일부 시스템 경로 탐색(PHP 실행 파일 자동 감지 등)이 제한될 수 있습니다.',
    'error_extension_selection_locked' => '설치가 이미 시작된 상태라 확장 선택을 바꿀 수 없습니다. 선택을 바꾸려면 먼저 [처음부터 다시]로 설치를 초기화해 주세요.',
    'log_db_cleanup_dropped_tables' => '삭제한 테이블: :tables',
    'warning_disk_space_low_headroom' => '설치 자체는 가능하지만, 코어와 기본 확장의 실사용량(약 :recommended)에 비해 여유가 적어 설치 중 디스크가 부족해질 수 있습니다.',
    'error_env_not_writable_start' => '.env 파일에 쓸 수 없어 설치를 시작할 수 없습니다. 이대로 진행하면 설치가 끝나도 완료 표식을 기록하지 못해 설치 화면이 계속 열려 있게 됩니다. 파일 권한을 수정한 뒤 다시 시도해 주세요.',
    'env_not_writable_label' => '쓰기 불가',
    'db_drop_targets_list' => '[강제 진행] 동의 시 삭제될 테이블 (:count개)',
    'db_nested_g7_warning' => '주의: 이 접두사의 삭제 범위에 더 긴 접두사(:prefixes)로 설치된 다른 그누보드 사이트가 포함되어 있습니다. 동의하면 그 사이트의 테이블도 함께 삭제됩니다.',
    'error_db_connection_failed_detail' => ':type 데이터베이스 연결 실패: :error',
    'error_db_test_failed' => '데이터베이스 테스트 중 오류 발생',

    // 에러 메시지 - 검증 (관리자 계정)
    'error_admin_credentials_missing' => '관리자 계정 정보를 찾을 수 없습니다. 설정 단계로 돌아가 관리자 이름·이메일·비밀번호를 다시 입력해주세요.',
    'error_admin_email_invalid' => '유효한 이메일 주소를 입력해주세요.',
    'error_app_name_too_long' => '사이트명은 :max 자를 넘을 수 없습니다.',
    'error_admin_language_invalid' => '올바른 언어를 선택해주세요.',
    'error_admin_name_required' => '관리자 이름을 입력해주세요.',
    'error_admin_password_required' => '관리자 비밀번호를 입력해주세요.',
    'error_admin_password_min' => '비밀번호는 최소 8자 이상이어야 합니다.',
    'error_admin_password_confirm_required' => '비밀번호 확인을 입력해주세요.',
    'error_password_mismatch' => '비밀번호가 일치하지 않습니다.',
    'error_app_name_required' => '사이트 이름을 입력해주세요.',
    'error_app_url_required' => '사이트 URL을 입력해주세요.',
    'error_field_required' => '이 필드는 필수입니다.',

    // 에러 메시지 - 시스템 (요구사항)
    'error_requirements_check_failed' => '요구사항 검증 중 오류가 발생했습니다.',
    'error_php_version_insufficient' => 'PHP 버전이 요구사항을 충족하지 않습니다.',
    'error_php_extensions_missing' => '일부 필수 PHP 확장이 설치되어 있지 않습니다.',
    'error_disk_space_insufficient' => '디스크 공간이 부족합니다.',
    'error_disk_space_unknown' => '디스크 공간을 확인할 수 없습니다.',
    'error_directory_not_writable' => '일부 디렉토리에 쓰기 권한이 없습니다.',

    // 에러 메시지 - 시스템 (요구사항) (상세)
    'error_php_version_insufficient_detail' => 'PHP 버전이 요구사항을 충족하지 않습니다. (현재: :current, 요구: :min+)',
    'error_disk_space_insufficient_detail' => '디스크 공간이 부족합니다. (현재: :current MB, 요구: :min MB)',
    'error_directory_not_writable_detail' => '일부 디렉토리에 쓰기 권한이 없습니다. 아래 명령어를 실행하여 권한을 부여해주세요.',
    'error_required_files_missing' => '필수 파일이 누락되었습니다.',

    'error_step_file_not_found' => '단계 파일을 찾을 수 없습니다. 설치 파일이 손상되었을 수 있습니다.',
    'error_state_file_creation_failed' => '설치 상태 파일(state.json)을 생성할 수 없습니다. storage/installer 디렉토리의 쓰기 권한을 확인해주세요.',
    'error_invalid_step_access' => '올바르지 않은 단계 접근입니다',
    'error_invalid_step_access_message' => '올바른 설치 순서대로 진행해주세요.',

    // 성공 메시지 - 데이터베이스
    'success_db_write_connected' => 'Write 데이터베이스 연결 및 권한 검증에 성공했습니다.',
    'success_db_read_connected' => 'Read 데이터베이스 연결 및 권한 검증에 성공했습니다.',

    // 성공 메시지 - 요구사항
    'success_requirements_met' => '모든 요구사항이 충족되었습니다.',
    'success_php_version' => 'PHP 버전이 요구사항을 충족합니다.',
    'success_php_extensions' => '모든 필수 PHP 확장이 설치되어 있습니다.',
    'success_disk_space' => '디스크 공간이 충분합니다.',
    'success_directories_writable' => '모든 디렉토리가 쓰기 가능합니다.',
    'success_required_files' => '필수 파일이 모두 존재합니다.',

    // 성공 메시지 - 요구사항 (상세)
    'success_php_version_detail' => 'PHP 버전이 요구사항을 충족합니다. (현재: :current, 요구: :min+)',
    'success_disk_space_detail' => '디스크 공간이 충분합니다. (현재: :current MB, 요구: :min MB)',

    // HTTPS 메시지
    'https_enabled' => 'HTTPS가 활성화되어 있습니다. (권장)',
    'https_disabled' => 'HTTPS가 비활성화되어 있습니다. 보안을 위해 HTTPS 사용을 권장합니다.',
    'https_behind_proxy' => '리버스 프록시 뒤에서 구동 중인 것으로 보입니다. 설치 후 .env 에 TRUSTED_PROXIES 를 지정하지 않으면 접속 주소와 방문자 IP 가 프록시 기준으로 인식됩니다. (https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md)',

    // OPcache 메시지
    'opcache_enabled' => 'OPcache가 활성화되어 있습니다. (권장)',
    'opcache_disabled_short' => '비활성화됨 — 페이지가 몇 배 느려집니다',
    'opcache_disabled_warning' => 'OPcache가 비활성화되어 있습니다. 이 상태에서는 방문자가 페이지를 열 때마다 사이트의 모든 PHP 코드를 처음부터 다시 해석합니다. 같은 서버, 같은 사양에서도 응답 속도가 몇 배까지 느려지며 방문자가 많을수록 격차가 커집니다. 설치는 이대로 진행할 수 있지만, 운영에 사용할 서버라면 php.ini에서 opcache.enable=1 을 켠 뒤 웹서버를 재시작하시기를 강력히 권장합니다.',
    'opcache_unknown' => 'OPcache 상태를 확인할 수 없습니다. 서버에서 설정 조회가 제한되어 있습니다.',

    // API 응답 메시지
    'api_method_not_allowed' => 'POST 요청만 허용됩니다.',
    'api_invalid_request' => '잘못된 요청 데이터입니다.',
    'api_unexpected_error' => '예상치 못한 오류가 발생했습니다.',

    // 단계명 (헬퍼 함수용)
    'step_0_welcome' => '환영',
    'step_1_license' => '라이선스 동의',
    'step_2_requirements' => '설치 환경 확인',
    'step_3_configuration' => '설치 정보 입력',
    'step_4_extension_selection' => '확장 기능 선택',
    'step_5_installation' => '설치 진행',
    'step_6_complete' => '완료',
    'step_unknown' => '알 수 없는 단계',

    // 작업명 (헬퍼 함수용)
    'task_composer_check' => '패키지 관리자 확인',
    'task_composer_install' => '필수 패키지 설치',
    'task_env_create' => '환경 설정 파일 생성',
    'task_env_update' => '환경 설정 파일 업데이트',
    'task_key_generate' => '보안 키 생성',
    'task_dependency_precheck' => '확장 의존성 사전 검증',
    'task_db_cleanup' => '기존 DB 테이블 정리',
    'task_db_migrate' => '데이터베이스 테이블 생성',
    'task_db_seed' => '기본 데이터 생성',
    'task_template_install' => '관리자 템플릿 설치',
    'task_template_activate' => '관리자 템플릿 활성화',
    'task_module_install' => '모듈 설치',
    'task_module_activate' => '모듈 활성화',
    'task_plugin_install' => '플러그인 설치',
    'task_plugin_activate' => '플러그인 활성화',
    'task_user_template_install' => '사용자 템플릿 설치',
    'task_user_template_activate' => '사용자 템플릿 활성화',
    'task_language_pack_install' => '언어팩 설치',
    'task_cache_clear' => '임시 파일 정리',
    'task_create_settings_json' => '설정 파일 생성',
    'task_complete_flag' => '설치 완료 처리',
    'task_config_cache' => '설정 캐시 생성',
    'task_static_publish' => '부트스트랩 리소스 정적 게시',
    'task_unknown' => '알 수 없는 작업',

    // 작업 그룹명
    'task_group_environment' => '환경 설정',
    'task_group_database' => '데이터베이스',
    'task_group_admin_templates' => '관리자 템플릿',
    'task_group_modules' => '모듈',
    'task_group_plugins' => '플러그인',
    'task_group_user_templates' => '사용자 템플릿',
    'task_group_language_packs' => '언어팩',
    'task_group_finalize' => '마무리',

    // 그룹 상태 레이블
    'status_label_pass' => '통과',
    'status_label_fail' => '실패',
    'status_label_warning' => '경고',
    'status_label_info' => '정보',
    'status_pending' => '대기',
    'status_in_progress' => '진행 중',
    'status_completed' => '완료',
    'status_failed' => '실패',
    'status_aborted' => '중단',
    'status_skipped' => '건너뜀',
    // 건너뛴 단계가 있는 그룹 — 초록 "완료" 로 덮으면 무엇이 빠졌는지 알 수 없다
    'status_completed_with_skipped' => '완료 (:skipped건 건너뜀)',
    // 경로·바이너리 확인 중 스피너 라벨
    'checking' => '확인 중...',

    // 에러 메시지 - Worker (Composer)
    'error_composer_not_installed' => 'Composer가 설치되어 있지 않습니다.',
    'error_composer_install_failed' => 'Composer 의존성 설치에 실패했습니다',

    // 로그 메시지 - Worker (Composer)
    'log_composer_check_success' => 'Composer 확인 완료',
    'log_composer_check_skipped_bundled' => 'Composer 확인 스킵 — 번들 vendor 모드',
    'log_composer_check_auto_fallback' => 'Composer 미설치 — 번들 vendor 모드로 자동 폴백 예정',
    'log_composer_already_installed' => 'Composer 의존성이 이미 설치되어 있습니다',
    'log_composer_install_success' => 'Composer 의존성 설치 완료',
    'log_composer_vendor_without_lock' => 'vendor 디렉토리는 있지만 composer.lock 파일이 없습니다 (불완전 상태)',
    'log_composer_removing_vendor' => 'vendor 디렉토리를 삭제하고 재설치합니다...',
    'log_composer_vendor_deleted' => 'vendor 디렉토리 삭제 완료',
    'log_composer_vendor_delete_failed' => 'vendor 디렉토리 삭제 실패 (계속 진행합니다)',
    'log_composer_installing_from_lock' => 'composer.lock 파일을 사용하여 의존성을 설치합니다...',
    'log_composer_fresh_install' => '새로운 Composer 의존성을 설치합니다...',
    'log_composer_cache_cleared' => '이전 패키지 캐시를 삭제했습니다',

    // 에러 메시지 - Worker (.env)
    'error_env_example_not_found' => '.env.example 파일을 찾을 수 없습니다',
    'error_env_create_failed' => '.env 파일 생성에 실패했습니다',
    'error_env_not_found' => '.env 파일이 존재하지 않습니다.',

    // 로그 메시지 - Worker (.env)
    'log_env_create_success' => '.env 파일 생성 완료',
    'log_env_update_success' => '.env 파일 업데이트 완료',
    'log_env_readonly_skip' => '.env 파일에 쓰기 권한이 없어 업데이트를 건너뜁니다.',
    'log_env_flag_skipped' => '.env 파일에 쓰기 권한이 없어 설치 완료 플래그를 건너뜁니다.',

    // 에러 메시지 - Worker (Key)
    'error_key_generate_failed' => 'Application Key 생성에 실패했습니다',

    // 로그 메시지 - Worker (Key)
    'log_key_generate_success' => 'Application Key 생성 완료',

    // 에러 메시지 - Worker (Database)
    'error_db_migrate_failed' => '데이터베이스 테이블 생성에 실패했습니다',
    'error_db_seed_failed' => '기본 데이터 입력에 실패했습니다',

    // 로그 메시지 - Worker (Database)
    'log_db_migrate_success' => '데이터베이스 마이그레이션 완료',
    'log_db_seed_success' => '데이터베이스 시딩 완료',

    // 에러 메시지 - Worker (Template)
    'error_template_install_failed' => '템플릿 설치에 실패했습니다',
    'error_template_activate_failed' => '템플릿 활성화에 실패했습니다',

    // 로그 메시지 - Worker (Template)
    'log_template_install_success' => '템플릿 설치 완료',
    'log_template_activate_success' => '템플릿 활성화 완료',

    // 에러 메시지 - Worker (Module)
    'error_module_install_failed' => '모듈 설치에 실패했습니다',
    'error_module_activate_failed' => '모듈 활성화에 실패했습니다',

    // 로그 메시지 - Worker (Module)
    'log_module_install_success' => '모듈 설치 완료',
    'log_module_activate_success' => '모듈 활성화 완료',

    // 에러 메시지 - Worker (Plugin)
    'error_plugin_install_failed' => '플러그인 설치에 실패했습니다',
    'error_plugin_activate_failed' => '플러그인 활성화에 실패했습니다',

    // 로그 메시지 - Worker (Plugin)
    'log_plugin_install_success' => '플러그인 설치 완료',
    'log_plugin_activate_success' => '플러그인 활성화 완료',

    // 에러 메시지 - Worker (User Template)
    'error_user_template_install_failed' => '사용자 템플릿 설치에 실패했습니다',
    'error_user_template_activate_failed' => '사용자 템플릿 활성화에 실패했습니다',

    // 로그 메시지 - Worker (User Template)
    'log_user_template_install_success' => '사용자 템플릿 설치 완료',
    'log_user_template_activate_success' => '사용자 템플릿 활성화 완료',

    // 에러/로그 메시지 - Worker (Language Pack)
    'error_language_pack_install_failed' => '언어팩 설치에 실패했습니다',
    'log_language_pack_install_success' => '언어팩 설치 완료',
    'warning_language_pack_install_partial' => '언어팩 일부 설치에 실패했습니다: :identifier (계속 진행)',
    'log_composer_unavailable_fallback_bundle' => '이 서버에서는 Composer 를 실행할 수 없어 동봉된 묶음으로 설치합니다.',
    'warning_best_effort_task_failed' => ':task 단계가 실패했지만 설치에 필수가 아니라 계속 진행합니다 (재시도 시 다시 시도) — :error',
    'task_vendor_bundle_extract' => 'Vendor 번들 추출',
    'log_vendor_bundle_extract_start' => 'Vendor 번들 추출을 시작합니다...',
    'log_vendor_bundle_verified' => 'vendor-bundle.zip 검증 통과 (:count packages)',
    'log_vendor_bundle_extracting' => 'vendor/ 디렉토리에 추출 중...',
    'error_vendor_bundle_extract_failed' => 'vendor 번들 추출 실패: :error',
    'log_vendor_bundle_extract_done' => 'vendor 번들 추출 완료 (:count packages)',
    'log_vendor_bundle_extract_complete' => 'vendor 번들 추출 완료',
    'stepwise_lock_lost_retry' => '다른 요청이 같은 단계를 이어받아 이 요청은 물러납니다. 잠시 후 이어서 진행합니다.',
    'log_stepwise_lock_taken_over' => '앞선 요청이 응답하지 않아 이 요청이 단계 실행을 이어받았습니다.',
    'log_worker_lock_lost_retreat' => '다른 요청이 설치 진행을 이어받아 이 요청은 물러납니다.',
    'log_worker_disconnected_abort' => '브라우저 연결이 끊어져 이 요청을 종료합니다.',
    'log_worker_started' => '설치 작업을 시작합니다.',
    'log_worker_client_ip' => '요청 IP: :ip',
    'log_worker_rejected_busy' => '다른 요청이 이미 설치를 진행하고 있어 이 요청은 시작하지 않습니다.',
    'log_worker_lock_acquired' => '설치 진행 권한을 확보했습니다.',
    'log_php_binary_path_rejected' => 'PHP 실행 경로 :path 은(는) 허용되지 않는 형태라 사용하지 않고 기본값 php 로 진행합니다. (공백·특수문자가 있는 경로는 쓸 수 없습니다)',

    // 에러 메시지 - Worker (Cache)
    'error_cache_clear_failed' => '캐시 클리어에 실패했습니다',
    'error_config_cache_failed' => '설정 캐시 생성에 실패했습니다',
    'error_static_publish_failed' => '부트스트랩 리소스 정적 게시에 실패했습니다 (사이트는 API 경로로 정상 동작합니다)',

    // 로그 메시지 - Worker (Cache)
    'log_cache_clear_success' => '캐시 클리어 완료',
    'log_config_cache_success' => '설정 캐시 생성 완료',
    'log_static_publish_success' => '부트스트랩 리소스 정적 게시 완료',

    // 에러 메시지 - Worker (Settings JSON)
    'error_settings_json_failed' => '설정 파일 생성에 실패했습니다',

    // 로그 메시지 - Worker (Settings JSON)
    'log_creating_settings' => '설정 JSON 파일 생성 중...',
    'log_settings_json_category_created' => '  - :category 설정 파일 생성',
    'log_settings_json_category_skipped' => '  - :category 설정 파일 건너뜀 (기본값 없음)',
    'log_settings_json_created' => '설정 JSON 파일 생성 완료',

    // 에러 메시지 - Worker (Admin)
    'error_autoload_not_found' => 'vendor/autoload.php 파일을 찾을 수 없습니다',
    'error_app_bootstrap_not_found' => 'bootstrap/app.php 파일을 찾을 수 없습니다',

    // 로그 메시지 - Worker (Complete)
    'log_complete_flag_success' => '설치 완료 플래그 설정 완료',
    'log_installation_completed' => '설치가 성공적으로 완료되었습니다',
    'log_installation_failed' => '설치 실패',
    'log_installation_task_failed' => '[실패] :task: :message',
    'log_installation_exception' => '[실패] 설치 중 오류 발생: :error',

    // Worker 로그 메시지 (작업 진행)
    'log_task_in_progress' => ':task 작업 중...',
    'log_task_completed' => ':task 작업 완료',
    'log_task_skipped' => ':task (이미 존재하여 건너뜀)',
    'manual_commands_guide' => '💡 아래 명령어를 수동으로 실행할 수 있습니다:',
    'log_separator' => '========================================',
    'log_error_occurred' => '❌ 오류 발생: :error',
    'log_env_file_created' => '.env 파일 생성 완료',
    'log_env_flag_added' => '.env 파일에 설치 완료 플래그 추가',
    'log_installed_flag_created' => 'g7_installed 플래그 파일 생성',
    'log_state_updated' => '설치 상태 업데이트 완료',
    'log_all_tasks_completed' => '🎉 모든 설치 작업이 완료되었습니다!',
    'log_already_completed' => ':task (이미 완료됨)',

    // Worker 중단 관련 메시지
    'abort_connection_lost' => '[중단] 브라우저 연결이 끊어졌습니다.',
    'abort_rollback_start' => "[중단] 현재 작업 ':task' 롤백을 시작합니다.",
    'abort_rollback_success' => '[중단] 롤백 완료: :message',
    'abort_rollback_failed' => '[중단] 롤백 실패: :message (계속 진행)',
    'abort_no_rollback_needed' => '[중단] 롤백할 작업이 없습니다. (current_task가 null이거나 이미 완료됨)',
    'abort_by_user' => '[중단] 사용자가 설치를 중단했습니다. (현재 작업: :task)',
    'abort_installation_stopped' => '설치가 중단되었습니다.',

    // Worker 실패 시 롤백 관련 메시지
    'failed_rollback_start' => "[실패] 실패한 작업 ':task' 롤백을 시작합니다.",
    'failed_rollback_success' => '[실패] 롤백 완료: :message',
    'failed_rollback_failed' => '[실패] 롤백 실패: :message',
    'failed_rollback_manual_cleanup' => '[안내] 롤백에 실패했습니다. 데이터베이스를 수동으로 정리한 후 다시 설치를 시도해주세요.',
    'failed_rollback_manual_cleanup_detail' => '데이터베이스 관리 도구(phpMyAdmin 등)를 사용하여 생성된 테이블을 삭제하거나, 데이터베이스를 초기화한 후 다시 설치를 진행하세요.',
    'failed_rollback_db_restart' => '[안내] 데이터베이스가 롤백되었습니다. 재시도 시 데이터베이스 설치부터 다시 진행됩니다.',
    'failed_rollback_retry' => '[안내] 작업에 실패했습니다. 설치를 다시 시도해주세요.',
    'failed_rollback_retry_detail' => '재시도 시 실패한 작업부터 다시 진행됩니다. 문제가 지속되면 수동 명령어를 참고하세요.',

    // Worker SSE 연결 메시지
    'sse_connection_established' => 'SSE 연결이 설정되었습니다',
    'sse_method_not_allowed' => '허용되지 않는 메서드입니다. SSE는 GET 요청이 필요합니다.',

    // DB 작업 중 중단 불가 메시지
    'db_task_no_abort' => '데이터베이스 작업 중에는 중단할 수 없습니다. 작업 완료 후 중단됩니다.',
    'db_task_in_progress' => '데이터베이스 작업 중...',

    // Worker 에러 상세 메시지
    'error_composer_process_failed' => 'Composer 설치 프로세스 시작 실패',
    'error_composer_exit_code' => 'Composer 설치 실패 (종료 코드: :code)',
    'error_env_example_not_found_detail' => '.env.example 파일을 찾을 수 없습니다: :path',
    'error_env_write_failed' => '.env 파일 쓰기 실패: :path',
    'error_complete_flag_failed' => '설치 완료 플래그 설정 실패',
    'error_installed_flag_write_failed' => '설치 완료 표시 파일을 저장하지 못했습니다 (:path). storage 디렉토리 쓰기 권한을 확인해 주세요.',
    'error_unexpected_exception' => '예기치 않은 오류가 발생했습니다',
    'error_binary_probe_unavailable' => '이 서버는 외부 명령 실행이 제한되어 있어 경로를 확인할 수 없습니다. CLI 없이 설치가 진행되므로 이 항목은 비워 두어도 됩니다.',

    // install-process.php 메시지
    'error_method_not_allowed' => 'POST 요청만 허용됩니다.',
    'error_config_not_in_session' => '설정 정보가 세션에 없습니다. Step 3에서 설정을 먼저 완료해주세요.',
    'error_required_fields_missing' => '필수 설정 항목이 누락되었습니다: :fields',
    'log_installation_config_saved' => '설치 설정 저장 완료 - SSE 연결 대기 중',
    'success_installation_started' => '설치가 시작되었습니다.',
    'error_installation_start_failed' => '설치 프로세스 시작 실패',
    'error_installation_start_exception' => '설치 시작 중 오류가 발생했습니다: :error',

    // get-install-state.php 메시지
    'error_get_method_required' => 'GET 요청만 허용됩니다.',
    'error_state_query_failed' => '설치 상태 조회 중 오류가 발생했습니다.',

    // 새로고침/페이지 이탈 경고 (브라우저가 기본 메시지로 표시하지만, 의도 명확화)
    'confirm_leave_installation' => '설치가 진행 중입니다. 페이지를 나가면 설치에 문제가 발생할 수 있습니다.',
    'confirm_resume_installation' => '설치가 진행 중이었습니다. 설치를 재개하시겠습니까?',
    'resume_installation_btn' => '설치 재개',
    'cancel_installation_btn' => '취소',

    // 설치 완료 후 재접근 메시지
    'installation_already_completed' => '설치 완료',
    'installation_already_completed_message' => '그누보드7이 이미 설치되었습니다. 홈페이지로 이동합니다.',

    // URL 파라미터 접근 메시지
    'url_parameter_not_supported' => 'URL 파라미터 사용 불가',
    'url_parameter_redirect_message' => '설치 과정은 세션 기반으로 관리됩니다. 요청하신 Step :requested 대신 현재 진행 중인 Step :current(으)로 이동합니다.',

    // 설치 중단 관련
    'abort_installation' => '설치 중단',
    'aborting' => '중단 중',
    'confirm_abort_installation' => '정말 설치를 중단하시겠습니까? 중단 시 현재까지의 설치 내용은 유지되며, 나중에 중단된 지점부터 다시 시작할 수 있습니다.',
    'installation_aborted' => '설치가 중단되었습니다',
    'installation_aborted_message' => '설치 도중 브라우저가 닫혔거나 새로고침되었습니다. 중단된 지점부터 다시 설치할 수 있습니다.',
    'installation_not_controllable_message' => '이 창에서는 진행 중인 설치를 이어받거나 멈출 수 없습니다. 설치를 시작한 창에서 계속해 주세요. 그 창을 닫았다면 설치가 스스로 끝나거나 멈출 때까지 기다린 뒤 다시 들어와 주세요.',
    'clean_state' => '상태 초기화',
    'cleaning' => '초기화 중',
    'state_cleaned' => '상태가 초기화되었습니다',
    'clean_failed' => '초기화 실패',
    'clean_error' => '초기화 중 오류 발생',
    'resuming' => '재개 중',
    'resume_failed' => '재개 실패',
    'resume_error' => '재개 중 오류 발생',
    'abort_failed' => '중단 실패',
    'abort_error_occurred' => '중단 중 오류 발생',

    // 검증 에러 메시지 (validation.php)
    'validation_required' => ':field은(는) 필수 항목입니다.',
    'validation_email' => ':field의 형식이 올바르지 않습니다.',
    'validation_min' => ':field은(는) 최소 :min자 이상이어야 합니다.',
    'validation_max' => ':field은(는) 최대 :max자까지 입력 가능합니다.',
    'validation_url' => ':field은(는) 올바른 URL 형식이어야 합니다.',
    'validation_alpha_num' => ':field은(는) 영문자와 숫자만 입력 가능합니다.',
    'validation_alpha_num_underscore' => ':field은(는) 영문 소문자, 숫자, 언더스코어(_)만 입력 가능합니다.',
    'validation_starts_with_alpha' => ':field은(는) 영문 소문자로 시작해야 합니다.',
    'validation_confirmed' => ':field 확인이 일치하지 않습니다.',
    'validation_numeric' => ':field은(는) 숫자여야 합니다.',
    'validation_integer' => ':field은(는) 정수여야 합니다.',
    'validation_in' => ':field은(는) 다음 중 하나여야 합니다: :values',

    // 필드명 레이블 (validation.php)
    'fields' => [
        'db_host' => '데이터베이스 호스트',
        'db_port' => '데이터베이스 포트',
        'db_database' => '데이터베이스명',
        'db_username' => '데이터베이스 사용자명',
        'db_password' => '데이터베이스 비밀번호',
        'db_write_host' => 'Write 데이터베이스 호스트',
        'db_write_port' => 'Write 데이터베이스 포트',
        'db_write_database' => 'Write 데이터베이스명',
        'db_write_username' => 'Write 데이터베이스 사용자명',
        'db_write_password' => 'Write 데이터베이스 비밀번호',
        'db_prefix' => '테이블 접두사',
        'db_read_host' => 'Read 데이터베이스 호스트',
        'db_read_port' => 'Read 데이터베이스 포트',
        'db_read_database' => 'Read 데이터베이스명',
        'db_read_username' => 'Read 데이터베이스 사용자명',
        'db_read_password' => 'Read 데이터베이스 비밀번호',
        'app_name' => '사이트 이름',
        'app_url' => '사이트 URL',
        'admin_name' => '관리자 이름',
        'admin_language' => '관리자 언어',
        'admin_email' => '관리자 이메일',
        'admin_password' => '관리자 비밀번호',
        'admin_password_confirmation' => '관리자 비밀번호 확인',
        'email' => '이메일',
        'password' => '비밀번호',
        'password_confirmation' => '비밀번호 확인',
    ],

    // JavaScript 클라이언트 메시지 (installer.js)
    'testing_connection' => '연결을 테스트하는 중...',
    'connection_failed_prefix' => '연결 실패:',
    'validation_incomplete_alert' => '필수 항목을 모두 입력하고 DB 연결 테스트를 완료해주세요.',
    'validation_incomplete_title' => '다음 항목을 완료해주세요:',
    'confirm_leave_page' => '설정이 저장되지 않았습니다. 페이지를 나가시겠습니까?',
    'installation_in_progress_alert' => '설치가 진행 중입니다. 설정 페이지로 돌아가시겠습니까?',
    'confirm_go_to_settings' => '설정 페이지로 이동하시겠습니까?',
    'confirm_go_to_settings_simple' => "설정 페이지로 이동하시겠습니까?\n\n설치 상태가 초기화되며, 모든 작업이 처음부터 다시 실행됩니다.\n\n⚠️ 데이터베이스에 생성된 테이블은 자동으로 삭제되지 않습니다.\n필요 시 phpMyAdmin 등을 통해 수동으로 정리해주세요.",
    'confirm_go_to_settings_title' => '설정 페이지로 이동',
    'confirm_go_to_settings_desc' => "설치 상태가 초기화되며, 모든 작업이 처음부터 다시 실행됩니다.\n\n⚠️ 데이터베이스에 생성된 테이블은 자동으로 삭제되지 않습니다. 필요 시 phpMyAdmin 등을 통해 수동으로 정리해주세요.",
    'confirm_go_to_settings_btn' => '이동',
    'reset_db_checkbox_label' => '데이터베이스 초기화 (생성된 테이블 삭제)',
    'error_state_reset_failed' => "상태 초기화에 실패했습니다.\n\n:message\n\n페이지를 새로고침 후 다시 시도해주세요.",
    'confirm_force_go_to_settings' => "상태 초기화 중 오류가 발생했습니다.\n\n그래도 설정 페이지로 이동하시겠습니까?",
    'view_error_details' => '오류 상세 보기',
    'view_command_output' => '명령 상세 보기',
    'installation_error_occurred' => '설치 중 오류가 발생했습니다.',
    'installation_start_error' => '설치 시작 중 오류 발생',
    'installation_start_failed' => '설치를 시작할 수 없습니다.',
    'unknown_error_occurred' => '알 수 없는 오류가 발생했습니다.',

    // SSE 연결 실패 메시지
    'sse_connection_timeout' => 'SSE 실시간 연결에 실패했습니다. 서버 설정을 확인하거나 페이지를 새로고침해주세요.',
    'sse_server_config_guide' => '서버 관리자이신 경우 아래 설정을 확인해주세요:

[Nginx 설정]
location /install/api/ {
    proxy_buffering off;
    proxy_cache off;
    proxy_read_timeout 600s;
}

[Apache 설정]
# .htaccess 또는 httpd.conf
<Location /install/api/>
    SetEnv no-gzip 1
</Location>

[PHP 설정]
// install-worker.php 최상단에 추가
ini_set(\'output_buffering\', \'off\');
ini_set(\'zlib.output_compression\', \'off\');
@apache_setenv(\'no-gzip\', 1);

[공유 호스팅]
호스팅 제공업체에 SSE(Server-Sent Events) 지원 여부를 문의해주세요.
방화벽이나 프록시에서 long-lived HTTP 연결을 차단하고 있을 수 있습니다.',

    // 설치 진행 방식 (SSE / 폴링)
    'installation_mode_label' => '설치 진행 방식',
    'installation_mode_sse_title' => '실시간 스트리밍 (SSE) — 권장',
    'installation_mode_sse_desc' => '서버가 진행 상황을 즉시 전송합니다. 일반 환경에서 가장 빠르고 안정적입니다.',
    'installation_mode_polling_title' => '호환성 모드 (폴링)',
    'installation_mode_polling_desc' => 'Nginx 프록시, 공유 호스팅 등 SSE 연결 오류가 발생하는 환경에서 사용하세요. 1초마다 상태를 확인합니다.',
    'sse_fallback_confirm' => "SSE 연결에 실패했습니다.\n\n호환성 모드(폴링)로 다시 시도하시겠습니까?",
    'sse_probe_incompat_use_polling' => "현재 환경에서는 SSE 실시간 연결을 사용할 수 없습니다.\n\n호환성 모드(폴링)로 설치를 진행하시겠습니까?\n\n[확인] 폴링 모드로 설치 시작\n[취소] 설치 시작 화면으로 돌아가기",
    'start_installation_button' => '설치 시작',

    // Step 4 의존성 경고
    'dependency_warning_title' => '의존성 누락 감지',
    'dependency_warning_description' => '다음 확장이 필요합니다. "필요한 항목 자동 선택" 버튼을 눌러 한 번에 해결할 수 있습니다.',
    'dependency_auto_select' => '필요한 항목 자동 선택',
    'dependency_missing_tooltip' => '의존성을 먼저 해결해주세요',
    'dependency_precheck_failed' => '의존성 사전 검증 실패 — Step 4로 돌아가 누락된 모듈/플러그인을 선택해주세요',

    // Step 3 기존 DB 감지
    'db_existing_g7_badge' => '기존 그누보드7 설치 감지',
    'db_existing_foreign_badge' => '다른 데이터 감지',
    'db_existing_mixed_badge' => 'G7 일부 + 다른 테이블 혼재',
    'db_existing_g7_title' => '그누보드7 기존 설치 감지',
    'db_existing_foreign_title' => '다른 데이터 감지',
    'db_existing_mixed_title' => '데이터 혼재 감지',
    'db_existing_generic_title' => '기존 테이블 감지',
    'db_existing_g7_desc' => '선택한 DB에 이미 그누보드7이 설치되어 있습니다. 강제 진행 시 입력한 테이블 접두사로 시작하는 기존 그누보드7 테이블이 삭제됩니다. 반드시 백업 후 진행하세요.',
    'db_existing_foreign_desc' => '선택한 DB에 알 수 없는 테이블이 존재합니다. 강제 진행 시 입력한 테이블 접두사로 시작하는 테이블만 삭제되며, 접두사가 다른 테이블은 보존됩니다. 반드시 백업 후 진행하세요.',
    'db_existing_mixed_desc' => '선택한 DB에 G7 일부 테이블과 다른 테이블이 혼재합니다. 강제 진행 시 입력한 테이블 접두사로 시작하는 테이블만 삭제되며, 접두사가 다른 테이블은 보존됩니다.',
    'db_existing_generic_desc' => '선택한 DB에 기존 테이블이 존재합니다. 강제 진행 시 입력한 테이블 접두사로 시작하는 테이블만 삭제됩니다.',
    'db_existing_tables_list' => '감지된 테이블 (최대 20개):',
    'db_backup_guide' => '백업 명령어 예시 (호스트/사용자/데이터베이스명 확인 후 실행하세요):',
    'db_backup_confirmed' => '백업을 완료했으며, 입력한 접두사로 시작하는 기존 테이블이 삭제되는 것에 동의합니다',
    'error_db_cleanup_consent_required' => '기존 테이블 삭제에 동의하셔야 다음 단계로 진행할 수 있습니다.',
    'log_db_cleanup_skipped' => '기존 테이블 정리 건너뛰기 (액션 없음)',
    'log_db_cleanup_empty' => '기존 테이블이 없습니다. 정리 건너뛰기',
    'log_db_cleanup_dropping' => '기존 테이블 :count개 삭제 시작...',
    'log_db_cleanup_done' => '기존 테이블 :count개 삭제 완료',
    'error_db_cleanup_failed' => '기존 DB 테이블 정리 실패',

    // 다크모드
    'toggle_theme' => '테마 전환',

    // Step 4: 확장 기능 선택
    'extension_selection_description' => '설치할 확장 기능을 선택하세요. 관리자 템플릿은 필수 선택입니다.',
    'loading_extensions' => '확장 기능을 불러오는 중...',
    'admin_templates' => '관리자 템플릿',
    'admin_templates_description' => '관리자 페이지에서 사용할 템플릿을 선택하세요.',
    'user_templates' => '사용자 템플릿',
    'user_templates_description' => '프론트 페이지에서 사용할 템플릿을 선택하세요.',
    'modules' => '모듈',
    'modules_description' => '설치할 모듈을 선택하세요. 모듈은 게시판, 이커머스 등 주요 기능을 제공합니다.',
    'plugins' => '플러그인',
    'plugins_description' => '설치할 플러그인을 선택하세요. 플러그인은 결제, 알림 등 부가 기능을 제공합니다.',
    'language_packs' => '언어팩',
    'language_packs_description' => '추가 언어 지원을 위한 번들 언어팩을 선택하세요. 종속 확장이 함께 선택되어야 활성화됩니다.',
    'optional' => '선택',
    'no_extensions_found' => '사용 가능한 확장이 없습니다.',
    'no_user_templates' => '사용 가능한 사용자 템플릿이 없습니다.',
    'no_modules' => '사용 가능한 모듈이 없습니다.',
    'no_plugins' => '사용 가능한 플러그인이 없습니다.',
    'no_language_packs' => '사용 가능한 번들 언어팩이 없습니다.',
    'language_pack_disabled_by_extension' => '종속된 확장이 선택되지 않아 비활성화되었습니다.',
    'language_pack_scope_core' => '코어',
    'language_pack_scope_module' => '모듈',
    'language_pack_scope_plugin' => '플러그인',
    'language_pack_scope_template' => '템플릿',
    'bulk_select_all_optional' => '선택 가능한 모든 항목 선택',
    'bulk_select_section' => '전체 선택',

    // 브랜드명
    'brand_name' => '그누보드7',

    // Step 3 — Vendor 설치 방식
    'vendor_mode_title' => 'Vendor 설치 방식',
    'vendor_mode_description' => 'PHP 패키지 의존성(vendor/) 을 어떤 방식으로 설치할지 선택합니다.',
    'vendor_mode_auto_title' => '자동',
    'vendor_mode_auto_description' => 'Composer 사용 가능 시 Composer, 불가 시 번들 vendor 사용',
    'vendor_mode_auto_status_inprocess' => '이 서버에서는 자동 선택을 쓸 수 없어 동봉된 묶음으로 고정됩니다.',
    'vendor_mode_auto_status_ok' => '환경 자동 감지',
    'vendor_mode_composer_title' => 'Composer 실행',
    'vendor_mode_composer_description' => 'composer install 명령으로 최신 버전 설치 (인터넷 + proc_open 필수)',
    'vendor_mode_composer_status_ok' => 'proc_open 사용 가능',
    'vendor_mode_composer_status_blocked' => 'proc_open() 차단됨 — 사용 불가',
    'vendor_mode_bundled_title' => '번들 Vendor 사용',
    'vendor_mode_bundled_description' => 'vendor-bundle.zip 을 추출 (오프라인 설치, composer 불필요)',
    'vendor_mode_bundled_status_ok' => 'vendor-bundle.zip 발견',
    'vendor_mode_bundled_status_no_ziparchive' => 'ZipArchive 확장 미설치 — 사용 불가',
    'vendor_mode_bundled_status_no_zip' => 'vendor-bundle.zip 파일 없음 — 사용 불가',
    'vendor_mode_badge_recommended' => '권장',
    'vendor_mode_badge_dev' => '개발 환경',
    'vendor_mode_badge_shared' => '공유 호스팅',
    'vendor_mode_change_later_admin' => '이 설정은 설치 후 관리자 화면(환경설정 › 시스템)에서 변경할 수 있습니다.',
    'vendor_mode_change_later_html' => '이 설정은 추후 <code>php artisan core:update --vendor-mode=...</code> 옵션으로 변경할 수 있습니다.',

    // 설치 진행 중단 감지
    // 서버가 본문 없는 응답을 돌려준 경우 (gnuboard/g7#62)
    'error_empty_server_response' => '서버가 빈 응답을 반환했습니다. 서버 설정이나 계정 이름·경로에 사용된 문자로 인해 응답이 만들어지지 못했을 수 있습니다. storage/logs/installation.log 를 확인해주세요.',
    'error_invalid_server_response' => '서버 응답을 해석할 수 없습니다. 응답에 오류 메시지나 경고문이 섞였을 수 있습니다. storage/logs/installation.log 를 확인해주세요.',
    'error_polling_response_invalid' => '설치 진행 상황을 읽어오지 못했습니다. 서버가 올바른 형식의 응답을 반환하지 않고 있습니다. storage/logs/installation.log 를 확인한 뒤 다시 시도해주세요.',
    'error_installation_stuck_detail' => '설치 작업이 진행 중일 수 있습니다. [다시 시도] 는 끝나지 않은 단계부터 이어서 실행합니다.',
    'error_installation_stuck' => '설치가 :seconds초 동안 응답하지 않습니다. 서버 워커가 명령 실행 중 멈췄을 수 있습니다. 새로고침하거나 재시도하세요. 문제가 반복되면 storage/logs/installation.log 를 확인해주세요.',
    'extension_load_failed' => '확장 기능 목록을 불러오는데 실패했습니다.',
    'scan_directory_listing_unavailable' => '이 서버는 디렉토리 열거(scandir)가 막혀 있어 동봉된 확장을 읽을 수 없습니다. 호스팅 설정에서 해당 함수 차단을 풀어 주세요.',
    'scan_failed_see_log' => '동봉된 확장을 읽는 중 오류가 났습니다. 자세한 내용은 storage/logs/installer.log 를 확인해 주세요.',
    'db_existing_check_failed' => '연결은 되었지만 기존 테이블 목록을 조회하지 못했습니다. 이 데이터베이스에 이미 테이블이 있을 수 있으니 직접 확인해 주세요.',
    'no_admin_template_error' => '관리자 템플릿이 필요하지만 찾을 수 없습니다. templates 디렉토리에 최소 1개 이상의 관리자 템플릿이 있는지 확인해주세요.',
    'selection_summary' => '선택 요약',
    'selected_count' => ':count개 선택됨',
    'admin_template_required' => '관리자 템플릿은 최소 1개 이상 선택해야 합니다.',
    'proceed_to_installation' => '설치 진행',
    'version' => '버전',
    'select' => '선택',
    'selected' => '선택됨',
    'dependencies' => '의존성',
    'dependency_type_module' => '모듈',
    'dependency_type_plugin' => '플러그인',
    'dependency_type_admin_template' => '관리자 템플릿',
    'dependency_type_user_template' => '사용자 템플릿',
    'dependency_type_other' => '기타',
    'dep_auto_badge_label' => '필수 의존성',
    'dep_lock_message' => ':names 이(가) 이 항목을 필요로 합니다. 먼저 해당 확장을 해제해주세요.',
    'dep_version_required' => '필요 버전',
    'dep_version_available' => '설치 가능 버전',
    'author' => '제작자',
    'retry' => '재시도',
    'select_all' => '전체 선택',
    'deselect_all' => '전체 해제',

    // install-worker.php 다국어 키
    'db_task_abort_detected_before_start' => '[DB 작업] 시작 전 중단 상태 감지 - 작업을 건너뜁니다.',
    'db_task_failed_rollback_start' => '[DB 작업] :task 실패 - 롤백을 시작합니다.',
    'db_task_abort_reason_connection' => '연결 끊김',
    'db_task_abort_reason_user' => '사용자 요청',
    'db_task_completed_abort_detected' => '[DB 작업] :task 완료 후 중단 감지 (:reason) - 롤백을 시작합니다.',
    'db_task_completed_rollback_start' => '[중단] :task 완료 후 롤백을 시작합니다.',
    'log_removing_state_file' => '인스톨러 상태 파일을 제거합니다...',
    'log_state_file_removed' => '인스톨러 상태 파일이 제거되었습니다.',
    'log_state_file_remove_failed' => '경고: 인스톨러 상태 파일 제거에 실패했습니다.',
    'error_worker_exception' => '설치 작업 중 예외 발생',
    'error_worker_terminated' => '설치 작업이 서버에서 강제로 종료되었습니다. (메모리 부족·실행 시간 초과 등)',

    // rollback-functions.php 다국어 키
    'log_prefix_rollback' => '[롤백]',
    'log_prefix_force_rollback' => '[강제 롤백]',
    'rollback_not_needed_recreatable' => '롤백 불필요 (새로 생성 가능)',
    'rollback_not_needed_overwritable' => '롤백 불필요 (재설치/재활성화 시 덮어쓰기 가능)',
    'rollback_unknown_task' => '알 수 없는 작업: :task',
    'rollback_error' => '[롤백 오류] :task: :error',
    'rollback_exception' => '롤백 중 예외 발생: :error',
    'rollback_migrate_start' => '마이그레이션 롤백을 시작합니다 (migrate:rollback).',
    'rollback_migrate_result' => 'migrate:rollback 실행 결과: :result',
    'rollback_migrate_success' => '마이그레이션 롤백 완료',
    'rollback_migrate_failed_code' => '마이그레이션 롤백 실패 (returnCode: :code)',
    'rollback_migrate_failed' => '마이그레이션 롤백 실패',
    'rollback_migrate_error' => '마이그레이션 롤백 중 오류: :error',
    'rollback_migrate_range_unknown' => '되돌릴 마이그레이션 범위를 확인할 수 없어 롤백을 건너뜁니다. 데이터베이스를 직접 확인해 주세요.',
    'rollback_migrate_nothing_applied' => '이번 설치가 적용한 마이그레이션이 없어 되돌릴 것이 없습니다.',
    'rollback_seed_no_config' => 'DB 설정 정보가 없어 시드 롤백을 건너뜁니다.',
    'rollback_seed_no_target_tables' => '이 설치의 접두사에 해당하는 시드 테이블이 없어 되돌릴 것이 없습니다.',
    'rollback_seed_already_done' => '시드 이미 완료됨 (롤백 불필요)',
    'rollback_seed_interrupted' => '시드가 중단되었습니다. 테이블을 비웁니다.',
    'rollback_seed_force_truncate' => '시드 데이터를 무조건 삭제합니다.',
    'rollback_table_truncated' => "테이블 ':table'을 비웠습니다.",
    'rollback_table_truncate_skipped' => "테이블 ':table' TRUNCATE 건너뜀: :error",
    'rollback_seed_data_deleted' => '시드 데이터를 삭제했습니다.',
    'rollback_db_connection_failed' => 'DB 연결 실패: :error',
    'rollback_db_connection_failed_skip' => 'DB 연결 실패. 시드 롤백을 건너뜁니다.',
    'rollback_seed_error' => '시드 처리 중 오류: :error',
    'rollback_env_flag_removed' => '.env에서 INSTALLER_COMPLETED를 제거했습니다.',
    'rollback_installed_flag_removed' => 'g7_installed 파일을 삭제했습니다.',
    'error_settings_dir_create_failed' => '설정 디렉토리를 만들지 못했습니다 (:path). storage/app 쓰기 권한을 확인해 주세요.',
    'error_settings_json_write_failed' => '설정 파일을 저장하지 못했습니다 (:path). storage/app/settings 쓰기 권한을 확인해 주세요.',
    'rollback_env_flag_read_failed' => '.env 파일을 읽지 못해 설치 완료 표식을 지우지 못했습니다. 파일 권한을 확인해 주세요.',
    'rollback_env_flag_write_failed' => '.env 파일에 쓰지 못해 설치 완료 표식을 지우지 못했습니다. 파일 권한을 확인해 주세요.',
    'rollback_installed_flag_remove_failed' => 'g7_installed 파일을 삭제하지 못했습니다. 파일 권한을 확인해 주세요.',
    'rollback_complete_flag_partial' => '설치 완료 표식을 완전히 지우지 못했습니다 (:details). 표식이 남아 있으면 설치 화면이 다시 열리지 않습니다.',
    'rollback_runtime_removed' => '인스톨러 런타임 설정 파일을 정리했습니다.',
    'error_worker_busy' => '다른 인스톨 워커가 진행 중입니다. 잠시 후 다시 시도하세요.',
    'rollback_complete_flag_removed' => '설치 완료 플래그를 제거했습니다: :details',
    'rollback_complete_flag_error' => '완료 플래그 제거 중 오류: :error',
    'rollback_no_current_task' => '롤백할 현재 작업이 없습니다. (current_task가 null)',
    'rollback_task_already_completed' => "':task'은(는) 이미 완료된 작업입니다. (롤백 불필요)",
    'rollback_current_task_start' => "[중단] 현재 작업 ':task' 롤백을 시작합니다.",
    'rollback_no_completed_tasks' => '롤백할 완료된 작업이 없습니다.',
    'rollback_checking_tasks' => '롤백 가능한 작업들을 확인합니다...',
    'rollback_no_matching_tasks' => '롤백할 작업이 없습니다. (rollbackable 목록과 매칭되는 작업 없음)',
    'rollback_tasks_to_rollback' => '롤백할 작업: :tasks',
    'rollback_task_rolling_back' => "':task' 작업을 롤백합니다...",
    'rollback_task_success' => "':task' 롤백 성공",
    'rollback_task_failed' => "':task' 롤백 실패: :error",
    'installer_disabled_completed' => '설치가 이미 완료되어 인스톨러를 사용할 수 없습니다.',
    'manual_cmd_dependency_precheck' => '설치할 확장 선택으로 돌아가 의존 확장을 함께 선택한 뒤 다시 시도하세요.',
    'manual_cmd_db_cleanup' => '데이터베이스 도구에서 기존 테이블을 직접 삭제한 뒤 다시 시도하세요.',
    'manual_cmd_settings_json' => 'settings.json은 자동으로 생성됩니다. 설치를 다시 시도하세요.',

    // state-management.php 다국어 키
    'state_reset_requested' => '설정 페이지로 이동을 요청했습니다.',
    'state_reset_db_notice' => 'DB는 자동으로 초기화되지 않습니다. 필요 시 수동으로 정리해주세요.',
    'state_reset_discarded_corrupt' => '설치 진행 정보가 손상되어 있어 초기화하면서 함께 정리했습니다. 손상된 원본은 진단용으로 보관됩니다.',
    'state_save_failed' => '상태 파일 저장에 실패했습니다.',
    'state_reset_completed' => '설치 상태가 초기화되었습니다. (Step :step(으)로 이동)',
    'abort_api_requested' => '[중단 API] abort 요청됨 - 사용자가 설치 중단 요청',
    'abort_api_current_status' => '[중단 API] 현재 installation_status: :status',
    'abort_api_current_task' => '[중단 API] 현재 current_task: :task',
    'abort_api_completed_count' => '[중단 API] 완료된 작업 수: :count',
    'abort_api_already_completed' => '설치가 이미 완료되어 중단할 수 없습니다.',
    'abort_api_already_aborted' => '설치가 이미 중단되었습니다.',
    'abort_api_not_running' => '중단할 설치가 없습니다.',
    // 로그 전용 — 사용자 문구(abort_api_not_running)는 자리표시자 없이 유지한다.
    // 그 키에 :status 를 넣으면 파라미터 없이 부르는 응답 메시지에 자리표시자가 노출된다.
    'abort_api_not_running_log' => '중단할 설치가 없습니다. (현재 상태: :status)',
    'abort_user_requested' => '사용자가 설치 중단을 요청했습니다.',
    'abort_api_worker_retreated' => '진행 중이던 작업이 물러났습니다 (:seconds초 대기). 되돌리기를 시작합니다.',
    'abort_api_worker_wait_timeout' => '진행 중이던 작업이 :seconds초 안에 물러나지 않아 더 기다리지 않고 되돌리기를 시작합니다.',
    'abort_api_status_change' => '[중단 API] installation_status를 "aborted"로 변경합니다.',
    'abort_api_save_result' => '[중단 API] state.json 저장 결과: :result',
    'abort_api_verify_status' => '[중단 API] 저장 후 확인 - installation_status: :status',
    'error_state_management' => '상태 관리 오류',
    'error_log_prefix' => '[오류] :error',

    // functions.php 다국어 키
    'error_db_name_username_required' => '데이터베이스 이름과 사용자명은 필수입니다.',
    'error_db_invalid_parameter' => '데이터베이스 :field 값에 허용되지 않는 문자가 포함되어 있습니다.',
    'error_db_connection_failed' => '데이터베이스 연결 실패: :error',
    'error_privilege_check_failed' => '권한 검증 중 오류 발생',

    // 필수 파일 생성 안내 (Step 5)
    'file_setup_title' => '필수 파일 생성',
    'file_setup_description' => '설치를 진행하려면 아래 파일이 프로젝트 루트에 필요합니다.',
    'vendor_bundle_backup_revert_failed' => '백업 되돌리기에 실패해 일부 원본이 백업 위치에 남았습니다 (:items). 위치: :path',
    'vendor_bundle_restore_failed' => '백업에서 vendor 복원에 실패했습니다. 원본이 :path 에 남아 있으니 직접 옮겨 주세요.',
    'finalize_rejected_notice' => '설치는 끝났지만 마무리 단계가 거부되었습니다. 설치 화면이 계속 열려 있고 설치용 임시 파일이 남아 있으니, 페이지를 새로고침해 마무리를 다시 시도해 주세요.',
    'file_setup_not_writable' => '아래 파일이 있지만 웹서버가 쓸 수 없습니다. 설치 마무리 단계에서 이 파일을 수정해야 하므로, 쓰기 권한을 준 뒤 다시 확인해 주세요.',
    'file_setup_guide' => '터미널에서 아래 명령어를 프로젝트 루트 디렉토리에서 실행하세요:',
    'file_check_button' => '파일 확인',
    'file_checking' => '확인 중...',
    'files_not_found_yet' => '필수 파일을 아직 찾을 수 없습니다. 파일을 생성한 후 다시 확인해주세요.',
    'copy_command' => '복사',
    'copied' => '복사됨!',

    // Step 2 권한 안내
    'permission_fix_guide' => '아래 명령어를 터미널 또는 SSH에서 실행하여 권한을 부여하세요:',
    'ownership_mismatch_option_group' => '[권장] 그룹 공유 방식 — 웹서버 그룹으로 변경하여 쓰기 권한 부여:',
    'ownership_mismatch_option_chown' => '[대안 1] 소유자 변경 — 소유자를 웹서버 사용자로 변경:',
    'ownership_mismatch_option_777' => '[대안 2] 최후 수단 — 모든 사용자에게 쓰기 허용 (보안 약화, 비권장):',
    'ownership_mismatch_hint' => '※ 현재 소유자: :owner / 웹서버 실행 사용자: :web_user. 위 3가지 중 환경에 맞는 방법을 선택하세요.',
    'permission_fallback_title' => '위 명령어로 해결되지 않는 경우',
    'permission_fallback_detail' => '전용 서버 등 일부 환경에서는 다음 명령어가 필요할 수 있습니다: chmod -R 775 :path',
    'permission_windows_hint' => '※ Windows 환경에서는 관리자 권한으로 명령 프롬프트(cmd)를 열고 실행하세요. 또는 폴더 속성 → 보안 탭에서 권한을 설정할 수 있습니다.',
    'directory_create_guide' => '아래 명령어를 실행하여 누락된 디렉토리를 생성하세요:',

    // 디렉토리명 (Step 2)
    'dir_bootstrap_cache' => '부트스트랩 캐시 디렉토리',
    'dir_vendor' => '패키지 디렉토리',
    'dir_modules' => '모듈 디렉토리',
    'dir_modules_pending' => '모듈 대기 디렉토리',
    'dir_plugins' => '플러그인 디렉토리',
    'dir_plugins_pending' => '플러그인 대기 디렉토리',
    'dir_templates' => '템플릿 디렉토리',
    'dir_templates_pending' => '템플릿 대기 디렉토리',

    // save-extensions.php 다국어 키
    'log_extensions_selected' => '확장 기능 선택 완료: 관리자 템플릿 :admin개, 사용자 템플릿 :user개, 모듈 :modules개, 플러그인 :plugins개, 언어팩 :language_packs개',
    'log_extensions_saved' => '확장 기능 선택이 저장되었습니다.',
    'error_admin_template_required' => '관리자 템플릿을 최소 1개 이상 선택해주세요.',
    'error_invalid_extension_identifier' => '확장 식별자 ":identifier" 가 허용된 형식이 아닙니다 (그룹: :group). 영문/숫자/`._-` 만 사용할 수 있습니다.',

    // 코어 업데이트 설정
    'core_update_settings' => '코어 업데이트 설정 (선택)',
    'core_update_pending_path' => '업데이트 대기 디렉토리 경로',
    'core_update_pending_path_help' => '비워두면 기본값(storage/app/core_pending)을 사용합니다. 외부 경로를 사용하려면 절대 경로 또는 그누보드7 루트 기준 상대 경로를 입력하세요.',

    // 자산 URL 방식 (이슈 #486)
    'asset_url_mode' => '에셋 파일 서빙 방식',
    'asset_url_mode_extension' => '확장자 사용 (권장)',
    'asset_url_mode_extensionless' => '확장자 미사용',
    'asset_url_mode_help' => '서버 설정을 자동으로 감지해 선택합니다. 정적 파일 최적화 규칙(nginx의 location ~* \\.(js|css|json|png|jpg|jpeg|gif|ico|svg|woff2?)$ 블록 등)이 확장자 주소를 가로채면 화면이 뜨지 않는데, 그 경우 확장자 미사용을 선택하세요.',
    'asset_url_mode_detected_extension' => '감지 결과: 확장자를 사용할 수 있는 환경입니다.',
    'asset_url_mode_detected_extensionless' => '감지 결과: 정적 파일 최적화 규칙(nginx location ~* \\.(js|css|json|...)$ 블록 등)이 확장자 주소를 가로채고 있어 확장자 미사용을 선택했습니다.',
    'asset_url_mode_detected_unavailable' => '확장자 있는 주소와 없는 주소 모두 응답하지 않아 서빙 방식을 감지하지 못했습니다. 에셋 방식 자체의 문제라기보다, 앱이 아직 이 주소에서 정상 응답하지 않거나(PHP·라우팅 설정) 프록시·보안 규칙(CSP 등)이 프로브 요청을 막고 있을 가능성이 큽니다. 이 항목은 설치를 막지 않으니 계속 진행할 수 있으며, 설치 후 화면이 비어 보이면 관리자 > 환경설정 > 일반에서 확장자 미사용으로 바꿔 보세요.',
    'asset_url_mode_checking' => '확인 중…',
    'asset_url_mode_unknown' => '확인 불가',
    'core_update_github_url' => 'GitHub 저장소 URL',
    'core_update_github_url_help' => '코어 업데이트를 확인할 GitHub 저장소 URL입니다.',
    'core_update_github_token' => 'GitHub 액세스 토큰',
    'core_update_github_token_help' => '프라이빗 저장소인 경우 GitHub Personal Access Token이 필요합니다. 공개 저장소인 경우 비워둘 수 있습니다.',
    'check_core_pending_path' => '경로 확인',
    'dir_core_pending' => '코어 업데이트 대기 디렉토리',
    'core_pending_will_be_created' => '디렉토리가 존재하지 않습니다. 설치 시 자동으로 생성됩니다.',
    'core_pending_path_ok' => '경로가 유효합니다.',
    'core_pending_info' => '소유자: :owner, 그룹: :group, 퍼미션: :permissions',
    'error_core_pending_not_directory' => '지정한 경로가 디렉토리가 아닙니다.',
    'error_env_value_line_break' => '줄바꿈 문자는 사용할 수 없습니다.',
    'error_env_value_invalid_url' => '올바른 주소 형식이 아닙니다 (:value). http:// 또는 https:// 로 시작하는 주소를 입력하세요.',
    'error_core_pending_not_writable' => '디렉토리(:path)에 쓰기 권한이 없습니다.',
    'error_core_pending_parent_not_writable' => '상위 디렉토리(:path)에 쓰기 권한이 없어 자동 생성이 불가합니다.',
    'error_path_required' => '경로를 입력해주세요.',
    'error_core_pending_path_invalid' => '입력한 경로가 유효한 디렉토리가 아니거나 허용되지 않은 형식입니다.',

    // PHP CLI / Composer 설정
    'php_cli_settings' => 'PHP CLI 설정 (선택)',
    'php_cli_settings_required' => 'PHP CLI 설정 (필수)',
    'php_cli_settings_help' => '호스팅 환경에서 PHP CLI가 시스템 PATH에 없거나 버전이 다른 경우 직접 경로를 지정할 수 있습니다. 일반적인 환경에서는 변경할 필요가 없습니다.',
    'php_cli_settings_help_required' => '기본 php 명령어로 PHP CLI를 실행할 수 없습니다. 올바른 PHP CLI 경로를 지정하고 버전을 확인해주세요.',
    'php_binary_path' => 'PHP CLI 경로',
    'php_binary_path_help' => '예: /usr/local/php82/bin/php (기본값: php)',
    'composer_binary_path' => 'Composer 경로',
    'composer_binary_path_help' => '비워두면 시스템 PATH에서 자동 탐색합니다. .phar 파일 경로도 지원합니다.',
    'verify_version' => '버전 확인',
    'auto_detect_php' => 'PHP 자동 감지',
    'detecting_php' => 'PHP 바이너리를 탐색 중입니다...',
    'detected_php_binaries' => '감지된 PHP 바이너리 (클릭하여 선택):',
    'no_php_detected' => '서버에서 PHP 바이너리를 찾을 수 없습니다.',
    'php_detected_count' => ':count개의 PHP 바이너리를 발견했습니다.',
    'warning_php_binary_not_cli' => 'PHP :version 확인됨 (:path) — CLI 판이 아닙니다(:sapi). 이 경로로는 설치 작업을 실행할 수 없으니 CLI 실행 파일 경로를 지정하세요.',
    'success_php_binary_version' => ':path — PHP :version ✓',
    'error_php_path_empty' => 'PHP 바이너리 경로가 비어있습니다.',
    'error_php_path_not_exists' => '파일이 존재하지 않습니다: :path',
    'error_php_exec_failed' => 'PHP 실행 실패: :path',
    'error_php_binary_path_not_allowed' => '사용할 수 없는 PHP 경로 형식입니다 (:path). 이 서버 안에 있는 실행 파일의 절대경로만 입력하세요 — 옵션(- 로 시작), 상대경로, .., 네트워크 경로(\\\\서버\\공유 또는 //서버/공유), scheme:// 형태는 쓸 수 없습니다.',
    'error_composer_binary_path_not_allowed' => '사용할 수 없는 Composer 경로 형식입니다 (:path). 이 서버 안에 있는 composer 실행 파일의 절대경로만 입력하세요 — 파일 이름은 composer 계열(composer, composer.phar, composer2.phar 등)이어야 하고, 다른 이름의 .phar, 네트워크 경로(\\\\서버\\공유 또는 //서버/공유), scheme:// 형태는 쓸 수 없습니다. 멀티 PHP 환경은 "PHP절대경로 composer절대경로" 형식으로 입력할 수 있습니다.',
    'error_execution_mode_subprocess_unavailable' => '이 서버에서는 「외부 명령 실행」 방식을 쓸 수 없습니다. 필수 함수(exec·proc_open·shell_exec)가 막혀 있어 별도 프로세스를 띄울 수 없습니다. 「자동」 또는 「CLI 없이 설치」를 골라 주세요.',
    'error_execution_mode_inprocess_unavailable' => '이 서버에서는 「CLI 없이 설치」 방식을 쓸 수 없습니다. 그 방식은 동봉된 의존성 묶음(vendor-bundle.zip)을 풀어 쓰는데, 묶음이 없거나 zip 확장이 없어 풀 수 없습니다.',
    'error_php_version_too_low' => ':path — PHP :version (최소 :min 필요)',
    'error_php_version_parse_failed' => 'PHP 버전을 파싱할 수 없습니다.',
    'error_php_cli_not_verified' => 'PHP CLI 경로가 확인되지 않았습니다. "버전 확인" 버튼을 클릭해주세요.',
    'error_composer_not_verified' => 'Composer 실행이 확인되지 않았습니다. "버전 확인" 버튼을 클릭해주세요.',
    'error_composer_path_not_exists' => '파일이 존재하지 않습니다: :path',
    'error_composer_exec_failed' => 'Composer 실행 실패: :path',
    'error_composer_version_parse_failed' => 'Composer 버전을 파싱할 수 없습니다.',
    'error_check_failed' => '확인 요청에 실패했습니다. 네트워크 또는 서버 상태를 확인해 주세요.',
    'session_cookie_blocked_title' => '세션 쿠키 차단 감지',
    'session_cookie_blocked_description' => '브라우저가 인스톨러의 세션 쿠키(PHPSESSID)를 보존하지 않아 설치 도중 진행이 멈출 수 있습니다. 그대로 진행은 가능하지만 권장하지 않으며, 아래 회피 방법 중 하나를 시도해 주세요.',
    'session_cookie_blocked_remedy_1' => '다른 브라우저로 다시 접속해 주세요. 시크릿/프라이빗 창은 보통 쿠키 정책이 더 엄격하므로 일반 창 사용을 권장합니다.',
    'session_cookie_blocked_remedy_2' => '브라우저의 쿠키/사이트 데이터 차단 설정을 해제한 뒤 새로고침 해 주세요.',
    'session_cookie_blocked_remedy_3' => '도메인 대신 IP 또는 localhost 로 접속해 보세요. 예: http://localhost:포트/install/',
    'session_cookie_blocked_technical_detail' => '기술 상세: 인스톨러 세션 쿠키 round-trip 검증이 실패했습니다. (SameSite 정책, 비표준 포트, 브라우저 추적 보호 등의 조합으로 인한 차단 추정)',
    'session_cookie_blocked_dismiss' => '경고 닫기',
    'success_composer_version' => ':path — Composer :version ✓',
    'composer_install_guide_title' => 'Composer 설치 안내',
    'composer_install_guide_message' => 'Composer가 설치되어 있지 않습니다. 아래 방법으로 설치할 수 있습니다:',
    'composer_install_guide_global' => '전역 설치 (권장):',
    'composer_install_guide_local' => '로컬 설치 (프로젝트 디렉토리):',
    'composer_install_guide_hosting' => '호스팅 환경 설치 (위 방법이 안 될 경우):',
    'composer_install_guide_pwd_hint' => '설치 후 아래 명령어로 현재 디렉토리의 절대 경로를 확인하세요:',
    'composer_install_guide_phar_hint' => '위 Composer 경로 입력란에 절대 경로를 포함한 전체 실행 명령어를 입력하세요. 예: /usr/local/php84/bin/php /home/user/g7/composer.phar',
    'composer_install_guide_link' => '자세한 설치 방법은 <a href="https://getcomposer.org/download/" target="_blank" rel="noopener">getcomposer.org</a>를 참조하세요.',
    'composer_checking' => 'Composer를 확인하는 중입니다...',
    'cli_status_checking' => '확인 중...',
    'cli_status_verified' => '✓ 확인됨',
    'cli_status_not_verified' => '✗ 미확인',
    'cli_status_optional_bundled' => '— 선택사항 (번들 모드)',

    // 환경 체크 강화
    'required_functions' => '필수 함수',
    'required_functions_available' => '사용 가능',
    'required_functions_disabled' => ':count개 비활성화',
    'success_required_functions' => '필수 함수(exec, proc_open, shell_exec)가 모두 사용 가능합니다.',

    // 실행 방식 판정 (A-1 — CLI 없는 환경의 모드 분기)
    'execution_mode_title' => '실행 방식',
    'execution_mode_inprocess_notice' => 'CLI 없이 설치합니다',
    'execution_mode_inprocess_detail' => '이 서버는 외부 명령 실행이 제한되어 있습니다.',
    'execution_mode_reason_prefix' => '판정 근거:',
    'execution_mode_reason_functions' => ':functions 차단됨',
    'execution_mode_reason_php_probe' => 'PHP 실행 파일 확인 실패',
    'execution_mode_changes_intro' => '설치는 그대로 진행됩니다. 다만 아래가 달라집니다:',
    'execution_mode_change_vendor' => '의존성은 동봉된 묶음으로 설치합니다',
    'execution_mode_change_cron' => '예약 작업은 주소 호출 방식으로 설정해야 합니다',
    'execution_mode_change_update' => '코어 업데이트는 관리자 화면 버튼으로 합니다',
    'error_no_cli_and_no_bundle' => '이 서버는 외부 명령 실행이 제한되어 있고, 동봉된 의존성 묶음(vendor-bundle.zip)도 찾을 수 없어 설치를 진행할 수 없습니다. 전체 배포판 압축을 그대로 업로드했는지 확인하세요.',
    'php_cli_version' => 'PHP CLI 버전',
    'php_cli_version_check_skipped' => 'exec 함수가 비활성화되어 CLI 버전 확인을 건너뛰었습니다.',
    'php_cli_version_unknown' => 'PHP CLI 버전을 확인할 수 없습니다.',
    'php_cli_version_matched' => '일치 (웹: :web, CLI: :cli)',
    'php_cli_version_mismatch' => '불일치 (웹: :web, CLI: :cli)',
    'php_cli_version_mismatch_hint' => 'Step 3에서 올바른 PHP CLI 경로를 지정해주세요.',
    'success_core_pending_path' => '경로가 유효하고 쓰기 권한이 있습니다.',

    // .env 통합 안내
    'required_files_fix_guide_combined' => '.env 파일 생성 및 권한 설정이 필요합니다. 아래 명령어를 실행해주세요:',

    // 상대경로 병기 안내
    'or_relative_path' => '또는 그누보드7 루트 디렉토리에서:',

    // 실행 모드 (Step 3 선택란 · D33 · W2) — 저장되는 것은 "선택", 기본 auto
    'execution_mode_card_title' => '실행 방식',
    'execution_mode_card_description' => '설치 중 명령(데이터베이스 생성·확장 설치 등)을 어떤 방식으로 실행할지 선택합니다. 설치 후에도 관리자 환경설정에서 바꿀 수 있습니다.',
    'execution_mode_auto_title' => '자동',
    'execution_mode_auto_description' => '서버 환경을 감지해 명령줄이 되면 명령줄로, 막혀 있으면 CLI 없이 실행합니다.',
    'execution_mode_auto_status' => '감지 결과: :mode',
    'execution_mode_php_binary_substituted' => '(입력한 PHP 경로를 쓸 수 없어 기본 php 기준으로 판정했습니다)',
    'vendor_bundle_hint_run_user' => '(실행 사용자: :user)',
    'vendor_bundle_hint_owner_same' => '(실행 사용자: :user, 소유자 동일)',
    'vendor_bundle_hint_owner_differs' => '— 실행 사용자(:user)와 디렉토리 소유자(:owner)가 다릅니다. 설치 파일을 올린 계정과 웹서버 실행 계정이 달라 생기는 문제이며, 요구사항 화면의 권한 안내를 따라 이 디렉토리에 쓰기 권한을 준 뒤 다시 시도해 주세요.',
    'execution_mode_subprocess_title' => '명령줄 실행',
    'execution_mode_subprocess_description' => 'php artisan 명령을 별도 프로세스로 실행합니다 (일반 서버의 기본 방식).',
    'execution_mode_subprocess_status_ok' => '명령줄 실행 가능',
    'execution_mode_subprocess_status_blocked' => '이 서버에서 불가',
    'execution_mode_inprocess_title' => 'CLI 없이 실행',
    'execution_mode_inprocess_description' => '명령을 웹 요청 안에서 직접 실행합니다. 공유 호스팅처럼 외부 명령이 막힌 서버용이며, 의존성은 동봉된 묶음으로 설치합니다.',
    'execution_mode_inprocess_status_ok' => '동봉 묶음(vendor-bundle.zip) 확인',
    'execution_mode_inprocess_status_no_bundle' => 'vendor-bundle.zip 없음 — 사용 불가',
    'execution_mode_badge_default' => '기본',
    'execution_mode_badge_cli' => '명령줄',
    'execution_mode_badge_no_cli' => '공유 호스팅',
    'execution_mode_label_subprocess' => '명령줄 실행',
    'execution_mode_label_inprocess' => 'CLI 없이 실행',
    'execution_mode_source_explicit' => '직접 선택',
    'execution_mode_source_detected' => '자동 감지',
    'execution_mode_detected_subprocess' => '명령줄 실행이 가능한 서버입니다.',
    'execution_mode_detected_inprocess' => '이 서버는 외부 명령 실행이 제한되어 CLI 없이 설치합니다.',
    'execution_mode_vendor_fixed_notice' => '이 서버는 Composer 를 실행할 수 없어 동봉된 묶음으로 설치합니다. 선택을 바꿀 수 없습니다.',
    'vendor_mode_composer_status_inprocess' => 'CLI 없이 설치 — 사용 불가',

    // 설치 진행 방식 — 단계별 실행 (A-2 · W3)
    'installation_mode_stepwise_title' => '단계별 실행',
    'installation_mode_stepwise_desc' => '단계마다 짧은 요청 하나씩 실행합니다. 실행 시간이 제한된 호스팅에 맞고, 새로고침해도 이어서 진행됩니다.',
    'installation_mode_unavailable_inprocess' => 'CLI 없는 환경 — 사용 불가',
    'installation_mode_unavailable_composer' => 'Composer 설치 방식에서는 사용 불가',
    'installation_mode_stepwise_composer_reason' => '의존성을 Composer 로 설치하면 한 단계가 길어져 요청 시간 안에 끝나지 않습니다. 동봉된 묶음을 선택하면 이 방식을 쓸 수 있습니다.',
    'installation_mode_forced_notice' => '이 서버는 외부 명령 실행이 제한되어 단계별 실행으로 설치합니다.',
    'stepwise_resume_notice' => '단계별 실행은 새로고침해도 이어서 진행됩니다.',
    'stepwise_waiting_busy' => '이전 요청의 작업이 끝나기를 기다리는 중입니다...',
    'stepwise_request_retry' => '서버 응답을 받지 못해 다시 시도합니다 (:attempt/:max)',
    'stepwise_step_progress' => ':done / :total 단계',
    'error_stepwise_request_failed' => '서버가 단계 실행 요청에 응답하지 않습니다. 잠시 후 [재시도] 를 누르면 중단된 단계부터 이어집니다. 문제가 반복되면 storage/logs/installation.log 를 확인해주세요.',
    'error_polling_request_failed' => '서버가 설치 상태 조회에 응답하지 않습니다. 잠시 후 페이지를 새로고침해 이어서 진행해 주세요. 문제가 반복되면 storage/logs/installation.log 를 확인해주세요.',
    'error_stepwise_token_invalid' => '단계별 실행 토큰이 유효하지 않습니다. 설치 화면을 새로고침한 뒤 다시 시작해주세요.',
    // 「보관본이 없다」와 「제시한 값이 틀렸다」는 조치가 다르다 — 전자는 새로고침으로 복구되지 않는다 (11차 #A10)
    'error_stepwise_token_missing' => '이 설치를 이어받을 확인 정보가 서버에 남아 있지 않습니다. 설치를 처음부터 다시 시작해주세요.',
    // 설치 마무리 엔드포인트 가드 (11차 #A1)
    'error_finalize_method_not_allowed' => '설치 마무리는 이 방식으로 호출할 수 없습니다.',
    'error_finalize_not_completed' => '아직 설치가 끝나지 않아 마무리할 수 없습니다.',
    // 다른 창이 이미 설치를 진행 중 (11차 #A2·#A4)
    'error_installation_already_running' => '다른 창에서 설치가 이미 진행 중입니다. 그 창에서 이어서 진행해주세요.',
    // 응답을 만들지 못했을 때 (11차 #A9) — 빈 본문을 내보내면 화면이 통신 오류로 잘못 보고한다
    'error_response_encoding_failed' => '서버 응답을 만들지 못했습니다. 설치 화면을 새로고침해주세요.',
    // 작업은 끝났는데 완료 기록이 저장되지 않았을 때 (11차 #A5)
    'error_task_marker_not_persisted' => ':task 의 완료 기록을 저장하지 못했습니다. 디스크 여유 공간과 storage 폴더 쓰기 권한을 확인해주세요.',
    // 중단 사유 — 서버가 구분해 기록한 값을 중단 화면이 그대로 번역해 보여 준다 (11차 #A12)
    'abort_reason_user' => '설치를 직접 중단하셨습니다.',
    'abort_reason_connection_lost' => '설치 화면과의 연결이 끊겨 중단되었습니다.',
    'abort_reason_label' => '중단 사유',
    'error_stepwise_mode_mismatch' => '이 설치는 단계별 실행으로 시작되지 않았습니다.',
    'error_stepwise_not_running' => '진행 중인 설치가 없습니다. (현재 상태: :status)',
    'error_stepwise_unexpected_response' => '서버가 예상하지 못한 응답을 보냈습니다. 설치 화면을 새로고침하면 중단된 단계부터 이어집니다.',
    'error_monitor_script_not_loaded' => '설치 진행 화면을 불러오지 못했습니다. 페이지를 새로고침해주세요.',
    'log_installation_mode_forced_stepwise' => '외부 명령 실행이 제한된 서버이므로 단계별 실행으로 진행합니다.',
    'log_installation_mode_downgraded_sse' => '의존성을 Composer 로 설치하면 한 단계가 요청 시간 안에 끝나지 않으므로 실시간 스트리밍으로 진행합니다.',
    'log_execution_mode_resolved' => '실행 방식: :mode (:source)',

    // 실패 단계 건너뛰기 (W3 [건너뛰고 계속])
    'skip_and_continue' => '건너뛰고 계속',
    'skip_task_confirm' => '이 확장의 설치를 건너뛰고 다음 단계로 계속할까요? 설치가 끝난 뒤 관리자 화면에서 다시 설치할 수 있습니다.',
    'skip_task_done' => '해당 단계를 건너뛰었습니다.',
    'skip_task_not_failed' => '건너뛸 실패 단계가 없습니다.',
    'skip_task_not_allowed' => '이 단계는 필수라 건너뛸 수 없습니다.',
    'log_task_best_effort_skipped' => ':task — 앞선 실행에서 실패했지만 설치에 필수가 아니라 지나갑니다.',
    'log_dependency_skipped_after_install' => ':dependent 은(는) 건너뛴 :skipped 에 의존합니다. 이미 설치되어 되돌릴 수 없으니 설치 후 확인하세요.',
    'log_task_skipped_by_user' => ':task — 운영자가 건너뛰었습니다.',
    // 실패 화면의 「처음부터 다시」 — 설치 상태를 비우고 설정 입력(3단계)으로 돌아간다.
    // 라벨이 「되돌리기」였을 때는 이미 만들어진 테이블·설치된 확장까지 되돌린다고 읽혔는데
    // 실제 동작은 화면만 되돌리고 DB 는 그대로 둔다(state_reset_db_notice 가 그 사실을 말한다).
    // 영어 판(Start over)과 뜻을 맞춘다 — 라벨이 없는 기능을 약속하면 안 된다 (11차 #B11).
    'failure_go_back' => '처음부터 다시',

    // 진행 방식 불일치 — 단계별 실행으로 확정된 설치는 스트리밍 워커를 쓰지 않는다 (R1)
    'error_sse_mode_mismatch' => '이 설치는 단계별 실행으로 진행 중이라 실시간 스트리밍 방식으로는 이어갈 수 없습니다.',
    // 스트리밍 워커의 진입 전제 (13차 보안#5) — 형제 입구(run-task)와 같은 규칙
    'error_sse_not_running' => '진행 중인 설치가 없습니다. (현재 상태: :status)',

    // 상태 변경 요청의 출처 확인 (13차 보안#4)
    'error_cross_origin_request' => '다른 사이트에서 보낸 요청은 처리하지 않습니다. 설치 화면에서 다시 시도해주세요.',

    // 무인증 확인 요청의 호출 횟수 상한 (13차 보안#6·보안#7)
    'error_probe_rate_limited' => '확인 요청이 너무 잦습니다. 잠시 후 다시 시도해주세요.',

    // 데이터베이스 연결 실패 사유 (원문 대신 조치가 갈리는 만큼만 분류 — 13차 보안#6)
    'error_db_failure_credentials' => '사용자명 또는 비밀번호가 올바르지 않습니다',
    'error_db_failure_unknown_database' => '해당 이름의 데이터베이스를 찾을 수 없습니다',
    'error_db_failure_unreachable' => '데이터베이스 서버에 연결할 수 없습니다 (주소·포트·방화벽을 확인해주세요)',
    'error_db_failure_denied' => '이 계정에는 해당 데이터베이스 접근 권한이 없습니다',
    'error_db_failure_generic' => '연결에 실패했습니다 (자세한 내용은 설치 로그를 확인해주세요)',

    // 실행 예산 확인 (권장 사항 — 설치를 차단하지 않는다, 13차 환경#8)
    'php_limits' => 'PHP 실행 예산',
    'php_limits_unknown' => 'PHP 실행 예산(메모리·시간)을 확인할 수 없습니다.',
    'php_limits_detail' => '메모리 상한 :memory / 실행 시간 상한 :time초 (권장 메모리 :recommended 이상)',

    // 웹 PHP 와 CLI PHP 의 설정 차이 (13차 환경#7)
    'php_cli_extensions_missing' => 'CLI PHP 에 다음 확장이 없습니다: :extensions — 웹과 다른 php.ini 를 쓰고 있습니다.',

    // 운영자가 직접 지정한 실행 경로가 CLI 가 아님 (13차 보안#7)
    'error_php_binary_not_cli_rejected' => ':path 은(는) CLI 가 아니라 :sapi 용 실행 파일입니다. 설치 작업을 실행할 수 없으므로 CLI 경로를 지정해주세요.',

    // 동봉 의존성 묶음 무결성 검증 (vendor-bundle.zip)
    'log_vendor_bundle_integrity_failed' => '동봉 묶음 무결성 검증 실패: :errors',
    'error_vendor_bundle_integrity_failed' => 'vendor-bundle.zip 무결성 검증에 실패했습니다: :errors',

    // 인스톨러 러너 (artisan-executor)
    'error_subprocess_unavailable' => '이 서버에서는 외부 명령을 실행할 수 없습니다 (exec/proc_open 차단).',
    'error_subprocess_signal_terminated' => '명령이 시그널 :signal 로 강제 종료되었습니다. 메모리 한도(OOM) 또는 실행 시간 제한을 확인해 주세요.',
    'error_inprocess_vendor_missing' => '의존성(vendor/)이 아직 설치되지 않아 명령을 실행할 수 없습니다.',
    'error_inprocess_boot_failed' => '애플리케이션을 시작하지 못했습니다: :error',
];
