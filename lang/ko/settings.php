<?php

return [
    // 설정 관련 메시지
    'fetch_success' => '설정을 성공적으로 가져왔습니다.',
    'fetch_failed' => '설정을 가져오는데 실패했습니다.',
    'save_success' => '설정이 성공적으로 저장되었습니다.',
    'save_failed' => '설정 저장에 실패했습니다.',
    'update_success' => '설정이 성공적으로 업데이트되었습니다.',
    'update_failed' => '설정 업데이트에 실패했습니다.',
    'delete_success' => '설정이 성공적으로 삭제되었습니다.',
    'delete_failed' => '설정 삭제에 실패했습니다.',
    'save_error' => '설정 저장 중 오류가 발생했습니다.',
    'update_error' => '설정 업데이트 중 오류가 발생했습니다.',
    'cache_clear_success' => '캐시가 성공적으로 정리되었습니다.',
    'cache_clear_failed' => '캐시 정리에 실패했습니다.',
    'cache_clear_error' => '캐시 정리 중 오류가 발생했습니다.',
    'optimize_success' => '시스템이 성공적으로 최적화되었습니다.',
    'optimize_failed' => '시스템 최적화에 실패했습니다.',
    'optimize_error' => '시스템 최적화 중 오류가 발생했습니다.',
    'backup_success' => '데이터베이스 백업이 성공적으로 시작되었습니다.',
    'backup_failed' => '데이터베이스 백업에 실패했습니다.',
    'backup_error' => '데이터베이스 백업 중 오류가 발생했습니다.',
    'save_individual_failed' => '설정 저장에 실패했습니다: :error',

    // 앱 키 관련 메시지
    'invalid_password' => '비밀번호가 일치하지 않습니다.',
    'password_required' => '비밀번호를 입력해주세요.',
    'app_key_regenerated' => '어플리케이션 키가 성공적으로 재생성되었습니다.',
    'app_key_regenerate_failed' => '어플리케이션 키 재생성에 실패했습니다.',
    'app_key_regenerate_warning' => '키를 변경하면 모든 세션이 무효화됩니다. 계속하시겠습니까?',

    // 시스템 정보 관련
    'seconds' => '초',

    // 드라이버 라벨 (DriverRegistryService 가 활성 언어팩 별로 조회)
    'drivers' => [
        'storage' => [
            'local' => '로컬',
            's3' => 'Amazon S3',
        ],
        'cache' => [
            'file' => '파일',
            'redis' => 'Redis',
        ],
        'session' => [
            'file' => '파일',
            'database' => '데이터베이스',
            'redis' => 'Redis',
        ],
        'queue' => [
            'sync' => '동기',
            'database' => '데이터베이스',
            'redis' => 'Redis',
        ],
        'log' => [
            'single' => '단일 파일',
            'daily' => '일별 파일',
        ],
        'websocket' => [
            'reverb' => 'Laravel Reverb',
        ],
        'mail' => [
            'smtp' => 'SMTP',
            'mailgun' => 'Mailgun',
            'ses' => 'SES (Amazon)',
        ],
    ],

    // 드라이버 연결 테스트 메시지
    'driver_test_success' => '모든 드라이버 연결 테스트가 성공했습니다.',
    'driver_test_partial' => '일부 드라이버 연결 테스트가 실패했습니다.',
    'driver_test_error' => '드라이버 연결 테스트 중 오류가 발생했습니다.',
    'unknown_driver' => '알 수 없는 드라이버입니다.',

    // S3 테스트 메시지
    's3_test_success' => 'S3 버킷에 성공적으로 연결되었습니다.',
    's3_test_failed' => 'S3 버킷 연결에 실패했습니다.',
    's3_missing_config' => 'S3 설정이 누락되었습니다. (버킷, 리전, 액세스 키, 시크릿 키)',
    's3_sdk_missing' => 'AWS SDK가 설치되어 있지 않습니다.',
    's3_bucket_not_found' => 'S3 버킷을 찾을 수 없습니다.',
    's3_access_denied' => 'S3 버킷에 대한 접근이 거부되었습니다.',
    's3_invalid_credentials' => 'S3 인증 정보가 올바르지 않습니다.',

    // Redis 테스트 메시지
    'redis_test_success' => 'Redis 서버에 성공적으로 연결되었습니다.',
    'redis_test_failed' => 'Redis 서버 연결에 실패했습니다.',
    'redis_extension_missing' => 'Redis PHP 확장이 설치되어 있지 않습니다.',
    'redis_connection_failed' => 'Redis 서버에 연결할 수 없습니다.',
    'redis_auth_failed' => 'Redis 인증에 실패했습니다.',
    'redis_ping_failed' => 'Redis PING 응답이 없습니다.',

    // Memcached 테스트 메시지
    'memcached_test_success' => 'Memcached 서버에 성공적으로 연결되었습니다.',
    'memcached_test_failed' => 'Memcached 서버 연결에 실패했습니다.',
    'memcached_extension_missing' => 'Memcached PHP 확장이 설치되어 있지 않습니다.',
    'memcached_connection_failed' => 'Memcached 서버에 연결할 수 없습니다.',

    // Websocket 테스트 메시지
    'websocket_test_success' => 'Websocket 서버에 성공적으로 연결되었습니다.',
    'websocket_test_failed' => 'Websocket 서버 연결에 실패했습니다.',
    'websocket_connection_refused' => 'Websocket 서버에 연결할 수 없습니다. 서버가 실행 중인지 확인해주세요.',

    // 테스트 메일 관련 메시지
    'invalid_email' => '유효하지 않은 이메일 주소입니다.',
    'test_mail_subject' => ':app_name 테스트 메일',
    'test_mail_body' => '이것은 그누보드7에서 발송한 테스트 메일입니다. 이 메일을 받으셨다면 메일 설정이 올바르게 구성되어 있습니다.',
    'test_mail_sent' => '테스트 메일이 성공적으로 발송되었습니다.',
    'test_mail_failed' => '테스트 메일 발송에 실패했습니다.',
    'test_mail_error' => '테스트 메일 발송 중 오류가 발생했습니다.',

    // 코어 업데이트 관련 메시지
    'core_update' => [
        'check_success' => '업데이트 확인이 완료되었습니다.',
        'check_failed' => '업데이트 확인에 실패했습니다.',
        'update_available' => '새로운 업데이트가 있습니다.',
        'no_update' => '현재 최신 버전입니다.',
        'maintenance_mode_active' => '시스템 점검 중입니다. 잠시 후 다시 시도해주세요.',
        'invalid_github_url' => 'GitHub 저장소 URL이 유효하지 않습니다.',
        'download_failed' => ':version 버전 다운로드에 실패했습니다.',
        'zip_extract_failed' => 'ZIP 파일 압축 해제에 실패했습니다.',
        'invalid_package' => '다운로드된 패키지가 유효하지 않습니다.',
        'invalid_package_not_g7' => '지정된 디렉토리가 그누보드7 프로젝트가 아닙니다. config/app.php 파일과 version 설정이 필요합니다.',
        'composer_failed' => 'composer install 실행에 실패했습니다.',
        'pending_path_create_failed' => '_pending 디렉토리(:path) 생성에 실패했습니다: :error',
        'pending_path_not_writable' => '_pending 디렉토리(:path)에 쓰기 권한이 없습니다.',
        'downloading' => '업데이트 다운로드 중...',
        'extracting' => '압축 해제 중...',
        'validating' => '패키지 검증 중...',
        'running_composer' => 'composer install 실행 중...',
        'step_check' => '업데이트 확인 중...',
        'step_validate_pending' => '환경 검증 중...',
        'step_maintenance' => '유지보수 모드 활성화 중...',
        'step_download' => '다운로드 중...',
        'step_backup' => '백업 생성 중...',
        'step_apply' => '파일 적용 중...',
        'step_composer' => 'composer install 중...',
        'step_migration' => '마이그레이션 실행 중...',
        'step_upgrade' => '업그레이드 스텝 실행 중...',
        'step_composer_prod' => '운영 디렉토리 composer install 중...',
        'step_cleanup' => '정리 중...',

        // GitHub API 에러 메시지
        'github_url_not_configured' => 'GitHub 저장소 URL이 설정되지 않았습니다.',
        'github_api_failed' => 'GitHub API에 연결할 수 없습니다.',
        'github_token_required' => '프라이빗 저장소입니다. GitHub 액세스 토큰을 설정해주세요.',
        'github_token_invalid' => 'GitHub 액세스 토큰이 유효하지 않거나 권한이 부족합니다. (HTTP :status — :message)',
        'github_repo_not_found' => 'GitHub 저장소를 찾을 수 없습니다. (HTTP :status — :message)',
        'github_repo_not_found_no_token' => 'GitHub 저장소를 찾을 수 없습니다. 프라이빗 저장소인 경우 액세스 토큰을 설정해주세요. (HTTP :status — :message)',
        'github_api_error' => 'GitHub API 오류가 발생했습니다. (HTTP :status — :message)',
        'no_releases_found' => 'GitHub 저장소에 릴리스가 없습니다. (HTTP :status — :message)',

        // 로그 메시지
        'log_api_call_failed' => '코어 업데이트: GitHub API 호출 실패',
        'log_auth_failed' => '코어 업데이트: GitHub 인증 실패',
        'log_not_found' => '코어 업데이트: GitHub 저장소/릴리스 미발견',
        'log_unexpected_status' => '코어 업데이트: 예상하지 못한 HTTP 상태 코드',
        'log_version_check_error' => '코어 업데이트: 최신 버전 확인 중 오류',

        'unknown_error' => '알 수 없는 오류가 발생했습니다.',

        // 시스템 요구사항
        'system_requirements_failed' => '시스템 요구사항이 충족되지 않습니다:',
        'no_extract_method_available' => '사용 가능한 아카이브 추출 방법이 없습니다. PHP zip 확장(ZipArchive) 또는 unzip 명령어(Linux) 중 하나가 필요합니다. 또는 --source 옵션으로 수동 업데이트할 수 있습니다.',
        'manual_update_guide' => '수동 업데이트: GitHub에서 릴리스 ZIP을 다운로드하여 압축 해제 후, php artisan core:update --source=/압축해제/경로 명령어를 실행하세요.',

        // 추출 폴백 체인
        'archive_url_not_found' => ':type 아카이브 URL을 찾을 수 없습니다. 다음 방법을 시도합니다.',
        'extracting_with' => ':method 방식으로 압축 해제 중...',
        'extract_empty' => '압축 해제된 디렉토리가 비어 있습니다.',
        'extract_fallback' => ':method 방식 실패: :error — 다음 방법을 시도합니다.',
        'all_extract_methods_failed' => '모든 아카이브 추출 방법이 실패했습니다. --source 옵션으로 수동 업데이트를 시도하세요.',
        'unzip_command_failed' => 'unzip 명령어 실행 실패 (종료 코드: :code). :output',
        'zip_file_not_found' => '지정된 ZIP 파일을 찾을 수 없습니다: :path',
        'source_vendor_missing' => '소스 디렉토리에 vendor가 없습니다. composer install이 실행되지 않았을 수 있습니다.',
        'composer_failed_with_output' => 'composer install 실행에 실패했습니다.:output',
    ],
];
