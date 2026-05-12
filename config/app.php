<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', '그누보드7'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Vite Development Server URL
    |--------------------------------------------------------------------------
    |
    | This URL is used to connect to the Vite development server when
    | running in development mode. Set this to your Vite dev server URL.
    |
    */

    'vite_dev_server_url' => env('VITE_DEV_SERVER_URL', 'http://localhost:5173'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Default User Timezone
    |--------------------------------------------------------------------------
    |
    | 사용자가 timezone을 설정하지 않은 경우 사용할 기본 timezone입니다.
    | 한국 서비스 기준으로 Asia/Seoul을 기본값으로 사용합니다.
    |
    */

    'default_user_timezone' => env('APP_DEFAULT_USER_TIMEZONE', 'Asia/Seoul'),

    /*
    |--------------------------------------------------------------------------
    | Supported Timezones
    |--------------------------------------------------------------------------
    |
    | 시스템에서 지원하는 timezone 목록입니다.
    | PHP 내장 IANA 타임존 전체 목록을 사용합니다.
    | SettingsService::getAppConfigForFrontend()에서 오프셋 라벨로 변환되어
    | 프론트엔드에 _global.appConfig.supportedTimezones로 노출됩니다.
    |
    */

    'supported_timezones' => \DateTimeZone::listIdentifiers(),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'ko'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'ko'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'ko_KR'),

    /*
    |--------------------------------------------------------------------------
    | Supported Locales
    |--------------------------------------------------------------------------
    |
    | 시스템에서 지원하는 모든 언어 목록입니다.
    | UI 언어 전환 등에 사용됩니다.
    |
    */

    'supported_locales' => ['ko', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Translatable Locales
    |--------------------------------------------------------------------------
    |
    | 다국어 필드(name, description 등)에서 허용하는 언어 목록입니다.
    | 번역 파일이 없어도 데이터 저장은 허용됩니다.
    | 새로운 언어를 추가할 때는 이 배열에 언어 코드를 추가하세요.
    |
    */

    'translatable_locales' => ['ko', 'en'],

    /*
    |--------------------------------------------------------------------------
    | Locale Names (언어 표시명)
    |--------------------------------------------------------------------------
    |
    | 각 로케일의 표시 이름입니다.
    | 프론트엔드에서 언어 탭, 언어 선택 UI 등에 사용됩니다.
    | 새로운 언어를 추가할 때는 이 배열에 표시명을 추가하세요.
    |
    */

    'locale_names' => [
        'ko' => '한국어',
        'en' => 'English',
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale → Country Code 휴리스틱 매핑
    |--------------------------------------------------------------------------
    |
    | Accept-Language 헤더에서 국가 코드를 추출할 수 없을 때 사용하는
    | 언어 코드 → 기본 국가 코드 폴백 매핑입니다. AuthService 가 사용합니다.
    |
    */

    'locale_country_fallback' => [
        'ko' => 'KR',
        'en' => 'US',
        'ja' => 'JP',
        'zh' => 'CN',
        'de' => 'DE',
        'fr' => 'FR',
        'es' => 'ES',
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | This value is the version of your application.
    |
    */

    'version' => env('APP_VERSION', '7.0.0-beta.5'),

    /*
    |--------------------------------------------------------------------------
    | Installer Completed Flag
    |--------------------------------------------------------------------------
    |
    | .env 의 INSTALLER_COMPLETED 플래그를 config 로 노출하여 런타임에서
    | Schema::hasTable() 호출 없이 확장 테이블 존재 여부를 빠르게 판정합니다.
    | 설치가 완료되지 않은 환경에서는 false 로 두어 기존 hasTable 폴백 유지.
    |
    */

    'installer_completed' => env('INSTALLER_COMPLETED', false),

    /*
    |--------------------------------------------------------------------------
    | Application Release Year
    |--------------------------------------------------------------------------
    |
    | 소프트웨어 최초 출시 연도입니다. 저작권 표시에 사용됩니다.
    |
    */

    'release_year' => env('APP_RELEASE_YEAR', '2026'),

    /*
    |--------------------------------------------------------------------------
    | Core Update Configuration
    |--------------------------------------------------------------------------
    |
    | 코어 업데이트 관련 설정입니다.
    |
    */

    'update' => [
        'github_url' => env('G7_UPDATE_GITHUB_URL', 'https://github.com/gnuboard/g7'),
        'github_token' => env('G7_UPDATE_GITHUB_TOKEN', ''),
        'pending_path' => env('G7_UPDATE_PENDING_PATH') ?: storage_path('app/core_pending'),
        'targets' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_TARGETS', 'app,bootstrap,config,database,docs,lang,lang-packs/_bundled,resources,routes,public,tests,upgrades,artisan,composer.json,composer.json.default,composer.lock,package.json,package-lock.json,vite.config.js,vite.config.core.js,vitest.config.ts,tsconfig.json,phpunit.xml,.editorconfig,.gitattributes,.gitignore,README.md,CHANGELOG.md,modules/_bundled,plugins/_bundled,templates/_bundled')))),
        'excludes' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_EXCLUDES', 'node_modules,.git,bootstrap/cache')))),
        // applyUpdate 의 "신규 최상위 항목 자동 발견" 폴백이 절대 덮어쓰면 안 되는 경로 목록.
        // 런타임 데이터(`storage`), 로컬 환경(`.env*`), 별도 파이프라인 산출물(`vendor`),
        // 개발 도구 메타(`.git`, `.claude`, `.serena` 등) 를 보호한다.
        // 부모 프로세스의 stale `targets` 가 신버전 신규 디렉토리를 인식하지 못해
        // 자동 발견이 트리거될 때만 참조된다 (정상 경로의 targets 는 항상 처리됨).
        // modules,plugins,templates,lang-packs 부모 디렉토리도 보호 대상에 포함.
        // targets 에 `{modules,plugins,templates,lang-packs}/_bundled` 가 명시되어 정상 흐름에서는
        // 처리되므로 영향 없음. 자동 발견 폴백이 부모 디렉토리 단위로 base 를 통째로 복사해
        // 활성 서브디렉토리(예: templates/sirsoft-basic) 를 잘못 삭제하는 회귀(#347) 차단용 방어 깊이.
        'protected_paths' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_PROTECTED_PATHS', '.env,.env.local,.env.production,.env.testing,.env.testing.example,storage,vendor,node_modules,.git,.github,.idea,.vscode,.serena,.claude,.mcp.json,.phpunit.result.cache,core_pending,modules,plugins,templates,lang-packs')))),
        'backup_only' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_BACKUP_ONLY', 'vendor')))),
        'backup_extra' => ['storage/app/settings'],
        // 업데이트 종료 시 base_path() 소유자/그룹 기준으로 소유권을 재귀 복원할 경로 목록.
        // sudo 실행 시 composer·artisan 등 외부 프로세스가 root 로 생성한 파일을 원상 회복한다.
        //
        // 기본값 = "sudo update 가 root 로 새 파일을 만들 가능성이 있는 영역" 한정.
        // 인스톨러 SSoT (`public/install/includes/config.php:REQUIRED_DIRECTORIES`) 의
        // `'storage' => true (재귀 검증)` 와는 의미가 다르다 — 인스톨러 SSoT 는 "운영자가
        // 초기 설치 시 storage 트리 전체에 적절한 권한을 부여했는지 검증" 용도이고,
        // 본 설정은 "sudo 후 root 가 만든 파일의 소유권을 원상 복원" 용도. 두 책임이 다르므로
        // 정렬이 깨져도 무방하다.
        //
        // storage 트리에서 chown 대상은 PHP-FPM 쓰기 필수 + sudo update 가 root 로 만들 수
        // 있는 경로만 한정한다 (`storage/logs`, `storage/framework/{cache,sessions,views}`,
        // `storage/app/core_pending`). `storage/app/{modules,plugins,attachments,public,settings}`
        // 같은 사용자 데이터/모듈 storage 영역은 PHP-FPM 시드/업로드 시점 owner 가 보존
        // 되어야 한다 (chown 대상에서 제외). 단 settings *.json 처럼 모듈/플러그인 upgrade
        // step 이 sudo 컨텍스트에서 만들 수 있는 파일은 `SettingsMigrator::writeJsonFile`
        // 가 부모 디렉토리 owner/group 을 상속하여 별도 처리.
        //
        // 환경변수 `G7_UPDATE_RESTORE_OWNERSHIP` 로 공유 호스팅 등 축소 필요 시 재정의 가능.
        'restore_ownership' => array_filter(array_map('trim', explode(',', env(
            'G7_UPDATE_RESTORE_OWNERSHIP',
            'storage/logs,storage/framework,storage/app/core_pending,bootstrap/cache,vendor,modules,modules/_pending,plugins,plugins/_pending,templates,templates/_pending,lang-packs,lang-packs/_pending'
        )))),
        // 7.0.0-beta.3+: 그룹 쓰기 권한 비대칭 정상화 대상.
        // sudo root 로 실행된 업데이트가 umask 022 로 신규 생성한 하위 디렉토리/파일이
        // chownRecursive 후에도 g-w 로 남아 php-fpm(www-data 그룹) 이 쓰기 실패하는
        // 문제를 차단하기 위해, restoreOwnership 종료 직후 본 경로들에 한해
        // FilePermissionHelper::syncGroupWritability 를 호출한다.
        //
        // 정책: 루트가 g+w 면 하위 g-w 항목을 g+w 로 승격, 다른 비트 무변경.
        // 운영자가 의도적으로 그룹 쓰기를 차단한 경로(0755 등) 는 자동 보존됨.
        //
        // Laravel 런타임 그룹 쓰기 필요 경로 — `restore_ownership` 과 동일한 책임 분리 원칙
        // (PHP-FPM 쓰기 영역 한정, 사용자 데이터 영역 비대상). 인스톨러 SSoT 의 storage
        // 재귀 검증 의도와는 다른 책임이다.
        //  - storage/logs, storage/framework/{cache,sessions,views}: 캐시·세션·로그
        //  - storage/app/core_pending: 코어 업데이트 _pending 영역
        //  - bootstrap/cache: Laravel 설정·라우트 캐시
        //  - vendor: composer/sudo 가 root 로 재생성한 후 일반 권한 사용자/php-fpm 이 후속 작업
        //  - modules, plugins, templates: 확장 설치/업데이트/제거 시 php-fpm 이 디렉토리 조작
        //  - modules/_pending, plugins/_pending, templates/_pending: 다운로드 대기소
        //  - lang-packs, lang-packs/_pending: 언어팩 활성/대기 영역
        //
        // _bundled/ 는 개발 시점 원본 배포본이므로 런타임 쓰기 불필요 — 미포함.
        //
        // 자식 디렉토리(예: plugins/sirsoft-*)는 syncGroupWritability 가 재귀 순회하여
        // 자동 정상화되므로 상위 루트만 지정하면 충분. 환경변수로 재정의 가능.
        'restore_ownership_group_writable' => array_filter(array_map('trim', explode(',', env(
            'G7_UPDATE_RESTORE_OWNERSHIP_GROUP_WRITABLE',
            'storage/logs,storage/framework,storage/app/core_pending,bootstrap/cache,vendor,modules,modules/_pending,plugins,plugins/_pending,templates,templates/_pending,lang-packs,lang-packs/_pending'
        )))),

        // spawn 자식 프로세스 실패 시 동작 모드.
        //
        //  - 'abort'    (기본 / 안전 우선): 즉시 abort 후 운영자에게 수동 명령
        //                                  (`php artisan core:execute-upgrade-steps --from=... --to=...`)
        //                                  안내. 부모 메모리 stale 로 인한 upgrade step fatal
        //                                  위험을 원천 차단한다.
        //  - 'fallback' (호환 모드)      : 기존 동작 — in-process fallback 으로 진행.
        //                                  proc_open 비활성 공유 호스팅 등에서만 사용. 부모
        //                                  메모리 stale 시 신규 메서드 호출 fatal 위험 잔존.
        //
        // 4가지 분기 모두 본 모드를 따른다:
        //   (1) proc_open 비활성
        //   (2) proc_open 자원 생성 실패
        //   (3) 자식 비정상 종료 (exit != 0)
        //   (4) 자식 exit=0 이지만 [STEPS_EXECUTED] 신호 미발행/step 0건 실행 (silent skip)
        //
        // `CoreUpdateService::runUpgradeSteps` 의 stale 메모리 가드도 본 모드와 연동한다.
        'spawn_failure_mode' => env('G7_UPDATE_SPAWN_FAILURE_MODE', 'abort'),
    ],

];
