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
    'storage_permission_guide' => 'Storage 폴더에 <strong>:permissions</strong> 권한을 부여해주세요.',
    'storage_permission_guide_detail' => '아래 명령어를 실행하거나 FTP를 통해 권한을 변경하세요:',
    'storage_permission_command_linux' => 'chmod -R :permissions :path',
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
    'https_enabled' => 'HTTPS 활성화',
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
    'or_above' => '이상',
    'fix_permission' => '쓰기 권한 부족',
    'including_subdirectories' => '하위 포함',
    'and_more' => '외 :count개',
    'error_php_version' => 'PHP 버전',
    'error_missing_extensions' => '필수 PHP 확장 미설치',
    'error_disk_space' => '디스크 공간',
    'error_directory_permissions' => '디렉토리 쓰기 권한 부족',
    'error_required_files' => '필수 파일 누락',
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
    'db_prefix_hint' => '모든 테이블명 앞에 붙는 접두사입니다. (예: g7_users, g7_posts)',
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
    'recommendation_env_permission' => '.env 파일 권한을 600 또는 644로 설정하세요',
    'recommendation_https' => '프로덕션 환경에서는 반드시 HTTPS를 사용하세요',

    // Step 5: 완료
    'installation_info' => '설치 정보',
    'installation_complete_message' => '그누보드7이 성공적으로 설치되었습니다!',
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
    'error_db_connection_failed' => '데이터베이스 연결에 실패했습니다.',
    'error_db_privileges_insufficient' => '데이터베이스에 필요한 권한이 부족합니다.',
    'error_db_not_tested' => '데이터베이스 연결 테스트를 먼저 수행해주세요.',
    'error_write_db_not_tested' => 'Write DB 연결 테스트를 먼저 수행해주세요.',
    'error_read_db_not_tested' => 'Read DB 연결 테스트를 먼저 수행해주세요.',

    // 에러 메시지 - 검증 (데이터베이스) (상세)
    'error_db_privileges_insufficient_detail' => ':type 데이터베이스에 필요한 권한이 부족합니다: :missing',
    'error_db_connection_failed_detail' => ':type 데이터베이스 연결 실패: :error',
    'error_db_test_failed' => '데이터베이스 테스트 중 오류 발생',

    // 에러 메시지 - 검증 (관리자 계정)
    'error_admin_email_invalid' => '유효한 이메일 주소를 입력해주세요.',
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
    'error_directory_not_writable_detail' => '일부 디렉토리에 쓰기 권한이 없습니다. 권한을 :permissions로 설정해주세요.',
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
    'task_cache_clear' => '임시 파일 정리',
    'task_create_settings_json' => '설정 파일 생성',
    'task_complete_flag' => '설치 완료 처리',
    'task_unknown' => '알 수 없는 작업',

    // 작업 그룹명
    'task_group_environment' => '환경 설정',
    'task_group_database' => '데이터베이스',
    'task_group_admin_templates' => '관리자 템플릿',
    'task_group_modules' => '모듈',
    'task_group_plugins' => '플러그인',
    'task_group_user_templates' => '사용자 템플릿',
    'task_group_finalize' => '마무리',

    // 그룹 상태 레이블
    'status_pending' => '대기',
    'status_in_progress' => '진행 중',
    'status_completed' => '완료',
    'status_failed' => '실패',
    'status_aborted' => '중단',

    // 에러 메시지 - Worker (Composer)
    'error_composer_not_installed' => 'Composer가 설치되어 있지 않습니다.',
    'error_composer_install_failed' => 'Composer 의존성 설치에 실패했습니다',

    // 로그 메시지 - Worker (Composer)
    'log_composer_check_success' => 'Composer 확인 완료',
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

    // 에러 메시지 - Worker (Cache)
    'error_cache_clear_failed' => '캐시 클리어에 실패했습니다',

    // 로그 메시지 - Worker (Cache)
    'log_cache_clear_success' => '캐시 클리어 완료',

    // 에러 메시지 - Worker (Settings JSON)
    'error_settings_json_failed' => '설정 파일 생성에 실패했습니다',

    // 로그 메시지 - Worker (Settings JSON)
    'log_creating_settings' => '설정 JSON 파일 생성 중...',
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
    'abort_by_user' => "[중단] 사용자가 설치를 중단했습니다. (현재 작업: :task)",
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
    'error_unexpected_exception' => '예기치 않은 오류가 발생했습니다',

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
    'confirm_go_to_settings' => "설정 페이지로 이동하시겠습니까?",
    'confirm_go_to_settings_simple' => "설정 페이지로 이동하시겠습니까?\n\n설치 상태가 초기화되며, 모든 작업이 처음부터 다시 실행됩니다.\n\n⚠️ 데이터베이스에 생성된 테이블은 자동으로 삭제되지 않습니다.\n필요 시 phpMyAdmin 등을 통해 수동으로 정리해주세요.",
    'confirm_go_to_settings_title' => '설정 페이지로 이동',
    'confirm_go_to_settings_desc' => '설치 상태가 초기화되며, 모든 작업이 처음부터 다시 실행됩니다.\n\n⚠️ 데이터베이스에 생성된 테이블은 자동으로 삭제되지 않습니다. 필요 시 phpMyAdmin 등을 통해 수동으로 정리해주세요.',
    'confirm_go_to_settings_btn' => '이동',
    'reset_db_checkbox_label' => '데이터베이스 초기화 (생성된 테이블 삭제)',
    'error_state_reset_failed' => '상태 초기화에 실패했습니다.\n\n:message\n\n페이지를 새로고침 후 다시 시도해주세요.',
    'confirm_force_go_to_settings' => '상태 초기화 중 오류가 발생했습니다.\n\n그래도 설정 페이지로 이동하시겠습니까?',
    'view_error_details' => '오류 상세 보기',
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
// install-worker-sse.php 최상단에 추가
ini_set(\'output_buffering\', \'off\');
ini_set(\'zlib.output_compression\', \'off\');
@apache_setenv(\'no-gzip\', 1);

[공유 호스팅]
호스팅 제공업체에 SSE(Server-Sent Events) 지원 여부를 문의해주세요.
방화벽이나 프록시에서 long-lived HTTP 연결을 차단하고 있을 수 있습니다.',

    // 다크모드
    'toggle_theme' => '테마 전환',
    'dark_mode' => '다크 모드',
    'light_mode' => '라이트 모드',

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
    'optional' => '선택',
    'no_extensions_found' => '사용 가능한 확장이 없습니다.',
    'no_user_templates' => '사용 가능한 사용자 템플릿이 없습니다.',
    'no_modules' => '사용 가능한 모듈이 없습니다.',
    'no_plugins' => '사용 가능한 플러그인이 없습니다.',
    'extension_load_failed' => '확장 기능 목록을 불러오는데 실패했습니다.',
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
    'error_worker_exception' => '설치 워커 SSE 예외 발생',

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
    'rollback_seed_no_config' => 'DB 설정 정보가 없어 시드 롤백을 건너뜁니다.',
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
    'manual_cmd_settings_json' => 'settings.json은 자동으로 생성됩니다. 설치를 다시 시도하세요.',

    // state-management.php 다국어 키
    'state_reset_requested' => '설정 페이지로 이동을 요청했습니다.',
    'state_reset_db_notice' => 'DB는 자동으로 초기화되지 않습니다. 필요 시 수동으로 정리해주세요.',
    'state_save_failed' => '상태 파일 저장에 실패했습니다.',
    'state_reset_completed' => '설치 상태가 초기화되었습니다. (Step :step(으)로 이동)',
    'abort_api_requested' => '[중단 API] abort 요청됨 - 사용자가 설치 중단 요청',
    'abort_api_current_status' => '[중단 API] 현재 installation_status: :status',
    'abort_api_current_task' => '[중단 API] 현재 current_task: :task',
    'abort_api_completed_count' => '[중단 API] 완료된 작업 수: :count',
    'abort_api_already_completed' => '설치가 이미 완료되어 중단할 수 없습니다.',
    'abort_api_already_aborted' => '설치가 이미 중단되었습니다.',
    'abort_api_not_running' => '중단할 설치가 없습니다.',
    'abort_user_requested' => '사용자가 설치 중단을 요청했습니다.',
    'abort_api_status_change' => '[중단 API] installation_status를 "aborted"로 변경합니다.',
    'abort_api_save_result' => '[중단 API] state.json 저장 결과: :result',
    'abort_api_verify_status' => '[중단 API] 저장 후 확인 - installation_status: :status',
    'api_method_not_allowed' => '허용되지 않는 메서드입니다.',
    'error_state_management' => '상태 관리 오류',
    'error_log_prefix' => '[오류] :error',

    // functions.php 다국어 키
    'error_db_name_username_required' => '데이터베이스 이름과 사용자명은 필수입니다.',
    'error_db_connection_failed' => '데이터베이스 연결 실패: :error',
    'error_privilege_check_failed' => '권한 검증 중 오류 발생',

    // 필수 파일 생성 안내 (Step 5)
    'file_setup_title' => '필수 파일 생성',
    'file_setup_description' => '설치를 진행하려면 아래 파일이 프로젝트 루트에 필요합니다.',
    'file_setup_guide' => '터미널에서 아래 명령어를 프로젝트 루트 디렉토리에서 실행하세요:',
    'file_check_button' => '파일 확인',
    'file_checking' => '확인 중...',
    'files_not_found_yet' => '필수 파일을 아직 찾을 수 없습니다. 파일을 생성한 후 다시 확인해주세요.',
    'copy_command' => '복사',
    'copied' => '복사됨!',

    // Step 2 권한 안내
    'permission_fix_guide' => '아래 명령어를 터미널에서 실행하여 권한을 수정하세요:',
    'ownership_fix_guide' => '소유자와 그룹을 웹 서버 사용자로 변경하세요:',
    'ownership_fix_hint' => '※ www-data는 웹 서버 사용자/그룹입니다. 사용 환경에 따라 nginx, apache 등으로 변경하세요.',
    'ownership_detected_hint' => '※ 현재 웹 서버 사용자: :user (자동 감지)',
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
    'log_extensions_selected' => '확장 기능 선택 완료: 관리자 템플릿 :admin개, 사용자 템플릿 :user개, 모듈 :modules개, 플러그인 :plugins개',
    'log_extensions_saved' => '확장 기능 선택이 저장되었습니다.',
    'error_admin_template_required' => '관리자 템플릿을 최소 1개 이상 선택해주세요.',

    // 코어 업데이트 설정
    'core_update_settings' => '코어 업데이트 설정 (선택)',
    'core_update_pending_path' => '업데이트 대기 디렉토리 경로',
    'core_update_pending_path_help' => '비워두면 기본값(storage/app/core_pending)을 사용합니다. 외부 경로를 사용하려면 절대 경로 또는 그누보드7 루트 기준 상대 경로를 입력하세요.',
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
    'error_core_pending_not_writable' => '디렉토리(:path)에 쓰기 권한이 없습니다.',
    'error_core_pending_parent_not_writable' => '상위 디렉토리(:path)에 쓰기 권한이 없어 자동 생성이 불가합니다.',
    'error_path_required' => '경로를 입력해주세요.',

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
    'success_php_binary_version' => ':path — PHP :version ✓',
    'error_php_path_empty' => 'PHP 바이너리 경로가 비어있습니다.',
    'error_php_path_not_exists' => '파일이 존재하지 않습니다: :path',
    'error_php_exec_failed' => 'PHP 실행 실패: :path',
    'error_php_version_too_low' => ':path — PHP :version (최소 :min 필요)',
    'error_php_version_parse_failed' => 'PHP 버전을 파싱할 수 없습니다.',
    'error_php_cli_not_verified' => 'PHP CLI 경로가 확인되지 않았습니다. "버전 확인" 버튼을 클릭해주세요.',
    'error_composer_not_verified' => 'Composer 실행이 확인되지 않았습니다. "버전 확인" 버튼을 클릭해주세요.',
    'error_composer_path_not_exists' => '파일이 존재하지 않습니다: :path',
    'error_composer_exec_failed' => 'Composer 실행 실패: :path',
    'error_composer_version_parse_failed' => 'Composer 버전을 파싱할 수 없습니다.',
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

    // 환경 체크 강화
    'required_functions' => '필수 함수',
    'required_functions_available' => '사용 가능',
    'required_functions_disabled' => ':count개 비활성화',
    'success_required_functions' => '필수 함수(exec, proc_open, shell_exec)가 모두 사용 가능합니다.',
    'error_disabled_functions' => '다음 함수가 비활성화되어 있어 설치를 진행할 수 없습니다: :functions. 호스팅 관리자에게 활성화를 요청하세요.',
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
];
?>
