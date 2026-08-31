<?php

// changelog:allow 무CLI 실행 모드 축의 신규 키 — 사용자 노출 화면 미구현 단계라 공개 CHANGELOG 는 기능 완성 릴리즈 섹션에 일괄 기재 (#609)

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
    'static_cache_republished' => '초기 화면 정적 파일을 다시 만들었습니다.',
    'static_cache_republish_failed' => '초기 화면 정적 파일을 다시 만들지 못했습니다. 사이트는 정상 동작합니다.',

    // 앱 키 관련 메시지
    'invalid_password' => '비밀번호가 일치하지 않습니다.',
    'app_key_env_write_failed' => '.env 파일을 쓰지 못해 애플리케이션 키를 재생성하지 않았습니다. 디스크 여유 공간과 파일 권한을 확인해 주세요.',
    'password_required' => '비밀번호를 입력해주세요.',
    'app_key_regenerated' => '어플리케이션 키가 성공적으로 재생성되었습니다.',
    'app_key_regenerate_failed' => '어플리케이션 키 재생성에 실패했습니다.',
    'app_key_regenerate_warning' => '키를 변경하면 모든 세션이 무효화됩니다. 계속하시겠습니까?',

    // 시스템 정보 관련
    'seconds' => '초',

    // 실행 방식·예약 작업 화면 (W5 — 관리자 '시스템' 탭)
    'execution' => [
        'fetch_success' => '실행 방식 정보를 조회했습니다.',
        'fetch_failed' => '실행 방식 정보 조회에 실패했습니다.',
        'detect_success' => '실행 환경을 다시 감지했습니다.',
        'detect_failed' => '실행 환경 감지에 실패했습니다.',
    ],

    // 실행 모드 (웹 요청 안의 커맨드 실행 방식)
    'execution_mode' => [
        'auto' => '자동으로 판단',
        'subprocess' => '항상 CLI 사용',
        'inprocess' => '항상 CLI 없이',
    ],

    // PHP 바이너리 probe 사유 (App\Support\PhpBinaryResolver · ExecutionEnvironment 가
    // `message_key` 로 실어 보낸다 — 접두사 없는 키라 소비처가 이 네임스페이스를 붙인다:
    // `__('settings.'.$result['message_key'], $result['message_params'])`).
    'php_probe_ok' => 'PHP :version 확인 (최소 요구 :min_version)',
    'php_probe_version_low' => 'PHP :version 은 최소 요구 버전 :min_version 보다 낮습니다.',
    'php_probe_empty_path' => 'PHP 실행 파일 경로가 비어 있습니다.',
    'php_probe_file_missing' => 'PHP 실행 파일을 찾을 수 없습니다: :path',
    'php_probe_exec_failed' => 'PHP 실행 파일을 실행하지 못했습니다: :path',
    'php_probe_unparsable' => 'PHP 버전을 읽지 못했습니다.',
    'php_probe_exec_unavailable' => '이 서버에서는 외부 명령 실행이 막혀 있어 PHP 실행 파일을 확인하지 못했습니다.',
    'php_probe_failed' => 'PHP 실행 파일 확인에 실패했습니다.',
    'php_probe_functions_blocked' => '이 서버에서는 별도 프로세스 실행에 필요한 기능이 막혀 있어 확인을 건너뛰었습니다.',
    'php_probe_auto_detected' => 'PHP 실행 파일을 자동으로 찾았습니다: :path',

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

        // 루트 진입·보호 파일 통보 (D25)
        'root_index_replaced' => '루트 진입 파일(:path)이 코어 판본이 아니어서 코어 shim 으로 교체했습니다 — 교체 직전 내용을 :copy 에 사본으로 남겼습니다. 이 사이트가 프로젝트 루트에 자체 index.php 를 두고 있었다면 그 파일에서 내용을 되찾으세요 (업데이트 백업은 성공 시 삭제됩니다).',
        'root_index_replaced_not_preserved' => '루트 진입 파일(:path)이 코어 판본이 아니어서 코어 shim 으로 교체했습니다 — 교체 직전 내용을 사본으로 남기지 못했습니다(storage/app/core-update 쓰기 실패). 이 사이트가 프로젝트 루트에 자체 index.php 를 두고 있었다면 그 내용은 복구할 수 없습니다.',
        'root_index_unreadable' => '루트 진입 파일(:path)을 읽지 못해 코어 판본인지 확인하지 못한 채 교체했습니다 — 읽지 못했으므로 사본도 남기지 못했습니다. 그 파일에 자체 내용이 있었다면 복구할 수 없습니다.',
        'already_running' => '다른 코어 업데이트가 이미 실행 중입니다 — 그 실행이 끝난 뒤 다시 시도하세요. 두 실행이 겹치면 되돌리기용 백업이 서로 덮어써져 복구할 수 없습니다.',
        'run_lock_unavailable' => '동시 실행 보호를 켤 수 없어 그대로 진행합니다 (:reason) — 이 상태에서는 업데이트를 **한 번에 하나만** 실행하세요. 두 실행이 겹치면 되돌리기용 백업이 서로 덮어써져 복구할 수 없습니다.',
        'run_lock_unknown_reason' => '잠금 파일을 열 수 없습니다 (:path) — 권한·디스크 여유·open_basedir 를 확인하세요',
        'root_file_copy_failed' => '루트 진입·보호 파일(:path)을 갱신하지 못했습니다 — 그 파일이 구버전으로 남았습니다. 파일과 파일이 든 디렉토리 양쪽의 쓰기 권한을 확인한 뒤 다시 시도하세요. 나머지 항목은 정상적으로 갱신되었습니다.',
        'root_htaccess_preserved' => '루트 보호 규칙 파일(:path)에서 코어 관리 블록 마커(`# BEGIN G7` / `# END G7`, 줄 시작)를 찾지 못해 갱신하지 않았습니다 — 마커가 지워졌거나 형태가 바뀐(들여쓰기·다른 문자열이 덧붙음) 상태이며, 코어 보호 규칙이 적용되지 않은 채입니다. 일부러 지운 것이 아니라면 릴리즈 판본의 `.htaccess` 블록을 문서 루트 파일에 그대로 두고 다시 시도하세요.',
        'root_htaccess_created' => '루트 보호 규칙 파일(:path)을 새로 만들었습니다 — 프로젝트 루트가 웹에 열려 있던 배치라면 이제 보호됩니다. 다만 이 사이트를 `/public/…` 주소로 접근해 왔다면 그 주소는 이제 정본 주소로 이동(301)하며, 문서 루트를 바꿀 수 없는 서버라면 그대로 두는 것이 맞습니다.',
        'root_htaccess_write_failed' => '루트 보호 규칙 파일을 쓰지 못했습니다 (:path) — 보호 규칙이 구버전으로 남았습니다. 그 파일과 파일이 든 디렉토리 양쪽의 쓰기 권한을 확인한 뒤 다시 시도하세요 (교체는 대상 파일의 디렉토리에 임시 파일을 만든 뒤 바꿔 넣으므로 그 디렉토리에도 쓰기 권한이 필요합니다). 그 파일이 심볼릭 링크라면 링크가 아니라 링크 대상 파일과 그 대상이 든 디렉토리의 권한입니다.',
        'deploy_fragment_preserved' => '서버 설정 조각(:path)은 운영자가 수정한 판본이라 그대로 두고, 새 판본을 :dist 로 옆에 두었습니다. 두 파일의 차이를 확인해 직접 합친 뒤(nginx 는 이 파일을 include 로 읽으므로 합친 뒤 reload) .dist 파일은 지우세요 — 합치지 않으면 새 판본의 보호 규칙이 이 서버에 적용되지 않습니다.',
        'deploy_fragment_dist_write_failed' => '서버 설정 조각(:path)은 운영자가 수정한 판본이라 그대로 두었지만, 새 판본을 :dist 로 옆에 쓰지 못했습니다(디렉토리 쓰기 권한 확인). 릴리즈 판본의 같은 파일과 직접 비교해 합치세요.',
        'root_htaccess_read_failed' => '루트 보호 규칙 파일을 읽지 못했습니다 (:path) — 갱신을 건너뛰었고 보호 규칙이 구버전으로 남았습니다. 파일 소유자와 읽기 권한을 확인한 뒤 다시 시도하세요.',
        'root_htaccess_duplicate_block' => '루트 보호 규칙 파일(:path)에 코어 관리 블록이 두 벌 이상 있습니다 — 자동 갱신을 건너뛰었습니다. 중복 블록을 하나만 남기고 지운 뒤 다시 시도하세요.',
        'root_htaccess_broken_block' => '루트 보호 규칙 파일(:path)의 코어 관리 블록이 손상되어 있습니다 (`# BEGIN G7` 과 `# END G7` 중 하나만 있거나 순서가 뒤바뀜) — 자동 갱신을 건너뛰었고, 그 파일은 지금 보호 규칙이 온전하지 않은 상태입니다(전송 중단이나 편집 중 잘림일 수 있습니다). 릴리즈 판본의 `.htaccess` 블록으로 바꿔 넣은 뒤 다시 시도하세요.',
        'root_htaccess_source_invalid' => '릴리즈 판본의 루트 보호 규칙 파일에서 코어 관리 블록을 찾지 못했습니다 — 갱신을 건너뛰었습니다.',
        'root_htaccess_destination_invalid' => '루트 보호 규칙 경로(:path)가 파일이 아닙니다 — 갱신을 건너뛰었습니다.',
        'root_htaccess_splice_invalid' => '루트 보호 규칙 파일(:path)의 코어 관리 블록을 교체한 결과가 온전하지 않아 갱신을 건너뛰었습니다 — 블록 밖 내용을 확인한 뒤 다시 시도하세요.',
        'root_web_config_preserved' => 'IIS 루트 보호 규칙 파일(:path)에서 코어 관리 블록 마커(`BEGIN G7` / `END G7` 을 담은 XML 주석 줄, 줄 시작)를 찾지 못해 갱신하지 않았습니다 — 마커가 지워졌거나 형태가 바뀐(들여쓰기·다른 문자열이 덧붙음) 상태이며, 코어 보호 규칙이 적용되지 않은 채입니다. 일부러 지운 것이 아니라면 릴리즈 판본 `web.config` 의 블록을 그 파일에 그대로 두고 다시 시도하세요.',
        'root_web_config_created' => 'IIS 루트 보호 규칙 파일(:path)을 새로 만들었습니다 — 프로젝트 루트가 웹에 열려 있던 배치라면 이제 보호됩니다(URL Rewrite 모듈이 있어야 읽힙니다). 이 사이트를 `/public/…` 주소로 접근해 왔다면 그 주소는 이제 정본 주소로 이동(301)합니다.',
        'root_web_config_write_failed' => 'IIS 루트 보호 규칙 파일을 쓰지 못했습니다 (:path) — 보호 규칙이 구버전으로 남았습니다. 그 파일과 파일이 든 디렉토리 양쪽의 쓰기 권한을 확인한 뒤 다시 시도하세요 (교체는 대상 파일의 디렉토리에 임시 파일을 만든 뒤 바꿔 넣으므로 그 디렉토리에도 쓰기 권한이 필요합니다). 그 파일이 심볼릭 링크라면 링크 대상 파일과 그 대상이 든 디렉토리의 권한입니다.',
        'root_web_config_read_failed' => 'IIS 루트 보호 규칙 파일을 읽지 못했습니다 (:path) — 갱신을 건너뛰었고 보호 규칙이 구버전으로 남았습니다. 파일 소유자와 읽기 권한을 확인한 뒤 다시 시도하세요.',
        'root_web_config_duplicate_block' => 'IIS 루트 보호 규칙 파일(:path)에 코어 관리 블록이 두 벌 이상 있습니다 — 자동 갱신을 건너뛰었습니다. 중복 블록을 하나만 남기고 지운 뒤 다시 시도하세요.',
        'root_web_config_broken_block' => 'IIS 루트 보호 규칙 파일(:path)의 코어 관리 블록이 손상되어 있습니다 (`BEGIN G7` 과 `END G7` 마커 주석 중 하나만 있거나 순서가 뒤바뀜) — 자동 갱신을 건너뛰었고, 그 파일은 지금 보호 규칙이 온전하지 않은 상태입니다(IIS 는 web.config 파싱에 실패하면 사이트 전체를 500 으로 만듭니다). 릴리즈 판본 `web.config` 의 블록으로 바꿔 넣은 뒤 다시 시도하세요.',
        'root_web_config_source_invalid' => '릴리즈 판본의 IIS 루트 보호 규칙 파일에서 코어 관리 블록을 찾지 못했습니다 — 갱신을 건너뛰었습니다.',
        'root_web_config_destination_invalid' => 'IIS 루트 보호 규칙 경로(:path)가 파일이 아닙니다 — 갱신을 건너뛰었습니다.',
        'root_web_config_splice_invalid' => 'IIS 루트 보호 규칙 파일(:path)의 코어 관리 블록을 교체한 결과가 적형(well-formed) XML 이 아니어서 갱신을 건너뛰었습니다 — 블록 밖에 닫히지 않은 태그가 있을 수 있습니다(IIS 는 web.config 파싱에 실패하면 사이트 전체를 500 으로 만듭니다). 파일을 확인한 뒤 다시 시도하세요.',
        'root_web_config_unrecognized_failure' => 'IIS 루트 보호 규칙 파일(:path) 갱신이 알 수 없는 사유(:result)로 끝났습니다 — 보호 규칙이 갱신되지 않았을 수 있으니 파일 내용을 직접 확인하세요.',
        'deploy_fragment_dist_conflict' => '서버 설정 조각(:path)은 운영자가 수정한 판본이라 그대로 두었습니다. 옆에 있던 :dist 는 지금 릴리즈 판본과 내용이 달라(합치던 중일 수 있어) 덮어쓰지 않고, 새 판본을 :dist_new 로 두었습니다. 새 판본(.dist.new)을 기준으로 합친 뒤(nginx 는 이 파일을 include 로 읽으므로 합친 뒤 reload) .dist 와 .dist.new 를 지우세요.',
        'deploy_fragment_ledger_seeded' => '서버 설정 조각(deploy/) :count개의 갱신 기준선을 기록했습니다.',
        'deploy_fragment_ledger_seed_skipped' => '서버 설정 조각(deploy/)의 갱신 기준선은 이미 있거나 기록할 조각이 없어 그대로 둡니다.',
        'deploy_fragment_ledger_seed_premise' => '이 기준선은 **지금 디스크에 있는 내용**을 코어가 배포한 판본으로 간주해 기록한 것입니다. 설치 직후가 아닌 시점(특히 되돌리기 직후)에 실행했다면, 직접 수정한 서버 설정 조각이 「수정하지 않은 것」으로 기록되어 다음 업데이트가 경고 없이 덮어씁니다. 수정한 조각이 있다면 지금 사본을 따로 보관하세요.',
        'deploy_fragment_ledger_write_failed' => '서버 설정 조각의 갱신 기록(:ledger)을 쓰지 못했습니다 — 다음 코어 업데이트가 deploy/ 의 모든 조각을 「수정본」으로 보고 새 판본을 .dist 로 옆에 둡니다. storage/app 디렉토리의 쓰기 권한을 확인하세요.',
        'storage_link_backup_inside_docroot' => '공개 파일 백업을 문서 루트 밖으로 옮기지 못했습니다 (:path) — 그 사본은 지금 웹에서 열립니다. 내용을 확인한 뒤 옮기거나 삭제하세요.',
        'storage_link_backup_needs_review' => '공개 파일 전량 사본이 :path 에 남았습니다 — 문서 루트가 프로젝트 루트인 배치(설치 안내의 「문서 루트를 바꿀 수 없는 호스팅」)에서는 루트 보호 파일이 정상일 때만 가려집니다. 내용을 확인한 뒤 옮기거나 삭제하세요.',
        'storage_link_backup_accumulated' => '손상된 공개 스토리지 백업이 :count개 쌓여 있습니다 (:path). 내용을 확인한 뒤 정리하세요 — 데이터 손실을 피하려 코어는 자동 삭제하지 않으며, `php artisan storage:prune-leftovers` 가 보존일이 지난 것만 최신 1개를 남기고 회수합니다.',
        'root_htaccess_unrecognized_failure' => '루트 보호 규칙 파일(:path) 갱신이 알 수 없는 사유(:result)로 끝났습니다 — 보호 규칙이 갱신되지 않았을 수 있으니 파일 내용을 직접 확인하세요.',
        'rollback_root_protection_missing' => '되돌린 뒤 루트 진입·보호 파일이 없습니다 (:paths) — 문서 루트가 public/ 인 표준 배치이거나 되돌린 판본에 원래 없던 파일이라면 정상입니다. 문서 루트를 프로젝트 루트로 쓰고 있다면 설정 파일이 웹에 노출된 상태이므로 즉시 그 파일을 복구하거나 문서 루트를 public/ 으로 바꾸세요.',
        'rollback_docroot_residue' => '되돌리기가 문서 루트 안의 공개 파일 사본을 되살렸습니다 (:paths) — 그 사본은 웹에서 그대로 열립니다. 내용을 확인한 뒤 문서 루트 밖으로 옮기거나 삭제하세요.',

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

];
