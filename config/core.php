<?php

use App\Notifications\Identity\IdentityNotificationType;

return [
    /*
    |--------------------------------------------------------------------------
    | 환경설정 입력 한계값
    |--------------------------------------------------------------------------
    | 관리자 환경설정 화면의 숫자 입력 경계값(min/max)과 저장 검증 규칙이 공유하는 단일 출처.
    |
    | 화면과 규칙이 각자 리터럴을 들면 두 값이 조용히 갈라져 "화면이 허용한 값인데 저장에서
    | 422" 또는 그 반대가 됩니다. 규칙(SaveSettingsRequest)은 이 값을 읽어 규칙 문자열을
    | 만들고, 화면은 설정 응답의 `_meta.limits` 로 같은 값을 받아 바인딩합니다.
    |
    | 키 이름은 `{카테고리}_{필드}_{min|max}` 로, 설정 키(`upload.max_file_size`)와 1:1 대응합니다.
    | 카테고리를 접두사로 두는 이유는 `seo.cache_ttl` 과 `advanced.seo_cache_ttl` 처럼 필드명이
    | 겹치는 조합이 실제로 있기 때문입니다.
    */
    'settings_limits' => [
        // 업로드
        'upload_max_file_size_min' => 1,
        'upload_max_file_size_max' => 1024,
        'upload_image_max_width_min' => 100,
        'upload_image_max_width_max' => 10000,
        'upload_image_max_height_min' => 100,
        'upload_image_max_height_max' => 10000,
        'upload_image_quality_min' => 1,
        'upload_image_quality_max' => 100,
        'upload_orphan_retention_days_min' => 1,
        'upload_orphan_retention_days_max' => 3650,

        // SEO
        'seo_og_image_default_width_min' => 0,
        'seo_og_image_default_width_max' => 8000,
        'seo_og_image_default_height_min' => 0,
        'seo_og_image_default_height_max' => 8000,
        'seo_cache_ttl_min' => 60,
        'seo_cache_ttl_max' => 86400,
        'seo_sitemap_cache_ttl_min' => 3600,
        'seo_sitemap_cache_ttl_max' => 604800,
        'seo_sitemap_urls_per_file_min' => 1000,
        'seo_sitemap_urls_per_file_max' => 50000,

        // 보안
        'security_password_min_length_min' => 6,
        'security_password_min_length_max' => 64,
        'security_auth_token_lifetime_min' => 0,
        'security_auth_token_lifetime_max' => 3600,
        'security_max_login_attempts_min' => 0,
        'security_max_login_attempts_max' => 100,
        'security_login_lockout_time_min' => 0,
        'security_login_lockout_time_max' => 1440,
        // 비밀번호 정책 (#736) — 판독부(PasswordPolicy·PasswordExpiryService)도 이 값으로 양쪽 클램프한다
        'security_password_repeated_chars_length_min' => 3,
        'security_password_repeated_chars_length_max' => 6,
        'security_password_sequential_chars_length_min' => 3,
        'security_password_sequential_chars_length_max' => 6,
        'security_password_reuse_count_min' => 1,
        'security_password_reuse_count_max' => 10,
        'security_password_expiry_days_min' => 30,
        'security_password_expiry_days_max' => 730,
        'security_password_expiry_notice_days_min' => 1,
        'security_password_expiry_notice_days_max' => 30,
        'security_password_expiry_remind_days_min' => 1,
        'security_password_expiry_remind_days_max' => 90,
        'security_password_expiry_batch_size_min' => 0,
        'security_password_expiry_batch_size_max' => 100000,
        // 활동 로그(접속기록) 보관 기간 — 최소 365 는 개인정보의 안전성 확보조치 기준 제8조 접속기록 1년(#736 D14)
        'security_activity_log_retention_days_min' => 365,
        'security_activity_log_retention_days_max' => 3650,

        // 고급 (캐시 TTL)
        'advanced_cache_ttl_min' => 0,
        'advanced_cache_ttl_max' => 14400,
        'advanced_seo_sitemap_cache_ttl_min' => 3600,
        'advanced_seo_sitemap_cache_ttl_max' => 604800,

        // 드라이버
        'drivers_redis_port_min' => 1,
        'drivers_redis_port_max' => 65535,
        'drivers_redis_database_min' => 0,
        'drivers_redis_database_max' => 15,
        'drivers_memcached_port_min' => 1,
        'drivers_memcached_port_max' => 65535,
        'drivers_session_lifetime_min' => 1,
        'drivers_session_lifetime_max' => 43200,
        'drivers_websocket_port_min' => 1,
        'drivers_websocket_port_max' => 65535,
        'drivers_websocket_server_port_min' => 1,
        'drivers_websocket_server_port_max' => 65535,
        'drivers_log_days_min' => 1,
        'drivers_log_days_max' => 365,

        // 본인인증
        'identity_challenge_ttl_minutes_min' => 1,
        'identity_challenge_ttl_minutes_max' => 1440,
        'identity_max_attempts_min' => 1,
        'identity_max_attempts_max' => 20,

        // 개인정보 보유기간·휴면 (일)
        // 하한을 0 으로 두지 않는 이유: 0 이면 켠 즉시 전원이 대상이 되어 되돌릴 수 없다.
        // 휴면 전환 하한 30 일은 유효기간제 폐지 이후 "자율 정책 + 사전 안내" 라는 법 취지의
        // 최소선이다 — 그보다 짧은 전환은 사전 안내가 실질적으로 불가능하다.
        'privacy_retention_days_min' => 1,
        'privacy_retention_days_max' => 3650,
        'privacy_dormant_days_min' => 30,
        'privacy_dormant_notice_days_min' => 1,
        'privacy_dormant_notice_days_max' => 365,

        // 목록 한계값 (0 = 무제한)
        'advanced_pagination_result_cap_min' => 0,
        'advanced_pagination_result_cap_max' => 1000000,
        'advanced_pagination_max_page_min' => 0,
        'advanced_pagination_max_page_max' => 100000,
    ],

    /*
    |--------------------------------------------------------------------------
    | 목록 한계값 기본값
    |--------------------------------------------------------------------------
    | 관리자 환경설정(`pagination` 카테고리)이 비어 있을 때 쓰는 코드 기본값입니다.
    | 실제 해석은 App\Support\Query\PaginationLimits 가 단독으로 수행하며, 확장은
    | 이 값을 리터럴로 다시 적지 않고 필터 훅으로만 조정합니다.
    |
    | - result_cap: 총 건수를 정확히 세는 상한. 이 값을 넘는 매칭은 "이상" 으로만 보고합니다.
    |               페이지 이동은 상한과 무관하게 끝까지 열려 있고, 계산이 불가능해지는 것은
    |               마지막 페이지 번호 하나뿐입니다.
    | - max_page:   직접 요청할 수 있는 페이지 번호 상한 (남용 차단용).
    |
    | 둘 다 0 이면 무제한입니다.
    */
    'pagination' => [
        'result_cap' => 10000,
        'max_page' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | 활동 로그 자동 파기 (activity-log:prune)
    |--------------------------------------------------------------------------
    | 일반 로그는 환경설정 > 보안 「활동 로그 보관 기간」(security.activity_log_retention_days,
    | 365~3650일)을 따르고, 아래 액션은 그 값과 관계없이 최소 long_retention_days 일 보관합니다.
    | 개인정보의 안전성 확보조치 기준 제5조③ — 권한 부여·변경·말소 내역 3년 보관(#736 D14).
    |
    | - long_retention_actions: 역할 정의·권한 구성·회원 역할 변경 기록 (정확히 일치하는 액션 이름)
    | - long_retention_days:    그 액션의 최소 보관 일수
    */
    'activity_log' => [
        'long_retention_actions' => [
            'role.create',
            'role.update',
            'role.delete',
            'role.sync_permissions',
            'role.toggle_status',
            'menu.sync_roles',
            'user.roles_update',
            'user.bulk_roles',
        ],
        'long_retention_days' => 1095,
    ],

    /*
    |--------------------------------------------------------------------------
    | 부트스트랩 리소스 정적 게시 (bake)
    |--------------------------------------------------------------------------
    | 초기 부트스트랩 리소스(다국어 병합·컴포넌트 정의·라우트·확장 번들·템플릿
    | dist 에셋)를 캐시 버전 디렉토리(`public/build/ext/{v}/`)에 실파일로 게시해
    | 웹서버가 rewrite 전에 직접 서빙하는 fast path 의 스위치입니다.
    |
    | 끄면(false) 게시가 중단되고 blade 가 정적 URL 을 방출하지 않아 전면 API
    | 폴백(종전 동작)으로 돌아갑니다. 이미 게시된 파일은 참조되지 않은 채 남습니다.
    */
    'static_cache' => [
        'enabled' => env('G7_STATIC_CACHE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO 봇 캐시 상한
    |--------------------------------------------------------------------------
    | 봇 판정은 User-Agent 문자열뿐이라 위장이 가능하고, 캐시 키에 쿼리가 들어가므로
    | 물음표 뒤 값만 바꾸면 매 요청이 미스가 됩니다. 미스 1건은 레이아웃 병합·표현식
    | 평가·자기 API 루프백 호출을 유발하고 그 결과가 캐시에 쌓입니다.
    |
    | 아래 값이 그 증식을 막는 상한입니다. 렌더 예산을 넘긴 요청은 오류가 아니라
    | 일반 SPA 응답을 받습니다(봇에게 오류를 주면 색인에서 URL 이 빠집니다).
    |
    | IP 단위 상한(render_misses_per_minute, stats_records_per_minute)은 요청 IP 를
    | 기준으로 셉니다. 리버스 프록시·CDN 뒤에 두면서 TRUSTED_PROXIES 를 지정하지 않으면
    | 모든 요청이 프록시 IP 하나로 보여 사이트 전체가 한 예산을 나눠 쓰게 되고, 정상
    | 검색엔진 봇도 예산 초과 시점부터 SPA 를 받습니다. docs/backend/reverse-proxy.md 참조.
    */
    'seo_cache_limits' => [
        // 캐시 키에 허용하는 쿼리 파라미터 수 (초과 → 캐시·렌더 안 함)
        'max_query_params' => (int) env('G7_SEO_CACHE_MAX_QUERY_PARAMS', 10),

        // 정규화된 쿼리 문자열 길이 상한 (바이트)
        'max_query_length' => (int) env('G7_SEO_CACHE_MAX_QUERY_LENGTH', 512),

        // 같은 경로·언어에 대해 저장하는 쿼리 변종 수 상한 (언어별로 따로 센다)
        'max_variants_per_path' => (int) env('G7_SEO_CACHE_MAX_VARIANTS_PER_PATH', 50),

        // 캐시 인덱스 전체 항목 수 상한
        'max_entries' => (int) env('G7_SEO_CACHE_MAX_ENTRIES', 20000),

        // IP 당 분당 미스 렌더 수 (초과 → SPA + X-SEO-Cache: BYPASS)
        'render_misses_per_minute' => (int) env('G7_SEO_RENDER_MISSES_PER_MINUTE', 60),

        // IP 당 분당 통계 기록 수 (통계 테이블이 새 증식 축이 되지 않도록)
        'stats_records_per_minute' => (int) env('G7_SEO_STATS_RECORDS_PER_MINUTE', 300),

        // 사이트 전체에서 동시에 진행하는 미스 렌더 수 (초과 → SPA + X-SEO-Cache: BYPASS).
        // IP 당 예산은 요청이 끝나야 차감이 보이므로 동시에 몰아친 요청을 막지 못한다.
        // 렌더 1건은 자기 워커 + 순차 내부 API 호출 1건으로 워커 2개를 잡으므로, PHP 워커 수
        // (php-fpm pm.max_children / Apache MaxRequestWorkers)의 절반보다 작게 둔다. 0 = 제한 없음.
        'max_concurrent_renders' => (int) env('G7_SEO_MAX_CONCURRENT_RENDERS', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO 데이터소스 호출
    |--------------------------------------------------------------------------
    | 봇 화면은 레이아웃의 데이터소스를 사이트 자신의 API 로 호출해 채웁니다. 호출 하나가
    | 느리면 그동안 워커 두 개가 묶이므로 짧게 끊고 해당 데이터소스는 fallback 을 씁니다.
    */
    'seo_datasource' => [
        // 데이터소스 호출 1건의 제한 시간 (초)
        'timeout' => (int) env('G7_SEO_DATASOURCE_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | 아웃바운드 프록시 연결 테스트
    |--------------------------------------------------------------------------
    | 운영자가 환경설정에 입력한 프록시가 실제로 동작하는지, 그리고 그 프록시를 거쳐
    | 나갔을 때 상대편에 어떤 IP 로 보이는지 확인하는 데 쓰는 조회 대상입니다.
    |
    | 출발지 IP 는 운영자가 결제사·외부 서비스에 등록해야 하는 값이라, 프록시를 켠 상태의
    | 실제 값을 알려주는 것이 이 테스트의 목적입니다. 목록은 순차 시도하며 먼저 유효한
    | IP 를 돌려준 곳에서 멈춥니다. 폐쇄망 등 외부 조회가 불가능한 환경에서는 목록을
    | 비워 두면 도달성만 확인하고 IP 는 보고하지 않습니다.
    */
    'outbound_proxy' => [
        'egress_lookup_urls' => [
            'https://api.ipify.org',
            'https://ifconfig.me/ip',
            'https://icanhazip.com',
        ],
        'test_timeout_seconds' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | 검색 — DBMS 별 부분일치 연산자
    |--------------------------------------------------------------------------
    | 전문검색을 제공하지 않는 DBMS 로 설치된 사이트에서는 부분일치(LIKE)가 정상 검색
    | 경로입니다. 그런데 "대소문자를 구분하지 않는 부분일치" 를 어떤 연산자로 쓰는지는
    | DBMS 마다 다릅니다 — 대부분 기본 비교가 구분하지 않아 `like` 로 충분하지만,
    | 그렇지 않은 DBMS 는 전용 연산자를 씁니다.
    |
    | 코어 코드에 드라이버명을 적지 않기 위해 이 표에 선언합니다. 새 DBMS 를 공식 지원할
    | 때는 여기에 한 줄을 더하면 되고, 확장은 `core.search.like_operators` 필터 훅으로
    | 조정합니다. 표에 없는 드라이버는 `like_operator_default` 를 씁니다.
    |
    | 키는 `DB::getDriverName()` 이 돌려주는 값입니다.
    */
    'search' => [
        'like_operators' => [
            'pgsql' => 'ilike',
        ],
        'like_operator_default' => 'like',
    ],

    /*
    |--------------------------------------------------------------------------
    | 스토리지 — 공개 자산 직접 URL 서빙 디스크
    |--------------------------------------------------------------------------
    | 완전 공개 자산(상품/카테고리/리뷰/에디터 이미지)의 직접 URL(CDN) 서빙에 쓸
    | 디스크입니다. 빈 문자열이면 미설정(기존 PHP 스트리밍 유지)이며, 값은 관리자
    | 환경설정(drivers.public_asset_disk)에서 SettingsServiceProvider 가 주입합니다.
    | 확장은 개별 설정으로 이 전역값을 오버라이드할 수 있습니다.
    */
    'storage' => [
        'public_asset_disk' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 권한 정의
    |--------------------------------------------------------------------------
    | RolePermissionSeeder 및 CoreUpdateService::syncCoreRolesAndPermissions()에서 사용
    | 새 권한 추가 시 이 배열에 추가하면 설치/업데이트 모두 반영됩니다.
    |
    | 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
    */
    'permissions' => [
        // 1레벨: 코어 모듈
        'module' => [
            'identifier' => 'core',
            'name' => ['ko' => '코어', 'en' => 'Core'],
            'description' => ['ko' => '코어 시스템 권한', 'en' => 'Core system permissions'],
            'order' => 1,
        ],

        // 2레벨: 카테고리들 + 3레벨: 개별 권한
        'categories' => [
            [
                'identifier' => 'core.users',
                'name' => ['ko' => '사용자 관리', 'en' => 'User Management'],
                'description' => ['ko' => '사용자 관리 권한', 'en' => 'User management permissions'],
                'category' => 'users',
                'order' => 100,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.users.read', 'type' => 'admin', 'name' => ['ko' => '사용자 조회', 'en' => 'View Users'], 'description' => ['ko' => '사용자 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view user list and details.'], 'order' => 1, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                    ['identifier' => 'core.users.create', 'type' => 'admin', 'name' => ['ko' => '사용자 생성', 'en' => 'Create Users'], 'description' => ['ko' => '새로운 사용자를 생성할 수 있습니다.', 'en' => 'Can create new users.'], 'order' => 2],
                    ['identifier' => 'core.users.update', 'type' => 'admin', 'name' => ['ko' => '사용자 수정', 'en' => 'Update Users'], 'description' => ['ko' => '사용자 정보를 수정할 수 있습니다.', 'en' => 'Can update user information.'], 'order' => 3, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                    ['identifier' => 'core.users.delete', 'type' => 'admin', 'name' => ['ko' => '사용자 삭제', 'en' => 'Delete Users'], 'description' => ['ko' => '사용자를 삭제할 수 있습니다.', 'en' => 'Can delete users.'], 'order' => 4, 'resource_route_key' => 'user', 'owner_key' => 'id'],
                ],
            ],
            [
                'identifier' => 'core.menus',
                'name' => ['ko' => '메뉴 관리', 'en' => 'Menu Management'],
                'description' => ['ko' => '메뉴 관리 권한', 'en' => 'Menu management permissions'],
                'category' => 'menus',
                'order' => 200,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.menus.read', 'type' => 'admin', 'name' => ['ko' => '메뉴 조회', 'en' => 'View Menus'], 'description' => ['ko' => '메뉴 목록을 조회할 수 있습니다.', 'en' => 'Can view menu list.'], 'order' => 1, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.menus.create', 'type' => 'admin', 'name' => ['ko' => '메뉴 생성', 'en' => 'Create Menus'], 'description' => ['ko' => '새로운 메뉴를 생성할 수 있습니다.', 'en' => 'Can create new menus.'], 'order' => 2],
                    ['identifier' => 'core.menus.update', 'type' => 'admin', 'name' => ['ko' => '메뉴 수정', 'en' => 'Update Menus'], 'description' => ['ko' => '메뉴 정보를 수정할 수 있습니다.', 'en' => 'Can update menu information.'], 'order' => 3, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.menus.delete', 'type' => 'admin', 'name' => ['ko' => '메뉴 삭제', 'en' => 'Delete Menus'], 'description' => ['ko' => '메뉴를 삭제할 수 있습니다.', 'en' => 'Can delete menus.'], 'order' => 4, 'resource_route_key' => 'menu', 'owner_key' => 'created_by'],
                ],
            ],
            [
                'identifier' => 'core.modules',
                'name' => ['ko' => '모듈 관리', 'en' => 'Module Management'],
                'description' => ['ko' => '모듈 관리 권한', 'en' => 'Module management permissions'],
                'category' => 'modules',
                'order' => 300,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.modules.read', 'type' => 'admin', 'name' => ['ko' => '모듈 조회', 'en' => 'View Modules'], 'description' => ['ko' => '모듈 목록을 조회할 수 있습니다.', 'en' => 'Can view module list.'], 'order' => 1],
                    ['identifier' => 'core.modules.install', 'type' => 'admin', 'name' => ['ko' => '모듈 설치', 'en' => 'Install Modules'], 'description' => ['ko' => '새로운 모듈을 설치할 수 있습니다.', 'en' => 'Can install new modules.'], 'order' => 2],
                    ['identifier' => 'core.modules.activate', 'type' => 'admin', 'name' => ['ko' => '모듈 활성화', 'en' => 'Activate Modules'], 'description' => ['ko' => '모듈을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate modules.'], 'order' => 3],
                    ['identifier' => 'core.modules.uninstall', 'type' => 'admin', 'name' => ['ko' => '모듈 삭제', 'en' => 'Uninstall Modules'], 'description' => ['ko' => '모듈을 삭제할 수 있습니다.', 'en' => 'Can uninstall modules.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.plugins',
                'name' => ['ko' => '플러그인 관리', 'en' => 'Plugin Management'],
                'description' => ['ko' => '플러그인 관리 권한', 'en' => 'Plugin management permissions'],
                'category' => 'plugins',
                'order' => 400,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.plugins.read', 'type' => 'admin', 'name' => ['ko' => '플러그인 조회', 'en' => 'View Plugins'], 'description' => ['ko' => '플러그인 목록을 조회할 수 있습니다.', 'en' => 'Can view plugin list.'], 'order' => 1],
                    ['identifier' => 'core.plugins.install', 'type' => 'admin', 'name' => ['ko' => '플러그인 설치', 'en' => 'Install Plugins'], 'description' => ['ko' => '새로운 플러그인을 설치할 수 있습니다.', 'en' => 'Can install new plugins.'], 'order' => 2],
                    ['identifier' => 'core.plugins.activate', 'type' => 'admin', 'name' => ['ko' => '플러그인 활성화', 'en' => 'Activate Plugins'], 'description' => ['ko' => '플러그인을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate plugins.'], 'order' => 3],
                    ['identifier' => 'core.plugins.update', 'type' => 'admin', 'name' => ['ko' => '플러그인 설정', 'en' => 'Configure Plugins'], 'description' => ['ko' => '플러그인 환경설정을 수정할 수 있습니다.', 'en' => 'Can update plugin settings.'], 'order' => 4],
                    ['identifier' => 'core.plugins.uninstall', 'type' => 'admin', 'name' => ['ko' => '플러그인 삭제', 'en' => 'Uninstall Plugins'], 'description' => ['ko' => '플러그인을 삭제할 수 있습니다.', 'en' => 'Can uninstall plugins.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.templates',
                'name' => ['ko' => '템플릿 관리', 'en' => 'Template Management'],
                'description' => ['ko' => '템플릿 관리 권한', 'en' => 'Template management permissions'],
                'category' => 'templates',
                'order' => 500,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.templates.read', 'type' => 'admin', 'name' => ['ko' => '템플릿 조회', 'en' => 'View Templates'], 'description' => ['ko' => '템플릿 목록을 조회할 수 있습니다.', 'en' => 'Can view template list.'], 'order' => 1],
                    ['identifier' => 'core.templates.install', 'type' => 'admin', 'name' => ['ko' => '템플릿 설치', 'en' => 'Install Templates'], 'description' => ['ko' => '새로운 템플릿을 설치할 수 있습니다.', 'en' => 'Can install new templates.'], 'order' => 2],
                    ['identifier' => 'core.templates.activate', 'type' => 'admin', 'name' => ['ko' => '템플릿 활성화', 'en' => 'Activate Templates'], 'description' => ['ko' => '템플릿을 활성화/비활성화할 수 있습니다.', 'en' => 'Can activate/deactivate templates.'], 'order' => 3],
                    ['identifier' => 'core.templates.uninstall', 'type' => 'admin', 'name' => ['ko' => '템플릿 삭제', 'en' => 'Uninstall Templates'], 'description' => ['ko' => '템플릿을 삭제할 수 있습니다.', 'en' => 'Can uninstall templates.'], 'order' => 4],
                    ['identifier' => 'core.templates.layouts.edit', 'type' => 'admin', 'name' => ['ko' => '레이아웃 편집', 'en' => 'Edit Layouts'], 'description' => ['ko' => '템플릿 레이아웃을 편집할 수 있습니다.', 'en' => 'Can edit template layouts.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.extensions',
                'name' => ['ko' => '확장 공통', 'en' => 'Extension Common'],
                'description' => ['ko' => '모듈·플러그인·템플릿에 공통으로 적용되는 권한', 'en' => 'Permissions that apply across modules, plugins and templates'],
                'category' => 'extensions',
                'order' => 550,
                'type' => 'admin',
                'permissions' => [
                    // 확장 타입을 가리지 않는 단일 권한이다. 타입별로 쪼개면 운영자가 셋을 모두
                    // 부여해야 하고, "모듈 CSS 는 되는데 템플릿 CSS 는 안 되는" 상태가 실질적
                    // 의미 없이 생긴다. 레이아웃 편집 권한과는 분리한다 — 여기서 올린 스크립트는
                    // 그 레이아웃 한 장이 아니라 사이트 전 화면에서 실행되므로, 레이아웃을 고칠
                    // 수 있다는 것이 곧 그 권한이 될 수 없다.
                    ['identifier' => 'core.extensions.custom_assets.manage', 'type' => 'admin', 'name' => ['ko' => '커스텀 자산 관리', 'en' => 'Manage Custom Assets'], 'description' => ['ko' => '모듈·플러그인·템플릿에 운영자 CSS·JS·폰트·이미지를 추가하거나 수정할 수 있습니다. 추가한 스크립트는 사이트 전체에서 실행되므로 레이아웃 편집과 별도로 부여합니다.', 'en' => 'Can add or edit operator CSS/JS/fonts/images on modules, plugins and templates. Added scripts run across the whole site, so this is granted separately from layout editing.'], 'order' => 1],
                ],
            ],
            [
                'identifier' => 'core.permissions',
                'name' => ['ko' => '권한 관리', 'en' => 'Permission Management'],
                'description' => ['ko' => '역할 및 권한 관리 권한', 'en' => 'Role and permission management permissions'],
                'category' => 'permissions',
                'order' => 600,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.permissions.read', 'type' => 'admin', 'name' => ['ko' => '권한 조회', 'en' => 'View Permissions'], 'description' => ['ko' => '역할 및 권한 목록을 조회할 수 있습니다.', 'en' => 'Can view roles and permissions.'], 'order' => 1],
                    ['identifier' => 'core.permissions.create', 'type' => 'admin', 'name' => ['ko' => '역할 생성', 'en' => 'Create Roles'], 'description' => ['ko' => '새로운 역할을 생성할 수 있습니다.', 'en' => 'Can create new roles.'], 'order' => 2],
                    ['identifier' => 'core.permissions.update', 'type' => 'admin', 'name' => ['ko' => '역할 수정', 'en' => 'Update Roles'], 'description' => ['ko' => '역할 정보와 권한을 수정할 수 있습니다.', 'en' => 'Can update role information and permissions.'], 'order' => 3],
                    ['identifier' => 'core.permissions.delete', 'type' => 'admin', 'name' => ['ko' => '역할 삭제', 'en' => 'Delete Roles'], 'description' => ['ko' => '역할을 삭제할 수 있습니다.', 'en' => 'Can delete roles.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.notification-logs',
                'name' => ['ko' => '알림 발송 이력', 'en' => 'Notification Logs'],
                'description' => ['ko' => '알림 발송 이력 관리 권한', 'en' => 'Notification log management permissions'],
                'category' => 'notification-logs',
                'order' => 700,
                'type' => 'admin',
                'permissions' => [
                    // resource_route_key·owner_key 를 두지 않는다 — 소유 컬럼이 발신자·수신자 둘이라
                    // 단일 owner_key 로 표현할 수 없다. 이 권한의 self·role 스코프는 라우트 미들웨어가
                    // 아니라 저장소 쿼리 스코프(NotificationLogRepository::applyNotificationLogScope)가
                    // 정본이며, 이 권한으로 열리는 조회 경로(목록·대시보드 최근 알림)는 전부 그 스코프를
                    // 거쳐야 한다(ScopedPermissionEnforcement::QUERY_SCOPED_PERMISSIONS).
                    ['identifier' => 'core.notification-logs.read', 'type' => 'admin', 'name' => ['ko' => '발송 이력 조회', 'en' => 'View Notification Logs'], 'description' => ['ko' => '알림 발송 이력을 조회할 수 있습니다.', 'en' => 'Can view notification logs.'], 'order' => 1],
                    ['identifier' => 'core.notification-logs.delete', 'type' => 'admin', 'name' => ['ko' => '발송 이력 삭제', 'en' => 'Delete Notification Logs'], 'description' => ['ko' => '알림 발송 이력을 삭제할 수 있습니다.', 'en' => 'Can delete notification logs.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.member-notifications',
                'name' => ['ko' => '회원 알림함', 'en' => 'Member Notifications'],
                'description' => ['ko' => '전 회원 in-app 알림함 조회·삭제 권한', 'en' => 'View and delete member in-app notifications'],
                'category' => 'member-notifications',
                'order' => 770,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.member-notifications.read', 'type' => 'admin', 'name' => ['ko' => '회원 알림함 조회', 'en' => 'View Member Notifications'], 'description' => ['ko' => '전 회원이 받은 알림을 조회할 수 있습니다.', 'en' => 'Can view notifications received by all members.'], 'order' => 1],
                    ['identifier' => 'core.member-notifications.delete', 'type' => 'admin', 'name' => ['ko' => '회원 알림함 삭제', 'en' => 'Delete Member Notifications'], 'description' => ['ko' => '회원이 받은 알림을 삭제할 수 있습니다.', 'en' => 'Can delete notifications received by members.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.notifications',
                'name' => ['ko' => '알림 (관리자)', 'en' => 'Notifications (Admin)'],
                'description' => ['ko' => '관리자용 알림 관리 권한 (관리자 화면에서 사용)', 'en' => 'Admin notification management permissions (used in admin UI)'],
                'category' => 'notifications',
                'order' => 750,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.notifications.read', 'type' => 'admin', 'name' => ['ko' => '알림 조회', 'en' => 'View Notifications'], 'description' => ['ko' => '알림 목록 및 읽지 않은 수를 조회할 수 있습니다.', 'en' => 'Can view notification list and unread count.'], 'order' => 1],
                    ['identifier' => 'core.notifications.update', 'type' => 'admin', 'name' => ['ko' => '알림 읽음 처리', 'en' => 'Mark Notifications Read'], 'description' => ['ko' => '알림을 읽음 처리할 수 있습니다.', 'en' => 'Can mark notifications as read.'], 'order' => 2],
                    ['identifier' => 'core.notifications.delete', 'type' => 'admin', 'name' => ['ko' => '알림 삭제', 'en' => 'Delete Notifications'], 'description' => ['ko' => '알림을 삭제할 수 있습니다.', 'en' => 'Can delete notifications.'], 'order' => 3],
                ],
            ],
            [
                'identifier' => 'core.user-notifications',
                'name' => ['ko' => '알림 (사용자)', 'en' => 'Notifications (User)'],
                'description' => ['ko' => '사용자용 알림 권한 (사용자 화면에서 본인 알림 관리)', 'en' => 'User notification permissions (managing own notifications in user UI)'],
                'category' => 'user-notifications',
                'order' => 760,
                'type' => 'user',
                'permissions' => [
                    ['identifier' => 'core.user-notifications.read', 'type' => 'user', 'name' => ['ko' => '알림 조회', 'en' => 'View Notifications'], 'description' => ['ko' => '본인의 알림 목록 및 읽지 않은 수를 조회할 수 있습니다.', 'en' => 'Can view own notification list and unread count.'], 'order' => 1],
                    ['identifier' => 'core.user-notifications.update', 'type' => 'user', 'name' => ['ko' => '알림 읽음 처리', 'en' => 'Mark Notifications Read'], 'description' => ['ko' => '본인의 알림을 읽음 처리할 수 있습니다.', 'en' => 'Can mark own notifications as read.'], 'order' => 2],
                    ['identifier' => 'core.user-notifications.delete', 'type' => 'user', 'name' => ['ko' => '알림 삭제', 'en' => 'Delete Notifications'], 'description' => ['ko' => '본인의 알림을 삭제할 수 있습니다.', 'en' => 'Can delete own notifications.'], 'order' => 3],
                ],
            ],
            // 사용자 API 의 본인 프로필(`/api/user/profile`·활동 기록)이 요구하는 권한. 라우트만 이 식별자를
            // 걸고 정의가 없어 모든 회원이 403 이던 것을 정의로 채웠다(7.1.0 실측 A O-2). 비활성 상태 회원의
            // 본인 정보 조회(`GET /api/me`·`GET /api/auth/user`)도 이 읽기 권한을 기준으로 허용한다(CheckUserStatus).
            [
                'identifier' => 'core.profile',
                'name' => ['ko' => '내 정보 (사용자)', 'en' => 'My Profile (User)'],
                'description' => ['ko' => '사용자 화면에서 본인 정보를 조회·수정하는 권한', 'en' => 'Permissions to view and edit own profile in the user UI'],
                'category' => 'profile',
                'order' => 765,
                'type' => 'user',
                'permissions' => [
                    ['identifier' => 'core.profile.read', 'type' => 'user', 'name' => ['ko' => '내 정보 조회', 'en' => 'View My Profile'], 'description' => ['ko' => '본인 정보와 활동 기록을 조회할 수 있습니다. 계정이 비활성·차단 등 정상 상태가 아니어도 본인 정보 조회는 이 권한으로 허용됩니다.', 'en' => 'Can view own profile and activity history. Viewing own profile is allowed by this permission even when the account is not active (inactive, blocked, etc.).'], 'order' => 1],
                    ['identifier' => 'core.profile.update', 'type' => 'user', 'name' => ['ko' => '내 정보 수정', 'en' => 'Edit My Profile'], 'description' => ['ko' => '본인 정보를 수정할 수 있습니다.', 'en' => 'Can edit own profile.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.identity',
                'name' => ['ko' => '본인인증 (사용자)', 'en' => 'Identity Verification (User)'],
                'description' => ['ko' => '로그인 사용자의 본인인증 challenge 요청/검증/취소 권한', 'en' => 'Permissions for authenticated users to request/verify/cancel IDV challenges'],
                'category' => 'identity',
                'order' => 770,
                'type' => 'user',
                'permissions' => [
                    ['identifier' => 'core.identity.request', 'type' => 'user', 'name' => ['ko' => 'IDV 요청', 'en' => 'Request IDV Challenge'], 'description' => ['ko' => '본인인증 challenge 를 요청할 수 있습니다.', 'en' => 'Can request identity verification challenges.'], 'order' => 1],
                    ['identifier' => 'core.identity.verify', 'type' => 'user', 'name' => ['ko' => 'IDV 검증', 'en' => 'Verify IDV Challenge'], 'description' => ['ko' => '본인의 본인인증 challenge 를 검증할 수 있습니다.', 'en' => 'Can verify own identity verification challenges.'], 'order' => 2, 'resource_route_key' => 'challenge', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.identity.cancel', 'type' => 'user', 'name' => ['ko' => 'IDV 취소', 'en' => 'Cancel IDV Challenge'], 'description' => ['ko' => '본인의 본인인증 challenge 를 취소할 수 있습니다.', 'en' => 'Can cancel own identity verification challenges.'], 'order' => 3, 'resource_route_key' => 'challenge', 'owner_key' => 'user_id'],
                ],
            ],
            [
                'identifier' => 'core.admin.identity',
                'name' => ['ko' => '본인인증 관리 (관리자)', 'en' => 'Identity Verification Management (Admin)'],
                'description' => ['ko' => '관리자용 IDV 프로바이더/정책/로그 관리 권한', 'en' => 'Admin permissions for IDV providers, policies, and logs'],
                'category' => 'identity',
                'order' => 780,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.admin.identity.providers.read', 'type' => 'admin', 'name' => ['ko' => '프로바이더 설정 조회', 'en' => 'View IDV Providers'], 'description' => ['ko' => '본인인증 프로바이더 설정을 조회할 수 있습니다.', 'en' => 'Can view identity verification providers.'], 'order' => 1],
                    ['identifier' => 'core.admin.identity.providers.update', 'type' => 'admin', 'name' => ['ko' => '프로바이더 설정 수정', 'en' => 'Update IDV Providers'], 'description' => ['ko' => '본인인증 프로바이더 설정을 수정할 수 있습니다.', 'en' => 'Can update identity verification providers.'], 'order' => 2],
                    ['identifier' => 'core.admin.identity.policies.read', 'type' => 'admin', 'name' => ['ko' => '정책 조회', 'en' => 'View IDV Policies'], 'description' => ['ko' => '본인인증 정책(라우트/훅별)을 조회할 수 있습니다.', 'en' => 'Can view identity verification policies by route/hook.'], 'order' => 3],
                    ['identifier' => 'core.admin.identity.policies.update', 'type' => 'admin', 'name' => ['ko' => '정책 수정', 'en' => 'Update IDV Policies'], 'description' => ['ko' => '본인인증 정책(라우트/훅별)을 CRUD 할 수 있습니다.', 'en' => 'Can CRUD identity verification policies by route/hook.'], 'order' => 4],
                    ['identifier' => 'core.admin.identity.logs.read', 'type' => 'admin', 'name' => ['ko' => '로그 열람', 'en' => 'View IDV Logs'], 'description' => ['ko' => '본인인증 이력을 조회할 수 있습니다.', 'en' => 'Can view identity verification logs.'], 'order' => 5],
                    ['identifier' => 'core.admin.identity.logs.purge', 'type' => 'admin', 'name' => ['ko' => '로그 파기', 'en' => 'Purge IDV Logs'], 'description' => ['ko' => '본인인증 이력을 파기(보관주기 외)할 수 있습니다.', 'en' => 'Can purge identity verification logs (retention-based).'], 'order' => 6],
                ],
            ],
            [
                'identifier' => 'core.settings',
                'name' => ['ko' => '환경설정', 'en' => 'Settings'],
                'description' => ['ko' => '시스템 환경설정 권한', 'en' => 'System settings permissions'],
                'category' => 'settings',
                'order' => 800,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.settings.read', 'type' => 'admin', 'name' => ['ko' => '설정 조회', 'en' => 'View Settings'], 'description' => ['ko' => '시스템 설정을 조회할 수 있습니다.', 'en' => 'Can view system settings.'], 'order' => 1],
                    ['identifier' => 'core.settings.update', 'type' => 'admin', 'name' => ['ko' => '설정 수정', 'en' => 'Update Settings'], 'description' => ['ko' => '시스템 설정을 수정할 수 있습니다.', 'en' => 'Can update system settings.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.dashboard',
                'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
                'description' => ['ko' => '대시보드 접근 권한', 'en' => 'Dashboard access permissions'],
                'category' => 'dashboard',
                'order' => 900,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.dashboard.read', 'type' => 'admin', 'name' => ['ko' => '대시보드 조회', 'en' => 'View Dashboard'], 'description' => ['ko' => '대시보드 통계 및 정보를 조회할 수 있습니다.', 'en' => 'Can view dashboard statistics and information.'], 'order' => 1],
                    ['identifier' => 'core.dashboard.system-status', 'type' => 'admin', 'name' => ['ko' => '시스템 상태', 'en' => 'System Status'], 'description' => ['ko' => '시스템 상태 정보를 조회할 수 있습니다.', 'en' => 'Can view system status information.'], 'order' => 2],
                    ['identifier' => 'core.dashboard.resources', 'type' => 'admin', 'name' => ['ko' => '시스템 리소스', 'en' => 'System Resources'], 'description' => ['ko' => 'CPU, 메모리, 디스크 사용량을 조회할 수 있습니다.', 'en' => 'Can view CPU, memory, and disk usage.'], 'order' => 3],
                    ['identifier' => 'core.dashboard.activities', 'type' => 'admin', 'name' => ['ko' => '최근 활동', 'en' => 'Recent Activities'], 'description' => ['ko' => '최근 활동 이력을 조회할 수 있습니다.', 'en' => 'Can view recent activity history.'], 'order' => 4, 'resource_route_key' => 'activityLog', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.dashboard.alerts', 'type' => 'admin', 'name' => ['ko' => '시스템 알림', 'en' => 'System Alerts'], 'description' => ['ko' => '시스템 알림을 조회할 수 있습니다.', 'en' => 'Can view system alerts.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.activities',
                'name' => ['ko' => '활동 로그', 'en' => 'Activity Logs'],
                'description' => ['ko' => '활동 로그 조회 권한', 'en' => 'Activity log access permissions'],
                'category' => 'activities',
                'order' => 1000,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.activities.read', 'type' => 'admin', 'name' => ['ko' => '활동 로그 조회', 'en' => 'View Activity Logs'], 'description' => ['ko' => '활동 로그를 조회할 수 있습니다.', 'en' => 'Can view activity logs.'], 'order' => 1, 'resource_route_key' => 'activityLog', 'owner_key' => 'user_id'],
                    ['identifier' => 'core.activities.delete', 'type' => 'admin', 'name' => ['ko' => '활동 로그 삭제', 'en' => 'Delete Activity Logs'], 'description' => ['ko' => '활동 로그를 삭제할 수 있습니다.', 'en' => 'Can delete activity logs.'], 'order' => 2],
                ],
            ],
            [
                'identifier' => 'core.attachments',
                'name' => ['ko' => '첨부파일 관리', 'en' => 'Attachment Management'],
                'description' => ['ko' => '첨부파일 관리 권한', 'en' => 'Attachment management permissions'],
                'category' => 'attachments',
                'order' => 1100,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.attachments.create', 'type' => 'admin', 'name' => ['ko' => '첨부파일 업로드', 'en' => 'Upload Attachments'], 'description' => ['ko' => '첨부파일을 업로드할 수 있습니다.', 'en' => 'Can upload attachments.'], 'order' => 1],
                    ['identifier' => 'core.attachments.update', 'type' => 'admin', 'name' => ['ko' => '첨부파일 수정', 'en' => 'Update Attachments'], 'description' => ['ko' => '첨부파일 정보 및 순서를 수정할 수 있습니다.', 'en' => 'Can update attachment information and order.'], 'order' => 2, 'resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
                    ['identifier' => 'core.attachments.delete', 'type' => 'admin', 'name' => ['ko' => '첨부파일 삭제', 'en' => 'Delete Attachments'], 'description' => ['ko' => '첨부파일을 삭제할 수 있습니다.', 'en' => 'Can delete attachments.'], 'order' => 3, 'resource_route_key' => 'attachment', 'owner_key' => 'created_by'],
                ],
            ],
            [
                'identifier' => 'core.schedules',
                'name' => ['ko' => '스케줄 관리', 'en' => 'Schedule Management'],
                'description' => ['ko' => '스케줄 작업 관리 권한', 'en' => 'Schedule task management permissions'],
                'category' => 'schedules',
                'order' => 1200,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.schedules.read', 'type' => 'admin', 'name' => ['ko' => '스케줄 조회', 'en' => 'View Schedules'], 'description' => ['ko' => '스케줄 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view schedule list and details.'], 'order' => 1],
                    ['identifier' => 'core.schedules.create', 'type' => 'admin', 'name' => ['ko' => '스케줄 생성', 'en' => 'Create Schedules'], 'description' => ['ko' => '새로운 스케줄을 생성할 수 있습니다.', 'en' => 'Can create new schedules.'], 'order' => 2],
                    ['identifier' => 'core.schedules.update', 'type' => 'admin', 'name' => ['ko' => '스케줄 수정', 'en' => 'Update Schedules'], 'description' => ['ko' => '스케줄 정보를 수정할 수 있습니다.', 'en' => 'Can update schedule information.'], 'order' => 3],
                    ['identifier' => 'core.schedules.delete', 'type' => 'admin', 'name' => ['ko' => '스케줄 삭제', 'en' => 'Delete Schedules'], 'description' => ['ko' => '스케줄을 삭제할 수 있습니다.', 'en' => 'Can delete schedules.'], 'order' => 4],
                    ['identifier' => 'core.schedules.run', 'type' => 'admin', 'name' => ['ko' => '스케줄 실행', 'en' => 'Run Schedules'], 'description' => ['ko' => '스케줄을 수동으로 실행할 수 있습니다.', 'en' => 'Can manually run schedules.'], 'order' => 5],
                ],
            ],
            [
                'identifier' => 'core.language_packs',
                'name' => ['ko' => '언어팩 관리', 'en' => 'Language Pack Management'],
                'description' => ['ko' => '언어팩 설치/제거/활성화 권한', 'en' => 'Language pack install/uninstall/activation permissions'],
                'category' => 'language_packs',
                'order' => 1300,
                'type' => 'admin',
                'permissions' => [
                    ['identifier' => 'core.language_packs.read', 'type' => 'admin', 'name' => ['ko' => '언어팩 조회', 'en' => 'View Language Packs'], 'description' => ['ko' => '설치된 언어팩 목록 및 상세 정보를 조회할 수 있습니다.', 'en' => 'Can view installed language pack list and details.'], 'order' => 1],
                    ['identifier' => 'core.language_packs.install', 'type' => 'admin', 'name' => ['ko' => '언어팩 설치', 'en' => 'Install Language Packs'], 'description' => ['ko' => 'ZIP/GitHub/URL 로 언어팩을 설치할 수 있습니다.', 'en' => 'Can install language packs from ZIP/GitHub/URL.'], 'order' => 2],
                    ['identifier' => 'core.language_packs.manage', 'type' => 'admin', 'name' => ['ko' => '언어팩 관리', 'en' => 'Manage Language Packs'], 'description' => ['ko' => '언어팩을 활성화/비활성화/제거할 수 있습니다.', 'en' => 'Can activate/deactivate/uninstall language packs.'], 'order' => 3],
                    ['identifier' => 'core.language_packs.update', 'type' => 'admin', 'name' => ['ko' => '언어팩 업데이트', 'en' => 'Update Language Packs'], 'description' => ['ko' => '설치된 언어팩의 업데이트를 확인하고 실행할 수 있습니다.', 'en' => 'Can check and apply updates for installed language packs.'], 'order' => 4],
                ],
            ],
            [
                'identifier' => 'core.analytics',
                'name' => ['ko' => '방문자 통계', 'en' => 'Analytics'],
                'description' => ['ko' => '방문자 통계 권한', 'en' => 'Analytics permissions'],
                'category' => 'analytics',
                'order' => 1400,
                'type' => 'admin',
                // 조회(집계 숫자) · 설정(수집 방식·삭제) · 개인정보(방문자 원장) 세 단계로 나눈다.
                // 통계를 보는 것과 개인 방문 기록을 들여다보는 것은 다른 권한이어야 한다.
                'permissions' => [
                    ['identifier' => 'core.analytics.read', 'type' => 'admin', 'name' => ['ko' => '방문자 통계 조회', 'en' => 'View Analytics'], 'description' => ['ko' => '개요·리포트·패턴·실시간·봇·진단 통계와 CSV 내보내기를 조회할 수 있습니다. 개별 방문자의 IP 는 보이지 않습니다.', 'en' => 'Can view overview, reports, patterns, realtime, bot and diagnostics statistics and export CSV. Individual visitor IPs stay hidden.'], 'order' => 1],
                    ['identifier' => 'core.analytics.update', 'type' => 'admin', 'name' => ['ko' => '방문자 통계 설정', 'en' => 'Manage Analytics'], 'description' => ['ko' => '수집 설정 저장, 수동 집계·재집계, 통계 데이터 삭제, 소급 익명화를 할 수 있습니다.', 'en' => 'Can save collection settings, run manual or re-aggregation, delete statistics data and anonymize retroactively.'], 'order' => 2],
                    ['identifier' => 'core.analytics.privacy', 'type' => 'admin', 'name' => ['ko' => '방문자 개인정보 열람', 'en' => 'Access Visitor Data'], 'description' => ['ko' => '방문자 프로필과 액션 타임라인, 실시간 IP 원값, 정보주체 검색·내보내기·삭제를 할 수 있습니다.', 'en' => 'Can view visitor profiles and action timelines, raw realtime IPs, and search, export or delete data subject records.'], 'order' => 3],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 역할 정의
    |--------------------------------------------------------------------------
    | RolePermissionSeeder 및 CoreUpdateService::syncCoreRolesAndPermissions()에서 사용
    */
    'roles' => [
        [
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '시스템의 모든 기능에 접근할 수 있는 최고 관리자입니다.', 'en' => 'Super administrator with access to all system features.'],
            'attributes' => ['is_active' => true],
            'permissions' => 'all_leaf', // 모든 리프 권한 할당
        ],
        [
            'identifier' => 'manager',
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
            'description' => ['ko' => '콘텐츠 및 사용자 관리 권한을 가진 관리자입니다.', 'en' => 'Manager with content and user management permissions.'],
            'attributes' => ['is_active' => true],
            'permissions' => [
                'core.users.read', 'core.users.create', 'core.users.update',
                'core.menus.read', 'core.menus.create', 'core.menus.update',
                'core.dashboard.read',
                'core.dashboard.system-status',
                'core.dashboard.activities',
                'core.activities.read',
                'core.attachments.create', 'core.attachments.update', 'core.attachments.delete',
                'core.notification-logs.read',
                // 스코프를 두지 않는다 — 이 권한의 대상은 «회원이 받은» 알림이라
                // self 로 좁히면 매니저 본인의 수신함이 되어 버려 화면의 의미가 사라진다.
                'core.member-notifications.read',
            ],
            // 권한별 스코프 지정 (self: 본인 소유만, role: 같은 역할 범위, 미지정: 전체)
            'permission_scopes' => [
                'core.users.read' => 'self',
                'core.users.update' => 'self',
                'core.activities.read' => 'self',
                'core.attachments.update' => 'self',
                'core.attachments.delete' => 'self',
                'core.notification-logs.read' => 'self',
            ],
        ],
        [
            'identifier' => 'user',
            'name' => ['ko' => '일반 사용자', 'en' => 'User'],
            'description' => ['ko' => '기본 사용자 역할입니다.', 'en' => 'Default user role.'],
            'attributes' => ['is_active' => true],
            'permissions' => [
                'core.user-notifications.read', 'core.user-notifications.update', 'core.user-notifications.delete',
                'core.profile.read', 'core.profile.update',
                'core.identity.request', 'core.identity.verify', 'core.identity.cancel',
            ],
            // verify/cancel 은 본인 challenge(user_id 일치) 만 — PermissionMiddleware 의 scope=self 가드가 자동 검증
            'permission_scopes' => [
                'core.identity.verify' => 'self',
                'core.identity.cancel' => 'self',
            ],
        ],
        [
            'identifier' => 'guest',
            'name' => ['ko' => '비회원', 'en' => 'Guest'],
            'description' => [
                'ko' => '인증되지 않은 사용자 역할입니다. 관리자가 권한을 부여할 수 있습니다.',
                'en' => 'Unauthenticated user role. Permissions can be granted by administrators.',
            ],
            'attributes' => ['is_active' => true],
            // 비로그인 가입(Mode B) · 비밀번호 재설정 등 IDV 진입에 필요 — PermissionMiddleware 가 비인증 시 scope 검사를 자동 스킵하므로 owner 가드는 throttle + UUID 추정 어려움에 의존
            'permissions' => [
                'core.identity.request', 'core.identity.verify', 'core.identity.cancel',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 메뉴 정의
    |--------------------------------------------------------------------------
    | CoreAdminMenuSeeder 및 CoreUpdateService::syncCoreMenus()에서 사용
    */
    'menus' => [
        [
            'slug' => 'admin-dashboard',
            'name' => ['ko' => '대시보드', 'en' => 'Dashboard'],
            'url' => '/admin/dashboard',
            'icon' => 'fas fa-tachometer-alt',
            'parent_id' => null,
            'order' => 1,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-settings',
            'name' => ['ko' => '환경설정', 'en' => 'Settings'],
            'url' => '/admin/settings',
            'icon' => 'fas fa-cog',
            'parent_id' => null,
            'order' => 3,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-notification-logs',
            'name' => ['ko' => '알림 발송 이력', 'en' => 'Notification Logs'],
            'url' => '/admin/notification-logs',
            'icon' => 'fas fa-bell',
            'parent_id' => null,
            'order' => 4,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-member-notifications',
            'name' => ['ko' => '회원 알림함', 'en' => 'Member Notifications'],
            'url' => '/admin/member-notifications',
            'icon' => 'fas fa-inbox',
            'parent_id' => null,
            'order' => 5,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-identity-logs',
            'name' => ['ko' => '본인인증 이력', 'en' => 'Identity Logs'],
            'url' => '/admin/identity/logs',
            'icon' => 'fas fa-clipboard-check',
            'parent_id' => null,
            'order' => 6,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-activity-logs',
            'name' => ['ko' => '활동 로그', 'en' => 'Activity Logs'],
            'url' => '/admin/activity-logs',
            'icon' => 'fas fa-history',
            'parent_id' => null,
            'order' => 7,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-menus',
            'name' => ['ko' => '메뉴 관리', 'en' => 'Menu Management'],
            'url' => '/admin/menus',
            'icon' => 'fas fa-bars',
            'parent_id' => null,
            'order' => 8,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-users',
            'name' => ['ko' => '사용자 관리', 'en' => 'User Management'],
            'url' => '/admin/users',
            'icon' => 'fas fa-users',
            'parent_id' => null,
            'order' => 9,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-roles',
            'name' => ['ko' => '권한 관리', 'en' => 'Permission Management'],
            'url' => '/admin/roles',
            'icon' => 'fas fa-lock',
            'parent_id' => null,
            'order' => 10,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-modules',
            'name' => ['ko' => '모듈 관리', 'en' => 'Module Management'],
            'url' => '/admin/modules',
            'icon' => 'fas fa-cube',
            'parent_id' => null,
            'order' => 11,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-plugins',
            'name' => ['ko' => '플러그인 관리', 'en' => 'Plugin Management'],
            'url' => '/admin/plugins',
            'icon' => 'fas fa-puzzle-piece',
            'parent_id' => null,
            'order' => 12,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-templates',
            'name' => ['ko' => '템플릿 관리', 'en' => 'Template Management'],
            'url' => '/admin/templates',
            'icon' => 'fas fa-palette',
            'parent_id' => null,
            'order' => 13,
            'is_active' => true,
        ],
        [
            'slug' => 'admin-schedules',
            'name' => ['ko' => '스케쥴 관리', 'en' => 'Schedule Management'],
            'url' => '/admin/schedules',
            'icon' => 'fas fa-clock',
            'parent_id' => null,
            'order' => 14,
            'is_active' => false,
        ],
        // 방문자 통계 — 화면이 13개라 대시보드 바로 아래에 부모 하나로 묶는다.
        // children 은 ExtensionMenuSyncHelper::syncMenuRecursive 와 CoreAdminMenuSeeder 가
        // 부모를 먼저 만들고 그 id 를 parent_id 로 넣어 준다.
        [
            'slug' => 'admin-analytics',
            'name' => ['ko' => '방문자 통계', 'en' => 'Analytics'],
            'url' => '/admin/analytics',
            'icon' => 'fas fa-chart-line',
            'parent_id' => null,
            'order' => 2,
            'is_active' => true,
            'children' => [
                [
                    'slug' => 'admin-analytics-overview',
                    'name' => ['ko' => '개요', 'en' => 'Overview'],
                    'url' => '/admin/analytics',
                    'icon' => 'fas fa-chart-pie',
                    'order' => 1,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-pages',
                    'name' => ['ko' => '페이지', 'en' => 'Pages'],
                    'url' => '/admin/analytics/pages',
                    'icon' => 'fas fa-file-alt',
                    'order' => 2,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-referrers',
                    'name' => ['ko' => '유입 경로', 'en' => 'Referrers'],
                    'url' => '/admin/analytics/referrers',
                    'icon' => 'fas fa-sign-in-alt',
                    'order' => 3,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-environment',
                    'name' => ['ko' => '환경', 'en' => 'Environment'],
                    'url' => '/admin/analytics/environment',
                    'icon' => 'fas fa-desktop',
                    'order' => 4,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-regions',
                    'name' => ['ko' => '지역', 'en' => 'Regions'],
                    'url' => '/admin/analytics/regions',
                    'icon' => 'fas fa-globe',
                    'order' => 5,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-patterns',
                    'name' => ['ko' => '요일·시간대', 'en' => 'Patterns'],
                    'url' => '/admin/analytics/patterns',
                    'icon' => 'fas fa-calendar-week',
                    'order' => 6,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-actions',
                    'name' => ['ko' => '검색어·다운로드·전환', 'en' => 'Actions'],
                    'url' => '/admin/analytics/actions',
                    'icon' => 'fas fa-bullseye',
                    'order' => 7,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-realtime',
                    'name' => ['ko' => '실시간', 'en' => 'Realtime'],
                    'url' => '/admin/analytics/realtime',
                    'icon' => 'fas fa-bolt',
                    'order' => 8,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-visitors',
                    'name' => ['ko' => '방문자', 'en' => 'Visitors'],
                    'url' => '/admin/analytics/visitors',
                    'icon' => 'fas fa-user-friends',
                    'order' => 9,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-bots',
                    'name' => ['ko' => '봇·크롤러', 'en' => 'Bots'],
                    'url' => '/admin/analytics/bots',
                    'icon' => 'fas fa-robot',
                    'order' => 10,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-diagnostics',
                    'name' => ['ko' => '수집 진단', 'en' => 'Diagnostics'],
                    'url' => '/admin/analytics/diagnostics',
                    'icon' => 'fas fa-stethoscope',
                    'order' => 11,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-privacy',
                    'name' => ['ko' => '정보주체 요청', 'en' => 'Data Requests'],
                    'url' => '/admin/analytics/privacy-requests',
                    'icon' => 'fas fa-user-shield',
                    'order' => 12,
                    'is_active' => true,
                ],
                [
                    'slug' => 'admin-analytics-settings',
                    'name' => ['ko' => '설정', 'en' => 'Settings'],
                    'url' => '/admin/analytics/settings',
                    'icon' => 'fas fa-sliders-h',
                    'order' => 13,
                    'is_active' => true,
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 알림 정의
    |--------------------------------------------------------------------------
    | NotificationDefinitionSeeder 와 NotificationTemplateService 가 SSoT 로 사용합니다.
    | NotificationSyncHelper 가 upsert 하며, 운영자가 관리자 UI 에서 수정한 필드는
    | user_overrides JSON 에 보존되어 재시딩 시 덮어써지지 않습니다.
    | 모듈/플러그인 자체 알림은 각자의 AbstractModule/AbstractPlugin::getNotificationDefinitions()
    | 에서 선언하며 ModuleManager/PluginManager 가 자동 동기화합니다.
    */
    'notification_definitions' => [
        'welcome' => [
            'hook_prefix' => 'core.auth',
            'audience' => 'customer',
            'name' => ['ko' => '회원가입 환영', 'en' => 'Welcome'],
            'description' => ['ko' => '회원가입 완료 시 발송되는 환영 알림', 'en' => 'Welcome notification sent upon registration'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_register'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 회원가입을 환영합니다',
                        'en' => '[{app_name}] Welcome to Our Service',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 환영합니다!</h1>'
                            .'<p>{app_name}에 가입해 주셔서 감사합니다.</p>'
                            .'<p>이제 모든 서비스를 이용하실 수 있습니다. 아래 버튼을 클릭하여 로그인해 주세요.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Welcome, {name}!</h1>'
                            .'<p>Thank you for joining {app_name}.</p>'
                            .'<p>You now have access to all our services. Click the button below to log in.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Log In</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '회원가입을 환영합니다', 'en' => 'Welcome to our service'],
                    'body' => ['ko' => '{name}님, {app_name}에 가입해 주셔서 감사합니다.', 'en' => 'Welcome {name}, thank you for joining {app_name}.'],
                    'click_url' => '/mypage',
                ],
            ],
        ],
        'reset_password' => [
            'hook_prefix' => 'core.auth',
            'audience' => 'customer',
            'name' => ['ko' => '비밀번호 재설정', 'en' => 'Password Reset'],
            'description' => ['ko' => '비밀번호 재설정 요청 시 발송되는 알림', 'en' => 'Notification sent when password reset is requested'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_reset_password_request'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '비밀번호 재설정 URL'],
                ['key' => 'expire_minutes', 'description' => '링크 만료 시간(분)'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호 재설정 안내',
                        'en' => '[{app_name}] Password Reset Request',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>비밀번호 재설정 요청을 받았습니다. 아래 버튼을 클릭하여 비밀번호를 재설정해 주세요.</p>'
                            .'<p>이 링크는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">비밀번호 재설정</a></td></tr></table>'
                            .'<p>비밀번호 재설정을 요청하지 않으셨다면, 이 이메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>We received a request to reset your password. Click the button below to set a new password.</p>'
                            .'<p>This link will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Reset Password</a></td></tr></table>'
                            .'<p>If you did not request a password reset, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호 재설정 안내', 'en' => 'Password Reset Request'],
                    'body' => ['ko' => '{name}님, 비밀번호 재설정이 요청되었습니다. 본인이 요청하지 않았다면 이 알림을 무시해 주세요.', 'en' => '{name}, a password reset has been requested. If you did not request this, please ignore this notification.'],
                    'click_url' => '{action_url}',
                ],
            ],
        ],
        'password_changed' => [
            'hook_prefix' => 'core.auth',
            'audience' => 'customer',
            'name' => ['ko' => '비밀번호 변경', 'en' => 'Password Changed'],
            'description' => ['ko' => '비밀번호 변경 완료 시 발송되는 알림', 'en' => 'Notification sent when password is changed'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.after_password_changed'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호가 변경되었습니다',
                        'en' => '[{app_name}] Your Password Has Been Changed',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>계정의 비밀번호가 성공적으로 변경되었습니다.</p>'
                            .'<p><strong>본인이 변경하지 않았다면, 즉시 고객 지원팀에 문의하시기 바랍니다.</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your account password has been successfully changed.</p>'
                            .'<p><strong>If you did not make this change, please contact our support team immediately.</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Log In</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호가 변경되었습니다', 'en' => 'Your password has been changed'],
                    'body' => ['ko' => '{name}님, 비밀번호가 변경되었습니다. 본인이 변경하지 않았다면 즉시 고객 지원팀에 문의하시기 바랍니다.', 'en' => '{name}, your password has been changed. If you did not make this change, please contact support immediately.'],
                    'click_url' => '/mypage/change-password',
                ],
            ],
        ],
        // 비밀번호 만료 사전 안내 (#736) — `auth:process-password-expiry` 가 만료 N일 전 주기마다 1통 보낸다.
        // action_url 은 수신자에 따라 다르다(관리자 = 회원 수정 화면, 회원 = 마이페이지 비밀번호 변경).
        'password_expiry_notice' => [
            'hook_prefix' => 'core.auth',
            'audience' => 'customer',
            'name' => ['ko' => '비밀번호 만료 사전 안내', 'en' => 'Password Expiry Advance Notice'],
            'description' => ['ko' => '비밀번호 사용 기간이 곧 끝나는 회원에게 미리 보내는 안내', 'en' => 'Notice sent before a password reaches the end of its validity period'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.password_expiry_notice'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '비밀번호 변경 화면 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'expires_at', 'description' => '비밀번호 만료일'],
                ['key' => 'days_left', 'description' => '만료까지 남은 일수'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호 만료 {days_left}일 전 안내',
                        'en' => '[{app_name}] Your password expires in {days_left} days',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>회원님의 비밀번호 사용 기간이 <strong>{expires_at}</strong>에 끝납니다.</p>'
                            .'<p>만료 전에 변경해 주세요. 만료 후에는 로그인할 때 새 비밀번호를 설정해야 합니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">비밀번호 변경하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your password expires on <strong>{expires_at}</strong>.</p>'
                            .'<p>Please change it before then. After it expires, you will be asked to set a new password when you sign in.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Change password</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호 만료 {days_left}일 전입니다', 'en' => 'Your password expires in {days_left} days'],
                    'body' => [
                        // 받는 회원 이름으로 시작한다(2026-10-04 결정, 디자인 캔버스 사이트 알림)
                        'ko' => '{name}님, 비밀번호가 {expires_at}에 만료됩니다. 만료 전에 변경해 주세요.',
                        'en' => '{name}, your password expires on {expires_at}. Please change it before then.',
                    ],
                    'click_url' => '{action_url}',
                ],
            ],
        ],
        // 비밀번호 만료 (#736) — 만료일 처리에서 1통. '변경해야 로그인' 모드면 같은 처리에서 로그인 토큰도 폐기된다.
        'password_expired' => [
            'hook_prefix' => 'core.auth',
            'audience' => 'customer',
            'name' => ['ko' => '비밀번호 만료', 'en' => 'Password Expired'],
            'description' => ['ko' => '비밀번호 사용 기간이 끝났음을 알리는 고지', 'en' => 'Notice sent when a password has expired'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.auth.password_expired'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'expired_at', 'description' => '비밀번호 만료일'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호 사용 기간이 만료되었습니다',
                        'en' => '[{app_name}] Your password has expired',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>회원님의 비밀번호 사용 기간이 <strong>{expired_at}</strong> 에 끝났습니다.</p>'
                            .'<p>다음 로그인 때 새 비밀번호를 설정하는 단계가 표시됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your password expired on <strong>{expired_at}</strong>.</p>'
                            .'<p>You will be asked to set a new password the next time you sign in.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Sign in</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '비밀번호가 만료되었습니다', 'en' => 'Your password has expired'],
                    'body' => [
                        'ko' => '비밀번호 사용 기간이 {expired_at} 에 끝났습니다. 다음 로그인 때 새 비밀번호를 설정해 주세요.',
                        'en' => 'Your password expired on {expired_at}. Please set a new password the next time you sign in.',
                    ],
                    'click_url' => '{action_url}',
                ],
            ],
        ],

        // 사이트맵 재생성 완료 — 관리자 수동 재생성(SEO 탭 "지금 생성")에 한해, 실행한 관리자에게 발송.
        // 스케줄러/증분/봇 재생성은 SeoNotificationDataListener 가 context.skip 으로 제외.
        // 기본 활성 채널은 database(앱 내 알림)만. mail 템플릿은 존재하되 비활성(운영자가 필요 시 활성화).
        'sitemap_regenerated' => [
            'hook_prefix' => 'core.seo',
            'audience' => 'staff',
            'name' => ['ko' => '사이트맵 재생성 완료', 'en' => 'Sitemap Regeneration Complete'],
            'description' => ['ko' => '관리자가 수동으로 실행한 사이트맵 재생성이 완료되면 실행한 관리자에게 발송되는 알림', 'en' => 'Notification sent to the admin who manually triggered sitemap regeneration when it completes'],
            'channels' => ['database'],
            'hooks' => ['core.seo.sitemap.after_regenerate'],
            'variables' => [
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'url_count', 'description' => '생성된 총 URL 수'],
                ['key' => 'child_count', 'description' => '생성된 사이트맵 파일 수'],
                ['key' => 'action_url', 'description' => 'SEO 설정 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'is_active' => false,
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 사이트맵 재생성이 완료되었습니다',
                        'en' => '[{app_name}] Sitemap Regeneration Complete',
                    ],
                    'body' => [
                        'ko' => '<h1>사이트맵 재생성 완료</h1>'
                            .'<p>요청하신 사이트맵 재생성이 완료되었습니다.</p>'
                            .'<p>총 <strong>{url_count}</strong>개의 URL 이 <strong>{child_count}</strong>개의 파일로 생성되었습니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">SEO 설정 보기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Sitemap Regeneration Complete</h1>'
                            .'<p>The sitemap regeneration you requested has completed.</p>'
                            .'<p>A total of <strong>{url_count}</strong> URLs were generated across <strong>{child_count}</strong> file(s).</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">View SEO Settings</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '사이트맵 재생성 완료', 'en' => 'Sitemap regeneration complete'],
                    'body' => ['ko' => '요청하신 사이트맵 재생성이 완료되었습니다. 총 {url_count}개 URL, {child_count}개 파일이 생성되었습니다.', 'en' => 'Your sitemap regeneration is complete. {url_count} URLs across {child_count} file(s) were generated.'],
                    'click_url' => '/admin/settings?tab=seo',
                ],
            ],
        ],

        // 사이트맵 재생성 실패 — 관리자 수동 재생성이 실패하면 실행한 관리자에게 발송.
        'sitemap_regenerate_failed' => [
            'hook_prefix' => 'core.seo',
            'audience' => 'staff',
            'name' => ['ko' => '사이트맵 재생성 실패', 'en' => 'Sitemap Regeneration Failed'],
            'description' => ['ko' => '관리자가 수동으로 실행한 사이트맵 재생성이 최종 실패(재시도 소진)하면 실행한 관리자에게 발송되는 알림', 'en' => 'Notification sent to the admin who manually triggered sitemap regeneration when it finally fails after retries'],
            'channels' => ['database'],
            // 재시도 소진 시 1회만 발화하는 최종 실패 훅에 구독 (매 시도 발화하는 after_regenerate_failed 아님)
            'hooks' => ['core.seo.sitemap.regenerate_failed_final'],
            'variables' => [
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'error', 'description' => '실패 사유 메시지'],
                ['key' => 'action_url', 'description' => 'SEO 설정 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'is_active' => false,
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 사이트맵 재생성이 실패했습니다',
                        'en' => '[{app_name}] Sitemap Regeneration Failed',
                    ],
                    'body' => [
                        'ko' => '<h1>사이트맵 재생성 실패</h1>'
                            .'<p>요청하신 사이트맵 재생성이 실패했습니다.</p>'
                            .'<p>오류: <strong>{error}</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">SEO 설정 보기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Sitemap Regeneration Failed</h1>'
                            .'<p>The sitemap regeneration you requested has failed.</p>'
                            .'<p>Error: <strong>{error}</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">View SEO Settings</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '사이트맵 재생성 실패', 'en' => 'Sitemap regeneration failed'],
                    'body' => ['ko' => '요청하신 사이트맵 재생성이 실패했습니다. 오류: {error}', 'en' => 'Your sitemap regeneration failed. Error: {error}'],
                    'click_url' => '/admin/settings?tab=seo',
                ],
            ],
        ],
        /*
        | 휴면 생명주기 3종 (privacy:process-dormant 가 발행)
        |
        | hook_prefix 가 `core.privacy` 라서 데이터 추출 필터는
        | `core.privacy.notification.extract_data` 이며 CorePrivacyNotificationDataListener 가 받는다.
        | 세 정의 모두 법정 사전 안내·사후 고지에 해당하므로 마케팅 수신 동의와 무관하게 발송한다.
        */
        'dormant_pre_notice' => [
            'hook_prefix' => 'core.privacy',
            'audience' => 'customer',
            'name' => ['ko' => '휴면 전환 사전 안내', 'en' => 'Dormancy Advance Notice'],
            'description' => ['ko' => '장기 미접속으로 곧 휴면 전환될 회원에게 미리 보내는 안내', 'en' => 'Notice sent before a long-inactive account becomes dormant'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.privacy.dormant_pre_notice'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'dormant_days', 'description' => '휴면 전환 기준 미접속 일수'],
                ['key' => 'scheduled_date', 'description' => '휴면 전환 예정일'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] {scheduled_date} 휴면 계정 전환 예정 안내',
                        'en' => '[{app_name}] Your account will become dormant on {scheduled_date}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>{app_name} 에 <strong>{dormant_days}일</strong> 이상 접속하지 않으셔서, <strong>{scheduled_date}</strong> 에 회원님의 계정이 휴면 상태로 전환될 예정입니다.</p>'
                            .'<p>그 전에 한 번 로그인하시면 휴면 전환이 취소됩니다. 휴면으로 전환된 뒤에도 로그인하시면 곧바로 원래대로 복구됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">지금 로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>You have not signed in to {app_name} for more than <strong>{dormant_days} days</strong>, so your account is scheduled to become dormant on <strong>{scheduled_date}</strong>.</p>'
                            .'<p>Signing in before then cancels the change. Even after it takes effect, signing in restores your account immediately.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Sign in now</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '휴면 계정 전환 예정 안내', 'en' => 'Your account will become dormant soon'],
                    'body' => [
                        'ko' => '{dormant_days}일 이상 미접속으로 {scheduled_date} 에 휴면 계정으로 전환됩니다. 로그인하시면 전환이 취소됩니다.',
                        'en' => 'Your account becomes dormant on {scheduled_date} after {dormant_days} days of inactivity. Signing in cancels it.',
                    ],
                    'click_url' => '/mypage',
                ],
            ],
        ],
        'dormant_converted' => [
            'hook_prefix' => 'core.privacy',
            'audience' => 'customer',
            'name' => ['ko' => '휴면 전환 완료', 'en' => 'Dormancy Applied'],
            'description' => ['ko' => '휴면 계정으로 전환되었음을 알리는 고지', 'en' => 'Notice sent when an account has become dormant'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.privacy.dormant_converted'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] 휴면 계정으로 전환되었습니다',
                        'en' => '[{app_name}] Your account is now dormant',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>장기 미접속으로 회원님의 {app_name} 계정이 <strong>휴면 상태</strong>로 전환되었습니다.</p>'
                            .'<p>휴면 상태에서는 서비스 이용이 제한되지만, <strong>로그인만 하시면 곧바로 원래대로 복구</strong>됩니다. 별도의 신청 절차는 없습니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">로그인해 복구하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your {app_name} account has become <strong>dormant</strong> after a long period of inactivity.</p>'
                            .'<p>Service is limited while dormant, but <strong>simply signing in restores it immediately</strong> — no application is required.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Sign in to restore</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '휴면 계정으로 전환되었습니다', 'en' => 'Your account is now dormant'],
                    'body' => [
                        'ko' => '장기 미접속으로 휴면 계정이 되었습니다. 로그인하시면 곧바로 복구됩니다.',
                        'en' => 'Your account is dormant after a long period of inactivity. Signing in restores it immediately.',
                    ],
                    'click_url' => '/login',
                ],
            ],
        ],
        'dormant_withdraw_notice' => [
            'hook_prefix' => 'core.privacy',
            'audience' => 'customer',
            'name' => ['ko' => '휴면 계정 탈퇴 예정 안내', 'en' => 'Dormant Account Withdrawal Notice'],
            'description' => ['ko' => '휴면 기간이 지나 탈퇴 처리될 예정임을 미리 알리는 안내', 'en' => 'Notice sent before a dormant account is withdrawn'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.privacy.dormant_withdraw_notice'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'action_url', 'description' => '로그인 페이지 URL'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
                ['key' => 'scheduled_date', 'description' => '탈퇴 처리 예정일'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => [
                        'ko' => '[{app_name}] {scheduled_date} 회원 탈퇴 처리 예정 안내',
                        'en' => '[{app_name}] Your account will be withdrawn on {scheduled_date}',
                    ],
                    'body' => [
                        'ko' => '<h1>{name}님, 안녕하세요.</h1>'
                            .'<p>휴면 상태가 계속되어 <strong>{scheduled_date}</strong> 에 회원님의 {app_name} 계정이 탈퇴 처리될 예정입니다.</p>'
                            .'<p><strong>탈퇴 처리 후에는 계정과 개인정보를 되돌릴 수 없습니다.</strong> 계정을 계속 이용하시려면 그 전에 로그인해 주세요 — 로그인하시면 휴면이 해제되고 탈퇴 예정도 함께 취소됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">지금 로그인하기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Hello, {name}.</h1>'
                            .'<p>Your {app_name} account has remained dormant and is scheduled to be withdrawn on <strong>{scheduled_date}</strong>.</p>'
                            .'<p><strong>Once withdrawn, the account and its personal data cannot be restored.</strong> To keep using it, please sign in before then — signing in lifts the dormancy and cancels the scheduled withdrawal.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{action_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Sign in now</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'trigger_user']],
                    'subject' => ['ko' => '회원 탈퇴 처리 예정 안내', 'en' => 'Your account will be withdrawn soon'],
                    'body' => [
                        'ko' => '휴면 상태가 계속되어 {scheduled_date} 에 탈퇴 처리됩니다. 되돌릴 수 없으니 계속 이용하시려면 로그인해 주세요.',
                        'en' => 'Your dormant account will be withdrawn on {scheduled_date}. This cannot be undone — sign in to keep it.',
                    ],
                    'click_url' => '/login',
                ],
            ],
        ],

        // 방문자 통계 집계 실패 — 집계 배치가 끝내 실패하면 화면 숫자가 그 시점에 멈춘다.
        // 운영자가 모르면 며칠치 통계가 조용히 비므로 관리자 역할 전체에 알린다.
        'analytics_archive_failed' => [
            'hook_prefix' => 'core.analytics',
            'audience' => 'staff',
            'name' => ['ko' => '방문자 통계 집계 실패', 'en' => 'Analytics Archiving Failed'],
            'description' => ['ko' => '방문자 통계 집계 배치가 실패하면 관리자에게 발송되는 알림', 'en' => 'Notification sent to administrators when the analytics archiving batch fails'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.analytics.archive_failed'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'run_type', 'description' => '집계 종류 이름(예: 일 집계)'],
                ['key' => 'period', 'description' => '대상 기간'],
                ['key' => 'error', 'description' => '실패 사유 메시지'],
                ['key' => 'diagnostics_url', 'description' => '수집 진단 화면 URL'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => [
                        'ko' => '[{app_name}] 방문자 통계 집계가 실패했습니다',
                        'en' => '[{app_name}] Analytics Archiving Failed',
                    ],
                    'body' => [
                        'ko' => '<h1>방문자 통계 집계 실패</h1>'
                            .'<p>{period} 기간의 {run_type}가 실패했습니다. 집계가 밀린 동안에는 통계 화면의 숫자가 갱신되지 않습니다.</p>'
                            .'<p>오류: <strong>{error}</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{diagnostics_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">수집 진단 열기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Analytics Archiving Failed</h1>'
                            .'<p>{run_type} for {period} failed. Statistics screens will not update while archiving is behind.</p>'
                            .'<p>Error: <strong>{error}</strong></p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{diagnostics_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Open Diagnostics</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => ['ko' => '방문자 통계 집계 실패', 'en' => 'Analytics archiving failed'],
                    'body' => ['ko' => '{period} 기간의 {run_type}가 실패했습니다. 오류: {error}', 'en' => '{run_type} for {period} failed. Error: {error}'],
                    'click_url' => '/admin/analytics/diagnostics',
                ],
            ],
        ],

        // 원장 행수 임계 초과 — 방문·액션 원장이 계속 늘면 조회가 느려지고 디스크를 먹는다.
        // 보존일을 줄이거나 정리 배치를 확인하라는 신호.
        'analytics_ledger_limit_exceeded' => [
            'hook_prefix' => 'core.analytics',
            'audience' => 'staff',
            'name' => ['ko' => '방문자 통계 원장 임계 초과', 'en' => 'Analytics Ledger Threshold Exceeded'],
            'description' => ['ko' => '방문·액션 원장 행수가 설정한 경고 임계치를 넘으면 관리자에게 발송되는 알림', 'en' => 'Notification sent to administrators when visit or action ledger rows exceed the configured warning threshold'],
            'channels' => ['mail', 'database'],
            'hooks' => ['core.analytics.ledger_limit_exceeded'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'visits', 'description' => '현재 방문 행수'],
                ['key' => 'actions', 'description' => '현재 액션 행수'],
                ['key' => 'threshold_visits', 'description' => '방문 경고 임계치'],
                ['key' => 'threshold_actions', 'description' => '액션 경고 임계치'],
                ['key' => 'retention_days', 'description' => '현재 원장 보존일'],
                ['key' => 'settings_url', 'description' => '보존 설정 화면 URL'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => [
                        'ko' => '[{app_name}] 방문자 통계 원장이 임계치를 넘었습니다',
                        'en' => '[{app_name}] Analytics Ledger Exceeded Threshold',
                    ],
                    'body' => [
                        'ko' => '<h1>방문자 통계 원장 임계 초과</h1>'
                            .'<p>방문 {visits}행(임계 {threshold_visits}) · 액션 {actions}행(임계 {threshold_actions})입니다. 현재 보존일은 {retention_days}일입니다.</p>'
                            .'<p>보존일을 줄이거나 정리 배치가 도는지 확인해 주세요.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{settings_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">보존 설정 열기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Analytics Ledger Exceeded Threshold</h1>'
                            .'<p>{visits} visit rows (threshold {threshold_visits}) and {actions} action rows (threshold {threshold_actions}). Current retention is {retention_days} days.</p>'
                            .'<p>Consider lowering retention or checking that the prune batch runs.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{settings_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">Open Retention Settings</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => ['ko' => '방문자 통계 원장 임계 초과', 'en' => 'Analytics ledger exceeded threshold'],
                    'body' => ['ko' => '방문 {visits}행 · 액션 {actions}행으로 경고 임계치를 넘었습니다.', 'en' => '{visits} visit rows and {actions} action rows exceeded the warning threshold.'],
                    'click_url' => '/admin/analytics/settings?tab=retention',
                ],
            ],
        ],

        // 한 방문의 액션 수 상한 초과 — 그 방문의 이후 액션은 버려진다.
        // 자동화 트래픽이거나 상한이 너무 낮다는 신호라 화면 알림만 남긴다.
        'analytics_visit_limit_exceeded' => [
            'hook_prefix' => 'core.analytics',
            'audience' => 'staff',
            'name' => ['ko' => '방문당 액션 상한 초과', 'en' => 'Visit Action Limit Exceeded'],
            'description' => ['ko' => '한 방문의 액션 수가 상한을 넘어 이후 액션이 기록되지 않을 때 관리자에게 남기는 알림', 'en' => 'Notification left for administrators when a single visit exceeds the action limit and further actions are dropped'],
            'channels' => ['database'],
            'hooks' => ['core.analytics.visit_limit_exceeded'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'visitor_key', 'description' => '방문자 키(앞 8자)'],
                ['key' => 'actions', 'description' => '기록된 액션 수'],
                ['key' => 'limit', 'description' => '방문당 액션 상한'],
                ['key' => 'ip_display', 'description' => '표시용 IP(설정에 따라 마스킹)'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'database',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => ['ko' => '방문당 액션 상한 초과', 'en' => 'Visit action limit exceeded'],
                    'body' => ['ko' => '방문자 {visitor_key}({ip_display})의 액션이 상한 {limit}건을 넘어 이후 액션은 기록되지 않았습니다.', 'en' => 'Visitor {visitor_key} ({ip_display}) exceeded the {limit} action limit; further actions were not recorded.'],
                    'click_url' => '/admin/analytics/diagnostics',
                ],
            ],
        ],

        // 주간 리포트 — 설정의 report_weekly_enabled 와 이 정의의 활성 상태가 둘 다 참일 때만 발송된다.
        'analytics_weekly_report' => [
            'hook_prefix' => 'core.analytics',
            'audience' => 'staff',
            'name' => ['ko' => '방문자 통계 주간 리포트', 'en' => 'Weekly Analytics Report'],
            'description' => ['ko' => '매주 월요일 지난 한 주의 방문자 통계 요약을 관리자에게 보내는 리포트', 'en' => 'Weekly summary of visitor statistics sent to administrators every Monday'],
            'channels' => ['mail'],
            'hooks' => ['core.analytics.weekly_report_ready'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'period_label', 'description' => '기간 표기(예: 2026-09-08 ~ 2026-09-14)'],
                ['key' => 'visits', 'description' => '방문 수'],
                ['key' => 'visitors', 'description' => '순방문자 수'],
                ['key' => 'pageviews', 'description' => '페이지뷰 수'],
                ['key' => 'change_pct', 'description' => '직전 기간 대비 증감률(예: +12.5%, 직전 기간이 0이면 -)'],
                ['key' => 'top_pages', 'description' => '인기 페이지 표(HTML)'],
                ['key' => 'top_referrers', 'description' => '유입 경로 표(HTML)'],
                ['key' => 'report_url', 'description' => '통계 개요 화면 URL'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => [
                        'ko' => '[{app_name}] 주간 방문자 통계 ({period_label})',
                        'en' => '[{app_name}] Weekly Analytics Report ({period_label})',
                    ],
                    'body' => [
                        'ko' => '<h1>주간 방문자 통계</h1>'
                            .'<p>{period_label} 기간 요약입니다.</p>'
                            .'<p>방문 <strong>{visits}</strong> · 순방문자 <strong>{visitors}</strong> · 페이지뷰 <strong>{pageviews}</strong> (직전 기간 대비 {change_pct})</p>'
                            .'<h2>인기 페이지</h2>{top_pages}'
                            .'<h2>유입 경로</h2>{top_referrers}'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{report_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">통계 전체 보기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Weekly Analytics Report</h1>'
                            .'<p>Summary for {period_label}.</p>'
                            .'<p>Visits <strong>{visits}</strong> · Unique visitors <strong>{visitors}</strong> · Pageviews <strong>{pageviews}</strong> ({change_pct} vs previous period)</p>'
                            .'<h2>Top Pages</h2>{top_pages}'
                            .'<h2>Top Referrers</h2>{top_referrers}'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{report_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">View Full Statistics</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],

        // 월간 리포트 — 주간과 같은 구성, 매월 1일 발송.
        'analytics_monthly_report' => [
            'hook_prefix' => 'core.analytics',
            'audience' => 'staff',
            'name' => ['ko' => '방문자 통계 월간 리포트', 'en' => 'Monthly Analytics Report'],
            'description' => ['ko' => '매월 1일 지난달의 방문자 통계 요약을 관리자에게 보내는 리포트', 'en' => 'Monthly summary of visitor statistics sent to administrators on the first of each month'],
            'channels' => ['mail'],
            'hooks' => ['core.analytics.monthly_report_ready'],
            'variables' => [
                ['key' => 'name', 'description' => '수신자 이름'],
                ['key' => 'period_label', 'description' => '기간 표기(예: 2026-08-01 ~ 2026-08-31)'],
                ['key' => 'visits', 'description' => '방문 수'],
                ['key' => 'visitors', 'description' => '순방문자 수'],
                ['key' => 'pageviews', 'description' => '페이지뷰 수'],
                ['key' => 'change_pct', 'description' => '직전 기간 대비 증감률(예: +12.5%, 직전 기간이 0이면 -)'],
                ['key' => 'top_pages', 'description' => '인기 페이지 표(HTML)'],
                ['key' => 'top_referrers', 'description' => '유입 경로 표(HTML)'],
                ['key' => 'report_url', 'description' => '통계 개요 화면 URL'],
                ['key' => 'app_name', 'description' => '사이트명'],
                ['key' => 'site_url', 'description' => '사이트 URL'],
            ],
            'templates' => [
                [
                    'channel' => 'mail',
                    'recipients' => [['type' => 'role', 'value' => 'admin']],
                    'subject' => [
                        'ko' => '[{app_name}] 월간 방문자 통계 ({period_label})',
                        'en' => '[{app_name}] Monthly Analytics Report ({period_label})',
                    ],
                    'body' => [
                        'ko' => '<h1>월간 방문자 통계</h1>'
                            .'<p>{period_label} 요약입니다.</p>'
                            .'<p>방문 <strong>{visits}</strong> · 순방문자 <strong>{visitors}</strong> · 페이지뷰 <strong>{pageviews}</strong> (직전 기간 대비 {change_pct})</p>'
                            .'<h2>인기 페이지</h2>{top_pages}'
                            .'<h2>유입 경로</h2>{top_referrers}'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{report_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">통계 전체 보기</a></td></tr></table>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Monthly Analytics Report</h1>'
                            .'<p>Summary for {period_label}.</p>'
                            .'<p>Visits <strong>{visits}</strong> · Unique visitors <strong>{visitors}</strong> · Pageviews <strong>{pageviews}</strong> ({change_pct} vs previous period)</p>'
                            .'<h2>Top Pages</h2>{top_pages}'
                            .'<h2>Top Referrers</h2>{top_referrers}'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 24px 0;"><tr><td align="center"><a href="{report_url}" style="display: inline-block; padding: 12px 32px; background-color: #2d3748; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: 600; font-size: 14px;">View Full Statistics</a></td></tr></table>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
        // ── 본인인증 알림 (category=identity) ─────────────────────────────────────
        // 본인인증 메일(인증번호·검증 링크)도 알림 정의로 선언한다 — 채널 토글·발송 로그·훅을 함께 탄다.
        // type 규칙은 IdentityNotificationType 이 소유한다:
        //   identity.default (폴백) / identity.purpose.{purpose} / identity.policy.{정책 키}(운영자 추가, 100자 초과 시 해시)
        // 발송은 IdentityMessageDispatcher 가 정책 → 목적 → 기본 순서로 해석해 GenericNotification 으로 보낸다.
        // hooks 는 비운다 — 훅 리스너가 아니라 본인인증 발송 경로만 이 정의를 쓴다. 수신자는 인증 요청 대상으로 고정이라
        // recipients 를 선언하지 않는다. variables 는 공통 SSoT(IdentityNotificationType::commonVariables).
        'identity.default' => [
            'category' => 'identity',
            // 인증을 요청한 사람에게만 보낸다(수신자 규칙 없음) — 역할로 사람을 뽑지 않으므로 customer.
            'audience' => 'customer',
            'hook_prefix' => 'core.identity',
            'name' => ['ko' => '메일 본인 확인 (기본)', 'en' => 'Mail Verification (default)'],
            'description' => [
                'ko' => '특정 목적이 매칭되지 않을 때 사용되는 기본 메일 템플릿',
                'en' => 'Fallback mail template used when no specific purpose matches',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 본인 확인 인증 코드',
                        'en' => '[{app_name}] Verification Code',
                    ],
                    'body' => [
                        'ko' => '<p>안녕하세요.</p>'
                            .'<p>아래 인증 코드를 입력해 본인 확인을 완료해 주세요.</p>'
                            .'<p style="font-size:24px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p>본인이 요청하지 않았다면 이 메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<p>Hello.</p>'
                            .'<p>Please enter the verification code below to confirm your identity.</p>'
                            .'<p style="font-size:24px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p>If you did not request this, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
        'identity.purpose.signup' => [
            'category' => 'identity',
            // 인증을 요청한 사람에게만 보낸다(수신자 규칙 없음) — 역할로 사람을 뽑지 않으므로 customer.
            'audience' => 'customer',
            'hook_prefix' => 'core.identity',
            'name' => ['ko' => '회원가입 인증', 'en' => 'Signup Verification'],
            'description' => [
                'ko' => '회원가입 단계에서 이메일 본인 확인 시 발송',
                'en' => 'Sent when verifying email during signup',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 회원가입 인증 코드',
                        'en' => '[{app_name}] Signup Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>{app_name} 회원가입 인증</h1>'
                            .'<p>아래 인증 코드를 회원가입 화면에 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p>본인이 가입을 시도하지 않았다면 이 메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>{app_name} Signup Verification</h1>'
                            .'<p>Please enter the verification code below on the signup screen.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p>If you did not request this, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
        'identity.purpose.password_reset' => [
            'category' => 'identity',
            // 인증을 요청한 사람에게만 보낸다(수신자 규칙 없음) — 역할로 사람을 뽑지 않으므로 customer.
            'audience' => 'customer',
            'hook_prefix' => 'core.identity',
            'name' => ['ko' => '비밀번호 재설정 링크', 'en' => 'Password Reset Link'],
            'description' => [
                'ko' => '비밀번호 재설정 요청 시 발송되는 서명 링크 메일 (link 흐름)',
                'en' => 'Signed-link email sent on password reset request (link flow)',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 비밀번호 재설정 안내',
                        'en' => '[{app_name}] Password Reset Request',
                    ],
                    'body' => [
                        'ko' => '<h1>비밀번호 재설정</h1>'
                            .'<p>비밀번호 재설정 요청을 받았습니다. 아래 버튼을 클릭하여 비밀번호를 재설정해 주세요.</p>'
                            .'<p>이 링크는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td align="center"><a href="{action_url}" style="display:inline-block; padding:12px 32px; background-color:#2d3748; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">비밀번호 재설정</a></td></tr></table>'
                            .'<p>본인이 요청하지 않았다면 이 메일을 무시해 주세요.</p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Password Reset</h1>'
                            .'<p>We received a password reset request. Click the button below to set a new password.</p>'
                            .'<p>This link will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td align="center"><a href="{action_url}" style="display:inline-block; padding:12px 32px; background-color:#2d3748; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:600; font-size:14px;">Reset Password</a></td></tr></table>'
                            .'<p>If you did not request this, please ignore this email.</p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
        'identity.purpose.self_update' => [
            'category' => 'identity',
            // 인증을 요청한 사람에게만 보낸다(수신자 규칙 없음) — 역할로 사람을 뽑지 않으므로 customer.
            'audience' => 'customer',
            'hook_prefix' => 'core.identity',
            'name' => ['ko' => '계정 정보 변경 인증', 'en' => 'Self-update Verification'],
            'description' => [
                'ko' => '계정 정보 변경 시 본인 확인 코드 메일',
                'en' => 'Verification code mail when updating account info',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 계정 정보 변경 인증 코드',
                        'en' => '[{app_name}] Account Update Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>계정 정보 변경 인증</h1>'
                            .'<p>{purpose_label} 처리를 위해 아래 인증 코드를 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p><strong>본인이 변경을 시도하지 않았다면 즉시 비밀번호를 변경하시기 바랍니다.</strong></p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Account Update Verification</h1>'
                            .'<p>Please enter the code below to proceed with {purpose_label}.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p><strong>If you did not initiate this change, please change your password immediately.</strong></p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
        'identity.purpose.sensitive_action' => [
            'category' => 'identity',
            // 인증을 요청한 사람에게만 보낸다(수신자 규칙 없음) — 역할로 사람을 뽑지 않으므로 customer.
            'audience' => 'customer',
            'hook_prefix' => 'core.identity',
            'name' => ['ko' => '중요 작업 인증', 'en' => 'Sensitive Action Verification'],
            'description' => [
                'ko' => '결제, 주요 설정 변경 등 중요 작업 시 본인 확인 코드 메일',
                'en' => 'Verification code mail for sensitive actions (payment, critical settings)',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 중요 작업 인증 코드',
                        'en' => '[{app_name}] Sensitive Action Verification Code',
                    ],
                    'body' => [
                        'ko' => '<h1>중요 작업 인증</h1>'
                            .'<p>{purpose_label} 진행을 위해 아래 인증 코드를 입력해 주세요.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 코드는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p><strong>본인이 시도하지 않았다면 즉시 고객 지원팀에 문의해 주세요.</strong></p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<h1>Sensitive Action Verification</h1>'
                            .'<p>Please enter the code below to proceed with {purpose_label}.</p>'
                            .'<p style="font-size:28px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p><strong>If you did not initiate this action, please contact support immediately.</strong></p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
        'identity.purpose.login' => [
            'category' => 'identity',
            // 인증을 요청한 사람에게만 보낸다(수신자 규칙 없음) — 역할로 사람을 뽑지 않으므로 customer.
            'audience' => 'customer',
            'hook_prefix' => 'core.identity',
            'name' => ['ko' => '로그인 인증번호', 'en' => 'Login Verification Code'],
            'description' => [
                'ko' => '2단계 인증을 켰을 때 비밀번호 확인 뒤 보내는 로그인 인증번호 메일',
                'en' => 'Login verification code mail sent after the password step when two-factor authentication is on',
            ],
            'channels' => ['mail'],
            'hooks' => [],
            'variables' => IdentityNotificationType::commonVariables(),
            'templates' => [
                [
                    'channel' => 'mail',
                    'subject' => [
                        'ko' => '[{app_name}] 로그인 인증번호 안내',
                        'en' => '[{app_name}] Your Login Verification Code',
                    ],
                    'body' => [
                        'ko' => '<p>안녕하세요.</p>'
                            .'<p>로그인을 완료하려면 아래 인증번호를 입력해 주세요.</p>'
                            .'<p style="font-size:24px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>이 인증번호는 <strong>{expire_minutes}분</strong> 후 만료됩니다.</p>'
                            .'<p><strong>본인이 로그인을 시도하지 않았다면 즉시 비밀번호를 변경해 주세요.</strong></p>'
                            .'<p>감사합니다,<br><a href="{site_url}">{app_name}</a></p>',
                        'en' => '<p>Hello.</p>'
                            .'<p>Please enter the verification code below to complete your login.</p>'
                            .'<p style="font-size:24px; font-weight:bold; letter-spacing:4px; text-align:center; padding:16px; background:#f4f6f8; border-radius:6px;">{code}</p>'
                            .'<p>This code will expire in <strong>{expire_minutes} minutes</strong>.</p>'
                            .'<p><strong>If you did not try to log in, please change your password immediately.</strong></p>'
                            .'<p>Thank you,<br><a href="{site_url}">{app_name}</a></p>',
                    ],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 본인인증(IDV) 정책
    |--------------------------------------------------------------------------
    | IdentityPolicySeeder 에서 사용됩니다. IdentityPolicySyncHelper 가 upsert 하며,
    | 운영자가 S1d UI 에서 수정한 필드는 user_overrides JSON 에 기록되어 재시딩 시 보존됩니다.
    */
    'identity_policies' => [
        // 회원가입 — Mode B (route 단계, verification_token 검증 필수)
        'core.auth.signup_before_submit' => [
            'scope' => 'route',
            'target' => 'api.auth.register',
            'purpose' => 'signup',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'priority' => 110,
            'conditions' => ['signup_stage' => 'before_submit', 'http_method' => ['POST']],
        ],

        // 회원가입 — Mode C (hook 단계, after_register 시 challenge 발행 + PendingVerification)
        'core.auth.signup_after_create' => [
            'scope' => 'hook',
            'target' => 'core.auth.after_register',
            'purpose' => 'signup',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'priority' => 100,
            'conditions' => ['signup_stage' => 'after_create'],
        ],

        // 비밀번호 재설정 — 기존 forgotPassword 흐름이 IDV 인프라 경유 (안전 기본 OFF)
        'core.auth.password_reset' => [
            'scope' => 'hook',
            'target' => 'core.auth.before_reset_password',
            'purpose' => 'password_reset',
            'provider_id' => null,
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'both',
            'fail_mode' => 'block',
            'priority' => 100,
        ],

        // 비밀번호 변경 (로그인 상태 자기 변경)
        'core.profile.password_change' => [
            'scope' => 'route',
            'target' => 'api.me.password',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => true,
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ],

        // 민감 정보 변경 (이메일/전화 변경)
        'core.profile.contact_change' => [
            'scope' => 'hook',
            'target' => 'core.user.before_update',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 5,
            'enabled' => true,
            'applies_to' => 'self',
            'fail_mode' => 'block',
            'conditions' => ['changed_fields' => ['email', 'phone', 'mobile']],
        ],

        // 계정 탈퇴
        'core.account.withdraw' => [
            'scope' => 'route',
            'target' => 'api.me.destroy',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => true,
            'applies_to' => 'self',
            'fail_mode' => 'block',
        ],

        // 관리자: App Key 재생성
        'core.admin.app_key_regenerate' => [
            'scope' => 'route',
            'target' => 'api.admin.settings.regenerate-app-key',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],

        // 관리자: 사용자 삭제
        'core.admin.user_delete' => [
            'scope' => 'hook',
            'target' => 'core.user.before_delete',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],

        // 관리자: 모듈/플러그인 제거
        'core.admin.extension_uninstall' => [
            'scope' => 'route',
            'target' => 'api.admin.{modules,plugins}.uninstall',
            'purpose' => 'sensitive_action',
            'grace_minutes' => 0,
            'enabled' => false,
            'applies_to' => 'admin',
            'fail_mode' => 'block',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 본인인증(IDV) 목적 (purpose) 메타데이터
    |--------------------------------------------------------------------------
    | App\Enums\IdentityVerificationPurpose enum 이 코드 계약(타입 안전) 을 담당하고,
    | 본 블록은 라벨/설명/기본 provider/허용 채널 등 운영 메타데이터의 SSoT 입니다.
    | IdentityVerificationManager::registerCorePurposes() 가 부팅 시 본 블록을 로드하며,
    | 모듈/플러그인은 각자의 getIdentityPurposes() 에서 추가 purpose 를 선언합니다.
    | 라벨은 lang/{locale}/identity.php::purposes.{key}.label 가 우선 (다국어 정합).
    */
    'identity_purposes' => [
        'signup' => [
            // 'label' / 'description' 의 값은 i18n 키 문자열. resolvePurposeText 가 __() 로 풀이.
            // (`label_key` / `description_key` 명명은 controller 쪽이 'label'/'description' 만
            // 인식해 동작하지 않으므로 표준 명명으로 정렬)
            'label' => 'identity.purposes.signup.label',
            'description' => 'identity.purposes.signup.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
        'password_reset' => [
            'label' => 'identity.purposes.password_reset.label',
            'description' => 'identity.purposes.password_reset.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
        'self_update' => [
            'label' => 'identity.purposes.self_update.label',
            'description' => 'identity.purposes.self_update.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
        'sensitive_action' => [
            'label' => 'identity.purposes.sensitive_action.label',
            'description' => 'identity.purposes.sensitive_action.description',
            'default_provider' => 'g7:core.mail',
            'allowed_channels' => ['mail', 'sms'],
        ],
    ],

];
