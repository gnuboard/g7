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
    'database_backup_unavailable' => '데이터베이스 백업 기능은 아직 제공하지 않습니다. 설정 백업은 설정 백업 기능을 이용해 주세요.',
    'backup_path_required' => '백업 경로를 입력해주세요.',
    'restore_success' => '설정이 성공적으로 복원되었습니다.',
    'restore_failed' => '설정 복원에 실패했습니다.',
    'restore_error' => '설정 복원 중 오류가 발생했습니다.',
    'save_individual_failed' => '설정 저장에 실패했습니다: :error',
    'static_cache_status_loaded' => '초기 화면 정적 파일 상태를 불러왔습니다.',
    'queue_status_loaded' => '큐 워커 상태를 불러왔습니다.',
    'static_cache_republished' => '초기 화면 정적 파일을 다시 만들었습니다.',
    'static_cache_republish_failed' => '초기 화면 정적 파일을 다시 만들지 못했습니다. 사이트는 정상 동작합니다.',

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
        'public_asset' => [
            'none' => '사용 안 함 (스트리밍)',
            'public' => 'Public 디스크',
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
        'search' => [
            'mysql-fulltext' => 'MySQL 전문검색',
        ],
    ],

    // 드라이버 연결 테스트 메시지
    'driver_test_success' => '모든 드라이버 연결 테스트가 성공했습니다.',
    'driver_test_partial' => '일부 드라이버 연결 테스트가 실패했습니다.',
    'driver_test_error' => '드라이버 연결 테스트 중 오류가 발생했습니다.',
    'unknown_driver' => '알 수 없는 드라이버입니다.',

    // 아웃바운드 프록시 연결 테스트 메시지
    'outbound_proxy_test_success' => '프록시 연결에 성공했습니다. 외부 서비스에는 이 IP 로 보입니다.',
    'outbound_proxy_test_failed' => '프록시로 연결하지 못했습니다. 주소와 프록시 서버 상태를 확인해주세요.',
    'outbound_proxy_test_invalid_url' => '프록시 주소 형식이 올바르지 않습니다.',
    'outbound_proxy_test_no_lookup_url' => '출발지 IP 조회 대상이 설정되어 있지 않아 확인할 수 없습니다.',

    // S3 테스트 메시지
    's3_test_success' => 'S3 버킷에 성공적으로 연결되었습니다.',
    's3_test_failed' => 'S3 버킷 연결에 실패했습니다.',
    's3_missing_config' => 'S3 설정이 누락되었습니다. (버킷, 리전, 액세스 키, 시크릿 키)',
    's3_sdk_missing' => 'AWS SDK가 설치되어 있지 않습니다.',
    's3_adapter_missing' => 'S3 스토리지 어댑터(league/flysystem-aws-s3-v3)가 설치되어 있지 않습니다.',
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
    'websocket_server_test_failed' => 'Websocket 서버(백엔드 발송용) endpoint 연결에 실패했습니다. 클라이언트 endpoint 는 정상입니다.',
    'driver_unusable_s3_adapter' => 'S3 스토리지 어댑터(league/flysystem-aws-s3-v3)가 설치되어 있지 않습니다.',
    'driver_unusable_redis_client' => 'phpredis 확장과 predis 라이브러리가 모두 없습니다.',
    'driver_unusable_memcached_extension' => 'memcached PHP 확장이 설치되어 있지 않습니다.',
    'websocket_invalid_host' => 'Websocket 호스트 설정이 올바르지 않습니다. 호스트에는 주소만 입력하고 계정 정보(@)나 http/https 이외의 프로토콜은 사용할 수 없습니다.',
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

        // 파일 적용 방식 요약 (Step 7)
        'apply_mode_incremental' => '파일 적용: 코어가 실제 변경한 파일만 적용했습니다 (신규 :added개, 변경 :changed개). 그 외 파일은 현재 상태 그대로 보존되었습니다.',
        'apply_mode_incremental_prune_hint' => '신 버전에서 제거된 파일을 함께 정리하려면 같은 업데이트를 `--prune` 옵션으로 다시 실행하세요.',
        'apply_mode_prune' => '파일 적용: 전체 덮어쓰기 + 제거된 파일 정리를 수행했습니다 (--prune).',
        'apply_mode_fallback' => '파일 적용: 백업이 없어 증분 적용을 건너뛰고 전체 덮어쓰기를 수행했습니다. 사용자 커스텀 파일 보존을 원하면 다음부터는 백업을 유지한 채 업데이트하세요.',

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
        'no_extract_method_available' => '압축을 풀 수 없습니다. PHP zip 확장(ZipArchive)이 필요합니다. 또는 --source 옵션으로 수동 업데이트할 수 있습니다.',
        'manual_update_guide' => '수동 업데이트: GitHub에서 릴리스 ZIP을 다운로드하여 압축 해제 후, php artisan core:update --source=/압축해제/경로 명령어를 실행하세요.',

        // 추출 폴백 체인
        'archive_url_not_found' => ':type 아카이브 URL을 찾을 수 없습니다. 다음 방법을 시도합니다.',
        'extracting_with' => ':method 방식으로 압축 해제 중...',
        'extract_empty' => '압축 해제된 디렉토리가 비어 있습니다.',
        'extract_fallback' => ':method 방식 실패: :error — 다음 방법을 시도합니다.',
        'all_extract_methods_failed' => '모든 아카이브 추출 방법이 실패했습니다. --source 옵션으로 수동 업데이트를 시도하세요.',
        'release_not_found' => 'GitHub 에서 코어 :version 배포본을 찾지 못했습니다 — 그 버전 태그가 아직 없거나 GitHub 에 연결하지 못했습니다. --source 또는 --zip 옵션으로 배포본을 직접 지정하세요.',
        'extension_release_not_found' => 'GitHub 저장소 :repository 에서 :version 배포본을 찾지 못했습니다 — 그 버전 태그가 아직 없거나 GitHub 에 연결하지 못했습니다.',
        'zip_file_not_found' => '지정된 ZIP 파일을 찾을 수 없습니다: :path',
        'source_vendor_missing' => '소스 디렉토리에 vendor가 없습니다. composer install이 실행되지 않았을 수 있습니다.',
        'composer_failed_with_output' => 'composer install 실행에 실패했습니다.:output',
        // `core:update` 명령 콘솔 출력
        'console' => [
            'source_zip_conflict' => '--source 와 --zip 은 동시에 지정할 수 없습니다. 하나만 선택하세요.',
            'local_source_conflict' => '--local 은 --source / --zip 과 동시에 사용할 수 없습니다.',
            'zip_not_found' => '지정된 ZIP 파일이 존재하지 않습니다: :path',
            'source_not_found' => '지정된 소스 디렉토리가 존재하지 않습니다: :path',
            'check_failed' => '업데이트 확인 실패: :error',
            'already_latest' => '현재 최신 버전입니다: :version',
            'current_version' => '현재 버전: :version',
            'target_version_pending' => '업데이트 버전: (ZIP 추출 후 판별)',
            'target_version' => '업데이트 버전: :version',
            'confirm_update' => '코어를 업데이트하시겠습니까?',
            'pending_invalid' => '_pending 디렉토리 문제:',
            'pending_path' => '경로: :path',
            'pending_owner' => '소유자: :owner, 그룹: :group, 퍼미션: :permissions',
            'progress' => [
                'validate_source' => '소스 디렉토리 검증 중...',
                'extract_zip' => 'ZIP 파일 추출 중...',
                'copy_local' => '로컬 소스 복제 중...',
                'composer_skipped' => 'Composer 의존성 변경 없음 — 스킵',
                'manifest' => '신규 파일 manifest 생성 중...',
                'vendor_copy_skipped' => 'vendor 복사 스킵',
            ],
            'dev_packages_kept' => '운영 vendor 에 개발용 패키지 :count개 감지 — composer.json/lock 변경이 없어 vendor 를 재설치하지 않으므로 그대로 남습니다. 업데이트 후 \'composer install --no-dev --optimize-autoloader\' 실행을 권장합니다.',
            'dev_packages_replaced' => '운영 vendor 에 개발용 패키지 :count개 감지 — 이번 업데이트가 --no-dev vendor 로 교체합니다.',
            'vendor_install_failed' => 'Vendor 설치 실패: :error',
            'upgrade_steps_title' => '── 업그레이드 스텝 실행 ──',
            'upgrade_step_running' => 'upgrade step 실행: :version',
            'updated' => '그누보드7 코어가 :version 버전으로 업데이트되었습니다!',
            'restoring_ownership' => '일괄 업데이트로 생성된 파일의 소유권을 복원하는 중...',
            'handoff_cleanup_failed' => '핸드오프 cleanup 중 오류 발생 — 수동 점검 필요: :error',
            'handoff_incomplete' => '업그레이드 파일 반영은 완료되었으나, 일부 업그레이드 스텝이 현재 프로세스에서 실행될 수 없어 중단되었습니다.',
            'handoff_after_version' => '완료 스텝 지점: :version',
            'handoff_file_version' => '파일·설정 버전: :version (이미 반영됨)',
            'handoff_reason' => '사유: :reason',
            'handoff_resume_intro' => '나머지 스텝을 적용하려면 아래 명령을 실행하세요 (재다운로드 없이 스텝만 실행):',
            'handoff_resume_warning' => '위 명령을 실행하지 않으면 버전 표시는 최신이지만 일부 스텝이 미실행 상태로 남습니다.',
            'backup_kept' => '백업이 유지되었습니다: :path',
            'restoring_backup' => '백업에서 복원 중...',
            'restored' => '백업에서 복원되었습니다.',
            'restored_partial' => '백업에서 부분 복원되었습니다.',
            'restore_failed_targets' => '복원 실패 항목: :targets',
            'restore_failed' => '백업 복원 실패: :error',
            'cache_clear_failed' => '캐시 정리 실패 — 부팅 후 \'php artisan optimize:clear\' 수동 실행 필요: :error',
            'update_failed' => '코어 업데이트 실패: :error',
            'failure_report' => '실패 리포트: :path',
            'maintenance_disabled' => '복원 완료: 유지보수 모드가 해제되었습니다.',
            'maintenance_disable_failed' => '유지보수 모드 해제 실패. 수동으로 해제하세요: php artisan up',
            'maintenance_kept' => '유지보수 모드가 유지됩니다.',
            'manual_recovery_required' => '수동 복구가 필요합니다:',
            'manual_restore_backup' => '백업에서 수동 복원: :command',
            'manual_reinstall_composer' => 'Composer 재설치: composer install --no-dev --optimize-autoloader',
            'manual_disable_maintenance' => '유지보수 해제: php artisan up',
            'run_previous_version' => '이전 버전으로 사이트를 운영하려면: php artisan up',
            'admin_access' => '관리자 접근: :secret',
            'manifest_clear_failed' => 'spawn 직전 패키지 매니페스트 삭제 실패 — 자식이 이전 설치본의 provider 목록으로 부팅할 수 있습니다 (권한·소유권 확인): :paths',
            'hook_cache_failed' => '훅 캐시를 새 프로세스로 다시 굽지 못했습니다 — 업데이트 뒤 `php artisan hooks:cache` 를 실행하세요: :output',
            'spawn' => [
                'proc_open_disabled' => 'proc_open 비활성',
                'proc_open_failed' => 'proc_open 자원 생성 실패',
                'steps_signal_missing' => 'spawn 자식이 [STEPS_EXECUTED] 신호 미발행 — 이전 버전 자식 또는 silent skip 의심',
                'zero_steps' => 'spawn 자식 exit=0 이지만 step 0건 실행 — 의도된 동작이 아님 (from=:from to=:to)',
                'zero_steps_discovered' => 'spawn 자식 exit=0 이지만 step 0건 실행 — 의도된 동작이 아님 (from=:from to=:to, 발견 :count건)',
                'abnormal_exit' => 'spawn 비정상 종료 (exit=:code)',
                'abort_reason' => 'spawn 자식 실패 — :reason. fail-fast 모드. 수동 재개로 진행하세요.',
            ],
            'owner_mismatch' => '실행 사용자와 코어 파일 소유자가 다릅니다.',
            'owner_mismatch_current' => '실행 사용자: :user (uid=:uid)',
            'owner_mismatch_owner' => '코어 소유자: :user (uid=:uid)',
            'owner_mismatch_hint' => '권한 거부가 발생할 수 있습니다. 일반적으로 코어 파일 소유자로 SSH 로그인 후 실행해야 합니다. vendor/ 가 다른 사용자 소유인 경우 \'chown -R $(whoami) vendor/\' 후 재시도하세요.',
            'resume_root_known_warning' => '현재 sudo(root) 로 실행 중입니다. 위 명령을 root 로 그대로 실행하면 업그레이드 스텝이 생성하는 파일이 root 소유가 되어 이후 웹서버(:user) 요청이 쓰기 실패할 수 있습니다. 반드시 웹서버 계정(:user)으로 재실행하세요.',
            'resume_command_unknown_user' => 'sudo -u <웹서버계정> :command',
            'resume_root_unknown_warning' => '현재 sudo(root) 로 실행 중입니다. 웹서버 계정을 자동으로 식별하지 못했습니다.',
            'resume_root_unknown_hint' => '위 명령을 root 로 그대로 실행하면 업그레이드 스텝이 생성하는 파일이 root 소유가 되어 이후 웹서버(php-fpm) 요청이 쓰기 실패할 수 있습니다. 웹서버 계정(예: www-data, nginx, apache)을 확인해 그 계정으로 재실행하세요.',
            'permission_failed' => '권한 정상화 실패 — 일부 경로의 소유권/그룹 쓰기 권한을 복원하지 못했습니다.',
            'permission_kinds' => [
                'chown' => '소유권',
                'group_write' => '그룹 쓰기',
            ],
            'permission_failed_item' => ':target [:kind]: :count 건 실패',
            'permission_more_paths' => '… (총 :count건, 상위 5건만 표시)',
            'permission_recovery_example' => '복구 예시:',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 공식 번들 소스 배치 (core:update --bundled-sources, core:bundled-sources) (#146)
    |--------------------------------------------------------------------------
    */
    'bundled_sources' => [
        'types' => [
            'module' => '모듈',
            'plugin' => '플러그인',
            'template' => '템플릿',
            'language_pack' => '언어팩',
        ],
        'sample_suffix' => ' (샘플)',
        'item_line' => '  [:type] :identifier — :name (:version):sample',

        // core:update 질문
        'prompt_intro' => '이 프로젝트에 없는 공식 번들 확장 :count개가 새 배포본에 있습니다 (설치된 확장과 그 의존 확장은 자동으로 갱신됩니다).',
        'maintenance_warning' => '사이트가 유지보수 중입니다 — 빠르게 답해 주세요.',
        'choice_question' => '어떻게 할까요?',
        'choice_none' => '배치하지 않음 (기본)',
        'choice_all' => '모두 배치',
        'choice_pick' => '골라서 배치',
        'pick_question' => '배치할 번들을 고르세요 (쉼표로 여러 개)',
        'dependency_added' => '의존성으로 함께 배치: :items',
        'noninteractive_default' => '비대화형 실행이고 --bundled-sources 를 지정하지 않아, 이 프로젝트에 없는 공식 번들 :count개를 배치하지 않습니다.',
        'option_applied' => '--bundled-sources=:value 적용: 미배치 :unplaced개, 선택 배치 :selected개',
        'inventory_deferred' => 'ZIP 목록을 읽을 수 없어 공식 번들 배치 여부는 압축을 푼 뒤에 묻습니다.',

        // core:update 완료 요약
        'summary_title' => '── 공식 번들 처리 결과 ──',
        'summary_installed' => '1) 기존 설치 확장 업데이트: 성공 :success, 실패 :failed, 건너뜀 :skipped',
        'summary_installed_none' => '1) 기존 설치 확장 업데이트: 새 버전 없음',
        'summary_dependency' => '2) 의존성으로 배치한 번들: :count개',
        'summary_dependency_item' => '     :label ← :required_by',
        'summary_selected' => '3) 운영자 선택으로 배치한 번들: :count개',
        'summary_selected_item' => '     :label',
        'summary_unplaced' => '4) 배치하지 않은 번들: :count개',
        'summary_unplaced_item' => '     :label',
        'summary_place_hint' => '   필요할 때 배치하세요: php artisan core:bundled-sources place <식별자>',
        'summary_on_disk' => '디스크에 있던 미설치 번들 :count개는 새 버전으로 갱신했습니다.',
        'summary_selection_aborted' => '공식 번들 선택이 잘못되어 업데이트를 중단했습니다. 파일은 바뀌지 않았고 유지보수 모드를 해제했습니다.',

        // core:bundled-sources
        'source_resolving_github' => 'GitHub 에서 코어 :version 배포본을 내려받아 번들 목록을 읽습니다...',
        'source_used' => '배포본: :source (코어 :version)',
        'source_failed' => '배포본을 읽지 못했습니다: :error',
        'record_fallback' => '배포본을 읽지 못해 기록 파일(:recorded_at)로 표시합니다.',
        'disk_db_only' => '배포본도 기록 파일도 없어 디스크와 설치 상태만 표시합니다. 배포본 대조는 --source 또는 --zip 으로 지정하세요.',
        'record_unplaced_count' => '기록 파일 기준 미배치 :count개 — 디스크에 있는 번들은 모두 위 표에 있습니다.',
        'list_headers' => [
            'type' => '유형',
            'identifier' => '식별자',
            'version' => '버전',
            'disk' => '디스크',
            'installed' => '설치',
            'required' => '의존 대상',
        ],
        'yes' => '예',
        'no' => '아니오',
        'list_record_updated' => '기록 파일을 갱신했습니다 (미배치 :count개).',
        'place_done' => '배치함: :label',
        'place_already' => '이미 디스크에 있어 건너뜀: :label',
        'place_summary' => ':count개를 배치했습니다. 설치는 관리자 화면 또는 php artisan {module|plugin|template}:install 로 하세요.',
        'prune_preview_title' => '정리 대상 미리보기 (삭제하지 않음 — 실제로 지우려면 --force):',
        'prune_none' => '정리할 번들이 없습니다.',
        'prune_removed' => '삭제함: :label',
        'prune_modified_kept' => '수정됨, 보존: :label (배포본과 다른 파일 :count개)',
        'prune_modified_file' => '     :file',
        'prune_modified_hint' => '수정된 폴더도 지우려면 --include-modified 를 함께 지정하세요.',
        'prune_extra_only_kept' => '배포본에 없는 파일만 다름, 보존: :label (배포본에 없는 파일 :count개)',
        'prune_extra_only_hint' => '배포본에 없는 파일만 다른 폴더는 이전 판에서 남은 파일일 수 있습니다(기본 업데이트는 새 판에서 지워진 파일을 지우지 않습니다). 확인한 뒤 지우려면 --include-modified 를 함께 지정하세요.',
        'prune_protected_dependency' => '의존 대상이라 보존: :label (:required_by)',
        'prune_no_release_for_modified' => '배포본이 없어 수정 여부를 확인할 수 없습니다 — 수정 여부와 무관하게 지우려면 --include-modified 를 지정하세요.',
        'prune_summary' => ':count개를 삭제했습니다.',
        'unknown_action' => '알 수 없는 동작입니다: :action (list|place|prune)',

        'errors' => [
            'unknown_type' => '알 수 없는 번들 유형입니다: :type (module|plugin|template|language_pack)',
            'not_in_release' => '배포본에 없는 공식 번들입니다: :identifier',
            'ambiguous' => '식별자 :identifier 가 여러 유형에 있습니다. 유형을 붙여 지정하세요: :candidates',
            'version_mismatch' => '배포본 코어 버전(:source)이 현재 코어 버전(:current)과 다릅니다. 같은 버전의 배포본을 지정하세요.',
            'identifiers_required' => '배치할 번들 식별자를 하나 이상 지정하세요.',
            'release_required' => '배포본을 읽지 못해 배치할 수 없습니다. --source 또는 --zip 으로 지정하세요.',
            'release_required_prune' => '배포본을 읽지 못해 정리 대상을 판정할 수 없습니다. --source 또는 --zip 으로 지정하세요.',
            'not_unplaced_target' => '정리 대상이 아닙니다(설치됨 또는 배포본에 없음): :identifier',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 신뢰 프록시 진단 (#124)
    |--------------------------------------------------------------------------
    |
    | 값 편집은 .env 전용이다 — 화면에는 읽기 전용 진단만 노출한다.
    |
    */

    'trusted_proxy' => [
        'alert_title' => '신뢰 프록시가 설정되지 않았습니다',
        'alert_message' => '프록시 헤더(:headers)를 받고 있으나 신뢰 프록시가 설정되지 않아, 모든 방문자가 같은 주소(:ip)로 기록되고 있습니다. .env 에 TRUSTED_PROXIES 를 지정하세요. 상세: https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md',
    ],
    // `settings:*` 명령 출력
    'console' => [
        'install' => [
            'defaults_not_found' => '기본 설정 파일을 찾을 수 없습니다: :path',
            'defaults_check_hint' => ':path 파일이 존재하는지 확인하세요.',
            'defaults_parse_failed' => '기본 설정 파일 JSON 파싱 실패: :error',
            'defaults_section_missing' => '기본 설정 파일에 defaults 섹션이 없습니다.',
            'start' => '환경설정 설치를 시작합니다...',
            'no_defaults' => '기본값이 없습니다. 건너뜁니다.',
            'already_exists' => '이미 존재합니다. 건너뜁니다. (--force 또는 --merge 옵션 사용)',
            'merged' => '병합 완료 (:count개 새 키 추가)',
            'merge_failed' => '병합 실패',
            'action_overwritten' => '덮어씀',
            'action_installed' => '설치됨',
            'install_failed' => '설치 실패',
            'done' => '환경설정 설치 완료!',
            'summary_installed' => '설치됨: :count개',
            'summary_merged' => '병합됨: :count개',
            'summary_skipped' => '건너뜀: :count개',
        ],
        'migrate_to_json' => [
            'start' => '설정 마이그레이션을 시작합니다...',
            'table_missing' => ':table 테이블이 존재하지 않습니다. 기본값으로 초기화합니다.',
            'defaults_created' => '기본 설정 파일이 생성되었습니다.',
            'confirm_overwrite' => '기존 JSON 설정 파일이 존재합니다. 덮어쓰시겠습니까?',
            'cancelled' => '마이그레이션이 취소되었습니다.',
            'nothing_to_migrate' => '마이그레이션할 설정이 없습니다. 기본값으로 초기화합니다.',
            'found' => '총 :count개의 설정을 발견했습니다.',
            'category_saved' => ':category 카테고리 저장 완료',
            'done' => '마이그레이션이 완료되었습니다!',
            'location' => '설정 파일 위치: :path',
        ],
    ],
];
