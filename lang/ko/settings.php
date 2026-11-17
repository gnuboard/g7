<?php

// changelog:allow 무CLI 실행 모드 축의 신규 키 — 사용자 노출 화면 미구현 단계라 공개 CHANGELOG 는 기능 완성 릴리즈 섹션에 일괄 기재

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
    'errors' => [
        'zip_extension_required' => '설정 백업·복원에는 서버의 PHP :extension 확장이 필요합니다. 서버 관리자에게 설치를 요청하세요.',
    ],
    'restore_error' => '설정 복원 중 오류가 발생했습니다.',
    'save_individual_failed' => '설정 저장에 실패했습니다: :error',
    'static_cache_status_loaded' => '초기 화면 정적 파일 상태를 불러왔습니다.',
    'queue_status_loaded' => '큐 워커 상태를 불러왔습니다.',
    'static_cache_republished' => '초기 화면 정적 파일을 다시 만들었습니다.',
    'static_cache_republish_failed' => '초기 화면 정적 파일을 다시 만들지 못했습니다. 사이트는 정상 동작합니다.',
    'trusted_proxy_probe_no_headers' => '앞단 프록시가 감지되지 않아 이 값으로 달라지는 것이 없습니다. 프록시를 쓰지 않는 구성이라면 「신뢰하지 않음」이 올바른 값입니다.',
    'trusted_proxy_probe_resolves' => '이 값이면 방문자 IP 가 프록시 너머의 실제 주소로 해석됩니다.',
    'trusted_proxy_probe_unchanged' => '이 값으로도 방문자 IP 가 그대로입니다 — 목록에 앞단 프록시 주소가 빠져 있을 수 있습니다.',

    // 앱 키 관련 메시지
    'invalid_password' => '비밀번호가 일치하지 않습니다.',
    'app_key_env_write_failed' => '.env 파일을 쓰지 못해 애플리케이션 키를 재생성하지 않았습니다. 디스크 여유 공간과 파일 권한을 확인해 주세요.',
    'password_required' => '비밀번호를 입력해주세요.',
    'app_key_regenerated' => '어플리케이션 키가 성공적으로 재생성되었습니다.',
    'app_key_regenerate_failed' => '어플리케이션 키 재생성에 실패했습니다.',
    'app_key_regenerate_warning' => '키를 변경하면 모든 세션이 무효화됩니다. 계속하시겠습니까?',

    // 시스템 정보 관련
    'seconds' => '초',

    // 실행 방식·예약 작업 화면 (관리자 '시스템' 탭)
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
    // 서버 명령용 PHP 와 사이트가 도는 PHP 의 계열이 다를 때 (공유 호스팅에서 흔하다).
    // 막지 않고 사실만 알린다 — 설치·업데이트가 사이트와 다른 런타임에서 수행된다.
    'php_probe_ok_web_version_differs' => 'PHP :version 확인 (최소 요구 :min_version). 다만 사이트는 PHP :web_version 으로 동작하고 있어 서버 명령은 다른 버전에서 실행됩니다.',
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
        'private_attachment' => [
            'default' => '기본 (사이트 스토리지 드라이버를 따름)',
            's3' => 'Amazon S3',
        ],
        'file_delegation' => [
            'none' => '사용 안 함 (PHP 가 전송)',
            'x-sendfile' => 'X-Sendfile (Apache mod_xsendfile)',
            'x-accel-redirect' => 'X-Accel-Redirect (nginx)',
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
        'image' => [
            'gd' => 'GD',
            'imagick' => 'Imagick (ImageMagick)',
        ],
    ],

    // 드라이버 연결 테스트 메시지
    'driver_test_success' => '모든 드라이버 연결 테스트가 성공했습니다.',
    'driver_test_partial' => '일부 드라이버 연결 테스트가 실패했습니다.',
    'driver_test_error' => '드라이버 연결 테스트 중 오류가 발생했습니다.',
    'unknown_driver' => '알 수 없는 드라이버입니다.',

    // 웹서버 파일 위임 판정 (드라이버 시험 1단계, #878) — DriverConnectionTester::testFileDelegation()
    'driver_test' => [
        'file_delegation' => [
            'none' => '웹서버 파일 위임을 쓰지 않습니다. 파일은 PHP 가 보냅니다.',
            'apache_ready' => 'Apache 에 mod_xsendfile 이 로드되어 있습니다. XSendFilePath 를 확인하고, 저장한 뒤 「실제 전송 확인」으로 마무리하세요.',
            'apache_missing' => 'Apache 에 mod_xsendfile 이 로드되어 있지 않습니다. 이대로 켜면 파일이 빈 본문으로 나갑니다.',
            'apache_unknown' => 'Apache 모듈 목록을 읽을 수 없습니다(PHP-FPM 등). 저장한 뒤 「실제 전송 확인」으로 확인하세요.',
            'nginx_hint' => 'nginx 로 보입니다. 안내한 internal location 을 추가했는지 확인하고, 저장한 뒤 「실제 전송 확인」으로 마무리하세요.',
            'server_mismatch_nginx' => '이 서버는 nginx 로 보입니다. nginx 에서는 X-Accel-Redirect 를 고르세요.',
            'server_mismatch_apache' => '이 서버는 Apache 로 보입니다. Apache 에서는 X-Sendfile(mod_xsendfile)을 고르세요.',
            'server_unknown' => '웹서버 종류를 알 수 없습니다. 저장한 뒤 「실제 전송 확인」으로 확인하세요.',
        ],
    ],

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
    'driver_unusable_gd_extension' => 'gd PHP 확장이 설치되어 있지 않습니다.',
    'driver_unusable_imagick_extension' => 'imagick PHP 확장이 설치되어 있지 않습니다.',

    // 업로드 탭 썸네일 저장 위치 선택지 라벨 (ThumbnailDiskOptions)
    'thumbnail_disks' => [
        'thumbnails' => '서버 비공개 저장소 (기본)',
        's3' => 'Amazon S3 (비공개, 서버를 거쳐 전송)',
    ],
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
        'invalid_github_url' => 'GitHub 저장소 URL이 유효하지 않습니다.',
        'download_failed' => ':version 버전 다운로드에 실패했습니다.',
        'zip_extract_failed' => 'ZIP 파일 압축 해제에 실패했습니다.',
        'invalid_package' => '다운로드된 패키지가 유효하지 않습니다.',
        'invalid_package_not_g7' => '지정된 디렉토리가 그누보드7 프로젝트가 아닙니다. config/app.php 파일과 version 설정이 필요합니다.',
        'reserved_paths_in_release' => '배포본이 개발자 전용 custom 자리에 파일을 싣고 있어 업데이트를 시작하지 않았습니다. 아무 파일도 바뀌지 않았습니다. 대상: :paths',
        'custom_preflight_blocked' => '새 코어 :version 업데이트를 시작하지 않았습니다. 아무 파일도 바뀌지 않았고 사이트는 그대로 동작합니다.',
        'custom_conflict_package' => '개발자 패키지',
        'custom_conflict_reason' => '무엇이 맞지 않나',
        'custom_conflict_core' => '새 코어',
        'custom_conflict_constraint' => '이 패키지가 요구하는 :requires 버전 조건(:constraint)을 새 코어가 만족하지 않습니다',
        'custom_conflict_duplicate' => '코어도 같은 패키지를 다른 버전으로 갖고 있습니다 — 실행 때는 코어 버전이 쓰입니다',
        'custom_conflict_fix' => '해결: 개발자 패키지를 새 코어에 맞는 버전으로 올린 뒤(composer update [패키지] -d custom) php artisan custom:check 로 충돌이 없는지 확인하고 다시 업데이트하세요. composer 를 쓸 수 없는 서버라면 다른 PC 에서 고친 뒤 php artisan custom:vendor-bundle 로 묶음을 다시 만들어 올리세요.',
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

        // 루트 진입·보호 파일 통보
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

        // ── 웹 단계 실행 ────────────────────────────────
        //
        // 진행 기록(`log_*`)은 운영자가 [기록 보기] 로 여는 줄이다. 무엇을 했는지가
        // 아니라 **어디까지 갔는지**를 알 수 있어야 하므로 단계마다 한 줄을 남긴다.
        'composer_unavailable' => '이 서버에서는 Composer 를 실행할 수 없습니다 (프로세스 실행 함수가 차단되어 있습니다). 번들 vendor 방식으로 진행하거나 SSH 가 가능한 환경에서 실행하세요.',
        'web' => [
            // 단계 이름 (화면의 진행 목록)
            'steps' => [
                'preflight' => '사전 점검',
                'maintenance' => '점검 모드 전환',
                'download' => '내려받기',
                'backup' => '백업',
                'vendor' => '의존성 준비',
                'plan' => '적용 대상 산출',
                'apply' => '파일 적용',
                'vendor_copy' => '의존성 반영',
                'migrate' => '데이터베이스 반영',
                'upgrade_steps' => '업그레이드 스텝',
                'bundled' => '번들 확장·언어팩',
                'finalize' => '마무리',
            ],

            // 응답 메시지
            'status_success' => '업데이트 상태를 가져왔습니다.',
            'status_failed' => '업데이트 상태를 가져오지 못했습니다.',
            'started' => '업데이트를 시작했습니다.',
            'start_failed' => '업데이트를 시작하지 못했습니다.',
            'advanced' => '단계를 수행했습니다.',
            'advance_failed' => '단계를 수행하지 못했습니다.',
            'dismissed' => '업데이트 기록을 지웠습니다.',
            'dismiss_failed' => '업데이트 기록을 지우지 못했습니다.',

            // 진행 제어
            'no_active_run' => '진행 중인 업데이트가 없습니다.',
            'step_in_progress' => '다른 요청이 같은 단계를 수행하고 있습니다 — 잠시 후 다시 시도하세요.',
            'still_running' => '업데이트가 아직 진행 중입니다. 끝난 뒤에 기록을 지울 수 있습니다.',
            'missing_pending_path' => '내려받은 업데이트 파일의 위치를 찾지 못했습니다 — 처음부터 다시 시작하세요.',

            // 사전 진단
            'preflight_failed' => '사전 점검에서 문제가 발견되어 업데이트를 시작하지 않았습니다: :reasons',
            'preflight_not_writable' => '다음 경로에 쓰기 권한이 없습니다: :paths — 웹에서 업데이트하려면 이 경로들을 웹 서버 계정이 쓸 수 있어야 합니다. FTP 프로그램에서 해당 폴더의 권한을 755(폴더)·644(파일)로, 그래도 쓰기 권한이 생기지 않으면 707·606 으로 바꾸세요. SSH 를 쓸 수 있다면 `php artisan core:update` 로 업데이트해도 됩니다.',
            'preflight_low_disk' => '여유 공간이 부족합니다 (남은 공간 :free, 필요 추정 :required). 백업과 새 버전이 동시에 놓이는 구간이 있어 현재 크기의 몇 배가 필요합니다.',

            // 수동 ZIP
            'version_from_zip' => '(ZIP 추출 후 판별)',
            'zip_file_invalid' => 'ZIP 파일 이름만 지정할 수 있습니다 (경로 없이 `.zip` 으로 끝나는 이름).',

            // 착수 요청 항목 이름 (검증 오류 문구)
            'attributes' => [
                'update_bundled_extensions' => '번들 확장·언어팩 함께 업데이트',
                'rebuild_search_index' => '검색 인덱스 다시 만들기',
                'layout_strategy' => '레이아웃 처리 방식',
                'zip_file' => '직접 올린 ZIP 파일',
            ],
            'zip_not_newer' => '지정한 ZIP 의 버전(:to)이 현재 버전(:from)보다 새롭지 않습니다. 웹 업데이트는 강제 재적용을 지원하지 않습니다.',

            // 실패·되돌리기
            'upgrade_steps_failed' => '업그레이드 스텝 실행에 실패했습니다: :output',
            'handoff_exhausted' => '업그레이드 스텝이 새 프로세스 재진입을 반복 요청해 중단했습니다 (마지막 완료 지점 :after / 사유 :reason).',
            'vendor_swap_failed' => 'vendor 폴더를 새 판으로 바꿔 끼우다 실패했고 원래대로 되돌리지도 못했습니다 — 운영 vendor 자리(:live)가 비어 있을 수 있습니다. 옛 vendor 는 :parked 에, 새 vendor 는 :replacement 에 남아 있습니다. 자동 되돌리기가 옛 vendor 를 다시 끼웁니다. 그래도 사이트가 열리지 않으면 FTP 로 :parked 폴더를 :live 자리로 옮기세요.',
            'vendor_swap_back_failed' => '되돌리기에서 옛 vendor(:parked)를 다시 끼우지 못했습니다 — 백업의 vendor 로 덮어씁니다. 사이트가 열리지 않으면 FTP 로 :parked 폴더를 :live 자리로 옮기세요.',
            'step_interrupted' => '이 단계가 서버의 요청 시간 제한(:seconds초) 안에 끝나지 않아 중단되었습니다 (:error).',
            'step_fatal' => '이 단계를 수행하던 중 치명 오류로 요청이 끊겼습니다 (:error).',
            'step_interrupted_unknown' => '이 단계를 수행하던 요청이 기록을 남기지 못하고 끝났습니다(서버가 요청 시간 제한으로 끊은 것으로 보입니다).',
            'plan_slices_exhausted' => '적용 대상 산출을 :count번 나눠 이어 갔는데도 끝나지 않아 중단했습니다.',
            'bundled_partial_failure' => '번들 확장·언어팩 중 :failed개가 업데이트되지 않았습니다. 코어는 정상 반영되었으며, 실패한 확장은 확장 관리 화면에서 개별로 다시 시도할 수 있습니다.',
            'permission_warning' => '소유권·권한 복원에서 :target 의 :failed개 항목을 정상화하지 못했습니다 — 업로드 파일이나 캐시 쓰기에 문제가 생길 수 있으니 확인하세요.',
            'maintenance_off_failed' => '점검 모드를 해제하지 못했습니다 (:error) — 사이트가 점검 화면에 머물러 있습니다. FTP 로 `storage/framework/down` 파일을 삭제하면 해제됩니다. SSH 를 쓸 수 있다면 `php artisan up` 을 실행해도 됩니다.',
            'ownership_restore_failed' => '되돌린 뒤 파일 소유권·권한을 원래대로 되돌리지 못했습니다 (:error) — 사이트가 파일을 쓰지 못할 수 있으니 권한을 확인하세요.',

            // 진행 기록
            'log_started' => '업데이트 시작 (:from → :to)',
            'log_preflight_ok' => '사전 점검 통과 (검사 경로 :checked개)',
            'log_preflight_failed' => '사전 점검 실패 — 적용을 시작하지 않았습니다',
            'log_maintenance_on' => '점검 모드 켜짐',
            'log_maintenance_off' => '점검 모드 해제',
            'log_downloaded' => ':version 내려받기 완료',
            'log_zip_extracted' => '수동 ZIP 추출 완료 (:file → :version)',
            'log_backup' => '백업 생성 완료 (:path)',
            'log_vendor_skipped' => '의존성 변경 없음 — 준비 단계 건너뜀',
            'log_vendor' => '의존성 준비 완료 (방식 :strategy, 패키지 :packages개)',
            'log_manifest' => '신규 파일 목록 작성 (파일 :files개, 폴더 :dirs개)',
            'log_manifest_failed' => '신규 파일 목록 작성 실패 — 되돌리기는 덮어쓰기만 수행합니다 (:error)',
            'log_apply_list' => '적용 대상 산출 완료 (신규 :added개, 변경 :changed개)',
            'log_apply_list_failed' => '적용 대상 산출 실패 — 전체 덮어쓰기로 진행합니다 (:error)',
            'log_extract_continues' => '배포본 압축 풀기를 다음 요청에서 이어 갑니다 (:done / :total)',
            'log_backup_continues' => '백업을 다음 요청에서 이어 갑니다',
            'log_bundled_progress' => '번들 확장·언어팩 업데이트를 이어 갑니다 (:done / :total)',
            'log_rollback_continues' => '되돌리기를 다음 요청에서 이어 갑니다',
            'log_cleanup_continues' => '임시 파일 정리를 다음 요청에서 이어 갑니다',
            'log_bundled_item_interrupted' => '번들 확장 :identifier 업데이트가 중간에 끊겨 건너뜁니다 — :reason',
            'log_step_retry' => ':step 단계가 중간에 끊겼습니다 — 다음 요청에서 이어 갑니다 (:attempt / :max번째) — :reason',
            'log_ownership_continues' => '파일 소유권·권한 복원을 다음 요청에서 이어 갑니다',
            'rollback_cleanup_skipped' => '파일은 백업본으로 되돌렸지만, 파일 소유권·권한 복원과 임시 파일 정리를 서버의 요청 시간 안에 마치지 못해 건너뛰었습니다. 사이트는 열었습니다 — 업로드나 캐시 쓰기에 문제가 생기면 권한을 확인하세요.',
            'finalize_cleanup_skipped' => '업데이트는 끝났지만 백업·임시 파일 정리와 파일 소유권·권한 복원을 서버의 요청 시간 안에 마치지 못해 건너뛰었습니다 — 저장소의 core_backups·core_pending 폴더에 남은 파일은 직접 지워도 됩니다.',
            'log_plan_continues' => '적용 대상 산출을 다음 요청에서 이어 갑니다 (지금까지 :checked개 확인)',
            'log_preflight_partial' => '쓰기 권한·용량 점검을 요청 시간 안에서 할 수 있는 만큼만 했습니다 — 확인하지 못한 경로는 적용 단계에서 막히면 자동으로 되돌립니다',
            'log_rollback_interrupted' => '되돌리기가 중간에 끊겼습니다 (:attempt / :max번째) — :reason',
            'log_applied' => '코어 파일 적용 완료',
            'log_vendor_copy_skipped' => '의존성 변경 없음 — 반영 단계 건너뜀',
            'log_vendor_copied' => '의존성 반영 완료',
            'log_migrated' => '마이그레이션 · 권한/메뉴 재동기 완료',
            'log_upgrade_steps' => '업그레이드 스텝 :count개 실행 완료',
            'log_handoff' => '업그레이드 스텝이 새 프로세스를 요청 (:after 까지 완료, 대상 :to) — 다음 단계 요청에서 이어서 실행합니다. 사유: :reason',
            'log_bundled_skipped' => '번들 확장·언어팩 함께 업데이트를 선택하지 않았습니다',
            'log_bundled_none' => '번들에 새 버전이 있는 확장·언어팩이 없습니다',
            'log_bundled_placement' => '공식 번들 배치 판정 — 배치 :placed개, 미배치 :unplaced개(설치하지 않은 번들은 이번 업데이트에서 건너뜁니다)',
            'log_bundled_record_restored' => '공식 번들 배치 기록을 업데이트 전 내용으로 되돌렸습니다',
            'log_bundled' => '번들 확장·언어팩 업데이트 완료 (성공 :success, 실패 :failed)',
            'log_spawn_fallback' => '별도 프로세스 실행 불가 — 같은 요청 안에서 실행합니다 (:reason)',
            'log_finalized' => '마무리 완료 — 현재 버전 :version',
            'log_step_failed' => ':step 단계에서 실패: :error',
            'log_restored' => '백업본으로 되돌렸습니다',
            'log_restored_partial' => '일부만 되돌렸습니다 (실패 항목: :targets)',
            'log_restore_failed' => '되돌리기 실패: :error',
        ],

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
        'no_extract_method_available' => '압축을 풀 수 없습니다. PHP zip 확장(ZipArchive)이 필요합니다. 서버에 zip 확장을 설치하거나, 공유 호스팅이면 호스팅 업체에 활성화를 요청한 뒤 다시 시도하세요.',
        'manual_update_guide' => '수동 업데이트: GitHub에서 릴리스 ZIP을 다운로드하여 압축 해제 후, php artisan core:update --source=/압축해제/경로 명령어를 실행하세요.',

        // 추출 폴백 체인
        'archive_url_not_found' => ':type 아카이브 URL을 찾을 수 없습니다. 다음 방법을 시도합니다.',
        'extracting_with' => ':method 방식으로 압축 해제 중...',
        'extract_empty' => '압축 해제된 디렉토리가 비어 있습니다.',
        'extract_fallback' => ':method 방식 실패: :error — 다음 방법을 시도합니다.',
        // 문구는 CLI 와 웹 화면이 함께 쓴다 — 한쪽에서만 실행할 수 있는 조작(예: 명령줄 옵션)을
        // 담지 않는다. 무CLI 호스팅의 운영자는 이미 화면의 「직접 올린 ZIP 으로 업데이트」를
        // 쓰고 있어, 그 자리에서 명령줄 옵션을 안내받으면 따를 방법이 없다.
        'all_extract_methods_failed' => '압축 파일을 풀지 못했습니다. 파일이 온전히 올라갔는지 확인한 뒤 다시 시도해 주세요.',
        'release_not_found' => 'GitHub 에서 코어 :version 배포본을 찾지 못했습니다 — 그 버전 태그가 아직 없거나 GitHub 에 연결하지 못했습니다. 배포본 ZIP 을 직접 받아 「직접 올린 ZIP 으로 업데이트」로 적용해 주세요.',
        'extension_release_not_found' => 'GitHub 저장소 :repository 에서 :version 배포본을 찾지 못했습니다 — 그 버전 태그가 아직 없거나 GitHub 에 연결하지 못했습니다.',
        'zip_file_not_found' => '지정된 ZIP 파일을 찾을 수 없습니다: :path',
        'source_vendor_missing' => '소스 디렉토리에 vendor가 없습니다. composer install이 실행되지 않았을 수 있습니다.',
        'composer_failed_with_output' => 'composer install 실행에 실패했습니다.:output',
        // `core:update` 명령 콘솔 출력
        'console' => [
            'already_running' => '다른 코어 업데이트(core:update)가 진행 중이라 시작하지 않았습니다. 직전 실행이 비정상 종료됐다면 :minutes분 뒤 다시 실행하세요.',
            'lock_unavailable' => '코어 업데이트 잠금 저장소(캐시)를 쓸 수 없어 시작하지 않았습니다. 캐시 드라이버 상태를 확인한 뒤 다시 실행하세요.',
            'source_zip_conflict' => '--source 와 --zip 은 동시에 지정할 수 없습니다. 하나만 선택하세요.',
            'local_source_conflict' => '--local 은 --source / --zip 과 동시에 사용할 수 없습니다.',
            'zip_not_found' => '지정된 ZIP 파일이 존재하지 않습니다: :path',
            'source_not_found' => '지정된 소스 디렉토리가 존재하지 않습니다: :path',
            'docroot_exposure_header' => '⚠ 공개 파일 사본 — 아래를 확인해야 합니다.',
            'root_files_header' => '⚠ 루트 진입·보호 파일 — 진행은 계속되었지만 아래를 직접 확인해야 합니다.',
            'check_failed' => '업데이트 확인 실패: :error',
            'already_latest' => '현재 최신 버전입니다: :version',
            'current_version' => '현재 버전: :version',
            'target_version_pending' => '업데이트 버전: (ZIP 추출 후 판별)',
            'target_version' => '업데이트 버전: :version',
            'confirm_update' => '코어를 업데이트하시겠습니까?',
            'confirm_unavailable' => '확인 질문에 답할 수 없는 실행(터미널 없음 또는 --no-interaction)이라 코어 업데이트를 시작하지 않았습니다. (사이트는 바뀌지 않았습니다)',
            'confirm_unavailable_hint' => 'cron, CI, 원격 명령처럼 확인 없이 진행해야 하면 --yes 옵션을 붙여 다시 실행하세요. 예: php artisan core:update --yes',
            'php_extension_core_missing' => '새 코어에 필요한 PHP 확장 :extensions 이(가) 서버에 없어 업데이트를 중단했습니다. 확장을 설치한 뒤 다시 실행하세요. (사이트는 바뀌지 않았습니다)',
            'php_extension_extension_warning' => '함께 갱신될 확장 :extension 이(가) 요구하는 PHP 확장 :extensions 이(가) 서버에 없습니다 — 업데이트는 진행하지만 해당 기능이 동작하지 않을 수 있습니다',
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
            'handoff_exit_code' => '종료 코드 :code (재개 필요) — 스크립트에서 실행했다면 위 재개 명령을 이어서 실행하세요.',
            'backup_kept' => '백업이 유지되었습니다: :path',
            'restoring_backup' => '백업에서 복원 중...',
            'restored' => '백업에서 복원되었습니다.',
            'restored_partial' => '백업에서 부분 복원되었습니다.',
            'restore_failed_targets' => '복원 실패 항목: :targets',
            'restore_failed' => '백업 복원 실패: :error',
            'cache_clear_failed' => '캐시 정리 실패 — 부팅 후 \'php artisan optimize:clear\' 를 수동으로 실행하세요. SSH 를 쓸 수 없으면 FTP 로 `bootstrap/cache` 의 `config.php`·`routes-v7.php`·`events.php` 를 삭제해도 됩니다: :error',
            'update_failed' => '코어 업데이트 실패: :error',
            'failure_report' => '실패 리포트: :path',
            'maintenance_disabled' => '복원 완료: 유지보수 모드가 해제되었습니다.',
            'maintenance_disable_failed' => '유지보수 모드 해제 실패. 수동으로 해제하세요: php artisan up (SSH 를 쓸 수 없으면 FTP 로 `storage/framework/down` 파일을 삭제)',
            'maintenance_kept' => '유지보수 모드가 유지됩니다.',
            'manual_recovery_required' => '수동 복구가 필요합니다:',
            'manual_restore_backup' => '백업에서 수동 복원: :command',
            'manual_reinstall_composer' => 'Composer 재설치: composer install --no-dev --optimize-autoloader',
            'manual_disable_maintenance' => '유지보수 해제: php artisan up',
            'run_previous_version' => '이전 버전으로 사이트를 운영하려면: php artisan up',
            'admin_access' => '관리자 접근: :secret',
            'manifest_clear_failed' => 'spawn 직전 패키지 매니페스트 삭제 실패 — 자식이 이전 설치본의 provider 목록으로 부팅할 수 있습니다 (권한·소유권 확인): :paths',
            'hook_cache_failed' => '훅 캐시를 새 프로세스로 다시 굽지 못했습니다 — 업데이트 뒤 `php artisan hooks:cache` 를 실행하세요. SSH 를 쓸 수 없으면 FTP 로 `bootstrap/cache/hooks.php` 를 삭제해도 됩니다(다음 요청부터 캐시 없이 스캔으로 동작): :output',
            'spawn' => [
                'proc_open_disabled' => 'proc_open 비활성',
                'proc_open_failed' => 'proc_open 자원 생성 실패',
                'steps_signal_missing' => 'spawn 자식이 [STEPS_EXECUTED] 신호 미발행 — 이전 버전 자식 또는 silent skip 의심',
                'zero_steps' => 'spawn 자식 exit=0 이지만 step 0건 실행 — 의도된 동작이 아님 (from=:from to=:to)',
                'zero_steps_discovered' => 'spawn 자식 exit=0 이지만 step 0건 실행 — 의도된 동작이 아님 (from=:from to=:to, 발견 :count건)',
                'steps_already_run' => '범위 안 업그레이드 스텝 :count건은 같은 DB 의 다른 서버·실행이 이미 실행해 건너뛰었습니다.',
                'abnormal_exit' => 'spawn 비정상 종료 (exit=:code)',
                'abort_reason' => 'spawn 자식 실패 — :reason. fail-fast 모드. 수동 재개로 진행하세요.',
            ],
            'owner_mismatch' => '실행 사용자와 코어 파일 소유자가 다릅니다.',
            'owner_mismatch_current' => '실행 사용자: :user (uid=:uid)',
            'owner_mismatch_owner' => '코어 소유자: :user (uid=:uid)',
            'owner_mismatch_hint' => '권한 거부가 발생할 수 있습니다. 일반적으로 코어 파일 소유자로 SSH 로그인 후 실행해야 합니다. vendor/ 가 다른 사용자 소유인 경우 \'chown -R $(whoami) vendor/\' 후 재시도하세요.',
            'resume_root_known_warning' => '현재 sudo(root) 로 실행 중입니다. 위 명령을 root 로 그대로 실행하면 업그레이드 스텝이 생성하는 파일이 root 소유가 되어 이후 웹서버(:user) 요청이 쓰기 실패할 수 있습니다. 반드시 웹서버 계정(:user)으로 재실행하세요.',
            'resume_command_unknown_user' => 'sudo -u <웹서버계정> :command',
            'resume_command_powershell' => 'PowerShell: :command',
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
        'prune_protected_integration' => '켜져 있는 확장의 연동 대상이라 보존: :label (:integrated_by)',
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
    | 값 편집은 환경설정 > 고급의 같은 카드에서 한다 — 무CLI 설치에는 `.env` 를
    | 고칠 통로가 없어 진단만으로는 조치가 끝나지 않는다. `.env` 는 G7_ENV_PRIORITY 로
    | 잠글 때만 우선한다.
    |
    */

    'trusted_proxy' => [
        'alert_title' => '신뢰 프록시가 설정되지 않았습니다',
        'alert_message' => '프록시 헤더(:headers)를 받고 있으나 신뢰 프록시가 설정되지 않아, 모든 방문자가 같은 주소(:ip)로 기록되고 있습니다. 환경설정 > 고급 > 신뢰 프록시에서 신뢰 범위를 지정하세요. 상세: https://github.com/gnuboard/g7/blob/main/docs/backend/reverse-proxy.md',
    ],
    // `settings:*` 명령 출력
    'console' => [
        'cli' => [
            'scope_conflict' => '--plugin 과 --module 은 함께 쓸 수 없습니다.',
            'unknown_key' => '알 수 없는 설정 키입니다: :key',
            'key_format' => '설정 키는 「범주.항목」 형식입니다 (예: mail.host): :key',
            'computed_key' => ':key 는 다른 값에서 계산되는 값이라 지정할 수 없습니다.',
            'env_locked' => ':key 는 .env 가 값을 정하고 있어 여기서 바꿀 수 없습니다. .env 의 :env 를 고치세요.',
            'sensitive_positional' => ':key 는 민감한 값이라 명령줄 인자로 받지 않습니다 — 환경변수 G7_SETTING_VALUE, --value-file, 숨김 입력 중 하나를 쓰세요.',
            'value_missing' => '값을 주세요 — 위치 인자, --value-file, 환경변수 G7_SETTING_VALUE 중 하나.',
            'value_file_unreadable' => '값 파일을 읽을 수 없습니다: :path',
            'invalid_json' => '--json-value 의 값이 올바른 JSON 이 아닙니다: :error',
            'ask_secret' => ':key 값 (입력한 글자는 보이지 않습니다)',
            'array_replaced' => '배열 값은 통째로 바뀝니다 (기존 항목과 합치지 않습니다).',
            'validation_failed' => '검증을 통과하지 못해 저장하지 않았습니다:',
            'save_failed' => '저장하지 못했습니다: :reason',
            'saved' => '저장했습니다: :key',
            'changed' => '   :before → :after',
            'cache_rebuilt' => '설정 캐시를 다시 만들었습니다.',
            'plugin_not_found' => '설치된 플러그인이 아닙니다: :id',
            'plugin_unknown_key' => ':id 플러그인 설정에 없는 키입니다: :key',
            'template_not_found' => '설치됐고 설정 선언이 있는 템플릿이 아닙니다: :id',
            'template_unknown_key' => ':id 템플릿 설정에 없는 키입니다: :key',
            'template_attachment_readonly' => ':key 는 파일 업로드가 필요한 항목이라 관리자 화면에서만 바꿀 수 있습니다.',
            'module_unsupported' => '이 모듈은 터미널 설정 지정을 지원하지 않습니다: :id',
            'admin_screen_only' => ':key 는 관리자 화면에서만 바꿀 수 있습니다.',
            'secrets_shown' => '민감한 값을 그대로 보여 줍니다 — 화면 기록·공유에 주의하세요.',
            'computed_marker' => '(계산값)',
            'env_locked_marker' => '(.env 고정)',
            'no_settings' => '표시할 설정이 없습니다.',
        ],
        'install' => [
            'cache_refresh_failed' => '설정은 설치했지만 캐시를 다시 만들지 못했습니다(관리자 화면 저장 또는 php artisan config:cache 로 다시 만드세요): :error',
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
            'confirm_unavailable' => '확인 질문에 답할 수 없는 실행(터미널 없음 또는 --no-interaction)이라 기존 JSON 설정 파일을 덮어쓰지 않았습니다. (설정은 바뀌지 않았습니다)',
            'confirm_unavailable_hint' => 'cron, CI, 원격 명령처럼 확인 없이 덮어써야 하면 --force 옵션을 붙여 다시 실행하세요. 예: php artisan settings:migrate-to-json --force',
            'nothing_to_migrate' => '마이그레이션할 설정이 없습니다. 기본값으로 초기화합니다.',
            'found' => '총 :count개의 설정을 발견했습니다.',
            'category_saved' => ':category 카테고리 저장 완료',
            'done' => '마이그레이션이 완료되었습니다!',
            'location' => '설정 파일 위치: :path',
        ],
    ],

    // custom 예약 자리(#792) — custom:check·custom:vendor-* 명령과 코어 업데이트 출력
    'custom_reserved' => [
        'check' => [
            'reserved_title' => 'custom 예약 자리 (코어 업데이트가 덮지도 지우지도 않음)',
            'col_path' => '경로',
            'col_state' => '상태',
            'exists' => '있음',
            'routes_title' => 'custom 라우트 (routes/custom.php)',
            'no_routes_file' => 'routes/custom.php 가 없습니다 — 모든 주소를 그누보드7 화면이 그립니다.',
            'col_methods' => '메서드',
            'col_uri' => '주소',
            'col_action' => '처리',
            'registered' => '등록',
            'rejected' => '거부 — 코어 예약 주소(:reason)',
            'patterns_title' => '엔진 화면에서 전체 페이지로 이동하는 주소 (G7Config.customRoutes)',
            'packages_title' => 'custom 패키지 (custom/composer.lock — 코어 업데이트가 건드리지 않음)',
            'col_package' => '패키지',
            'col_version' => '버전',
            'no_packages' => 'custom/composer.lock 이 없습니다.',
            'no_conflicts' => '코어 패키지와 충돌 없음',
            'conflicts_title' => '코어 패키지와 맞지 않는 개발자 패키지 :count건',
            'developer_provider' => '개발자 서비스 프로바이더: :state',
        ],
        'errors' => [
            'internal_api_path' => 'InternalApiClient: /api/ 로 시작하는 경로만 부를 수 있습니다 (:path)',
            'internal_api_nested' => 'InternalApiClient: 하위 요청 안에서 다시 부를 수 없습니다',
            'internal_api_session_route' => 'InternalApiClient: 로그인·로그아웃·갱신·가입처럼 세션을 여는 인증 API 는 부를 수 없습니다 (:path) — 로그아웃은 ScreenAuth::logout() 을 쓰세요',
            'screen_url_missing_param' => 'ScreenUrl: 경로 자리 :placeholder 의 값이 없습니다 (:template)',
        ],
        'example' => [
            'unknown_sample' => '알 수 없는 견본입니다: :sample (가능한 견본: :available)',
            'exists' => '이미 있는 파일이 있어 견본을 만들지 않았습니다. 아래 파일을 옮기거나 지운 뒤 다시 실행하세요.',
            'created' => '견본 파일 :count개를 만들었습니다.',
            'next_steps' => '라우트 캐시를 쓰는 사이트라면 php artisan route:cache 를 다시 실행하세요. 견본을 지우려면 위 파일을 지우면 됩니다.',
        ],
        'vendor' => [
            'install_failed' => '개발자 패키지(custom/vendor) 설치에 실패했습니다 — 개발자 화면이 패키지를 못 찾을 수 있습니다: :reason',
            'no_composer_json' => 'custom/composer.json 이 없습니다 — 먼저 `composer require <패키지> -d custom` 으로 개발자 패키지를 설치하세요.',
            'no_bundle_zip' => 'custom/vendor-bundle.zip 이 없습니다 — `php artisan custom:vendor-bundle` 로 먼저 만드세요.',
        ],
    ],

    // 환경설정 > 고급 > GeoIP [갱신] 결과
    'geoip' => [
        'update_success' => 'GeoIP 데이터베이스를 갱신했습니다.',
        'license_key_missing' => 'MaxMind 라이선스 키가 설정되지 않았습니다.',
        'license_key_invalid' => 'MaxMind 라이선스 키가 올바르지 않습니다.',
        'connection_failed' => 'MaxMind 서버에 연결하지 못했습니다.',
        'update_failed' => 'GeoIP 데이터베이스를 갱신하지 못했습니다.',
    ],

    // 저장은 허용하되 알려야 할 저장소 조합 (#916)
    'warnings' => [
        'private_attachment_same_as_public_asset' => '비공개 첨부 저장소와 공개 자산 디스크가 같은 디스크(:disk)입니다. 버킷에 공개 읽기 정책이 있으면 비공개 원본도 버킷 주소로 직접 읽힐 수 있으니, 비공개 원본은 공개 정책이 없는 버킷이나 경로를 쓰세요.',
        'gated_thumbnail_same_as_public_asset' => '권한이 걸린 이미지 썸네일과 공개 자산 디스크가 같은 S3 버킷(:disk)을 씁니다. 버킷에 공개 읽기 정책이 있으면 권한이 걸린 이미지의 썸네일도 버킷 주소로 직접 읽힐 수 있으니, 썸네일 경로(thumbnails/)에 공개 정책이 걸리지 않았는지 확인하세요.',
    ],
];
