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

    'supported_timezones' => DateTimeZone::listIdentifiers(),

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

    /*
    | 사이트 기본 언어. `app.locale` 은 요청마다 `SetLocale` 미들웨어가 보는 사람의 언어로 덮으므로,
    | 「누가 보든 같은 언어」가 필요한 자리(다국어 필드 필수 언어 · 관리자 다국어 입력의 필수 표시)는
    | 이 값을 읽는다. 부팅 때 환경설정 「기본 언어」(general.language)로 채워진다.
    */
    'site_locale' => env('APP_LOCALE', 'ko'),

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

    'version' => env('APP_VERSION', '7.2.0'),

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
        // build/ext: 부트스트랩 리소스 정적 게시본(#122) — 각 서버가 재생성하는 로컬 파생물이라
        // 릴리즈 소스에 없다. 제외하지 않으면 --prune 업데이트가 orphan 으로 삭제해
        // 업데이트 완료까지 정적 fast path 가 불필요하게 끊긴다 (백업 대상에서도 제외).
        'excludes' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_EXCLUDES', 'node_modules,.git,bootstrap/cache,build/ext')))),
        // 루트 진입 파일 3종(`index.php`·`.htaccess`·`web.config`)은 배포 레이아웃 ②(문서 루트 =
        // 프로젝트 루트)의 진입점으로 릴리즈 ZIP 최상위에 동봉되며, 코어 업데이트가 함께 갱신한다 (D25).
        // `.htaccess` 는 통째로 덮어쓰지 않고 `# BEGIN G7 ~ # END G7` 블록만 교체해 운영자가
        // 블록 밖에 추가한 규칙을 보존한다 — CoreUpdateService::applyUpdate 의 전용 분기 참조.
        //
        // `web.config`(IIS)도 **같은 마커 블록 규칙**을 쓴다 — 경계가 `#` 주석이 아니라
        // `BEGIN G7`/`END G7` 을 담은 XML 주석 줄이고, 교체 대상은 그 블록(`system.webServer`
        // 요소 전체)이다. 판정 로직은 `AbstractRootMarkerFileUpdater` 하나가 소유하고
        // 자식(`RootHtaccessUpdater`·`RootWebConfigUpdater`)은 마커·문구 키만 정한다.
        // 운영자는 `END G7` 뒤·`</configuration>` 앞에 `<configuration>` 의 다른 자식 요소로
        // 규칙을 덧붙이며, 그 부분은 갱신에서 보존된다 — 안내는 파일 머리말과 INSTALL.md 에 있다.
        // (종전 이 주석은 「통째 교체」로 적혀 있었으나 구현·공개 문서와 어긋난 폐기 규약이었다.
        //  그대로 되돌리면 운영자 IIS 규칙이 업데이트마다 말없이 삭제된다.)
        // 통째 교체되는 것은 루트 `index.php` 뿐이다 — 마커가 없고, 파일 주석이 수정 금지를 고지한다.
        'targets' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_TARGETS', 'app,bootstrap,config,database,docs,lang,lang-packs/_bundled,resources,routes,public,tests,upgrades,deploy,scripts,artisan,g7-install,index.php,.htaccess,web.config,composer.json,composer.json.default,composer.lock,package.json,package-lock.json,vite.config.js,vite.config.core.js,vite.config.editor.js,vite.config.devtools.js,vite.config.devdashboard.js,vitest.config.ts,playwright.config.ts,tsconfig.json,tsconfig.app.json,tsconfig.test.json,phpunit.xml,pint.json,.editorconfig,.gitattributes,.gitignore,README.md,README.ko.md,CHANGELOG.md,modules/_bundled,plugins/_bundled,templates/_bundled')))),
        // applyUpdate 의 "신규 최상위 항목 자동 발견" 폴백이 절대 덮어쓰면 안 되는 경로 목록.
        // 런타임 데이터(`storage`), 로컬 환경(`.env*`), 별도 파이프라인 산출물(`vendor`),
        // 개발 도구 메타(`.git`, `.claude`, `.codex`, `.agents`, `.serena` 등) 를 보호한다.
        // 부모 프로세스의 stale `targets` 가 신버전 신규 디렉토리를 인식하지 못해
        // 자동 발견이 트리거될 때만 참조된다 (정상 경로의 targets 는 항상 처리됨).
        // R30 검토 (P3/G3) — 루트 진입 파일 3종과 이 목록의 관계:
        //   `index.php`·`.htaccess`·`web.config` 는 이 목록에 **없어야** 한다. 매칭은 정확
        //   일치 또는 `<항목>/` 하위 일치(CoreBackupHelper::isProtectedPath)이므로 현재
        //   기본값 중 세 파일에 걸리는 항목은 없다. 이 목록은 다섯 지점이 소비하는데
        //   (3-way 적용 목록 산출 · 신규파일 manifest · 자동 발견 폴백 스킵 · 자동 롤백
        //   prune 이중 가드 · stale 파일 정리 커맨드), 세 파일이 여기 들어가면 앞의 세 곳이
        //   **로그 없이** 건너뛰어 D25 의 「함께 갱신」이 조용히 죽는다. 특히 자동 발견
        //   폴백은 구버전 `targets` 로 도는 첫 업데이트에서 세 파일이 도달하는 유일한
        //   경로다. 기본값 잠금은 RootEntrypointContractTest 가 고정한다 — 운영자가
        //   `G7_UPDATE_PROTECTED_PATHS` 를 덮어쓰는 경우는 코드로 막지 않는다(그 설정의
        //   존재 이유가 운영자 판단이므로). 롤백 prune 이 세 파일을 지우는 것은 의도된
        //   동작이다 — 업데이트 이전 상태로 되돌리는 것이 목적이다.
        // 압축 풀기 부트스트랩(`g7-setup.php`)은 **설치 시점 파일**이라 업데이트가 관리하지
        // 않는다 (P7 · G4 산출물). 정상 설치는 그 파일이 스스로를 지우며 끝나고, 남아 있는
        // 사본은 완료 표식 둘을 보고 410 을 내는 무해한 상태다. `targets` 에 넣으면 운영자가
        // 지운 진입 파일이 업데이트마다 루트에 되살아나므로 여기에 둔다 — 만들지도 지우지도
        // 않는다는 선언이다. (2026-09-01 결정. 미등재 상태는 정적 검사가 error 로 잡는다)
        // modules,plugins,templates,lang-packs 부모 디렉토리도 보호 대상에 포함.
        // targets 에 `{modules,plugins,templates,lang-packs}/_bundled` 가 명시되어 정상 흐름에서는
        // 처리되므로 영향 없음. 자동 발견 폴백이 부모 디렉토리 단위로 base 를 통째로 복사해
        // 활성 서브디렉토리(예: templates/sirsoft-basic) 를 잘못 삭제하는 회귀(#347) 차단용 방어 깊이.
        // `custom`: 개발자 전용 composer(custom/composer.json·vendor) — 코어 업데이트가 건드리지 않는다(#792).
        'protected_paths' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_PROTECTED_PATHS', '.env,.env.local,.env.production,.env.testing,.env.testing.example,g7-setup.php,storage,vendor,node_modules,.git,.github,.idea,.vscode,.serena,.claude,.codex,.agents,CLAUDE.md,AGENTS.override.md,.mcp.json,.phpunit.result.cache,core_pending,modules,plugins,templates,lang-packs,custom')))),
        // 「custom 예약 자리」(#792, 7.2.0) — 업데이트 대상 폴더 *안쪽*이라도 코어 업데이트가 덮지도 지우지도
        // 않는 경로. 복사·orphan 정리(prune)·신규 파일 manifest·롤백 prune·자동 발견 폴백이 모두 건너뛴다.
        // 코어 배포본은 이 자리에 파일을 싣지 않는다 — 실리면 업데이트를 시작하지 않는다(UpdateReservedPaths).
        // 백업에는 targets 범위대로 포함되어 롤백 시 복원된다.
        'reserved_paths' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_RESERVED_PATHS', 'routes/custom.php,app/Custom,resources/views/custom,public/custom,lang/custom,config/custom.php,database/migrations/custom,custom')))),
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
        // `storage/app/{extension_backups,core_backups}`: 확장/코어 업데이트·롤백이 생성·소비·
        // 삭제하는 **임시 산출물**(사용자 데이터 아님). 쓰는 주체가 php-fpm(www-data) 이므로
        // chown 대상에 포함한다 — sudo update 가 root 로 백업을 만들면 이후 www-data 가
        // 그 안에 mkdir 못 해 `module:update` 가 "mkdir(): Permission denied" 로 실패한다.
        // 백업 내부 모듈 storage 에 `.preserve-ownership` 마커가 있으면 그 서브트리만
        // 자동 skip 되어 부작용 없음.
        //
        // `public/build/ext`: 부트스트랩 리소스 정적 게시본(#122). root 프로세스는 자동 게시를
        // 예약하지 않는다(`schedulePublishOnTerminate` 의 root 가드) — 이 항목은 **실존하는** 게시
        // 트리(이미 웹 계정이 게시한 사이트)의 소유권을 코어 업데이트 뒤 되돌리기 위한 것이다.
        // 트리가 root 소유가 되면 이후 php-fpm 의 재게시·GC 가 Permission denied 로 실패해 정적
        // fast path 가 영구 꺼진다(사이트는 API 폴백으로 정상). 임시 산출물(사용자 데이터 아님)이므로
        // extension_backups 와 같은 근거로 chown 대상.
        //
        // `storage/app/core_update`: 미배치 공식 번들 기록(`bundled_sources.json`, #146). sudo 업데이트가 root
        // 소유로 쓰면 관리자 목록 안내 API(웹 계정)가 읽지 못하므로 업데이트 뒤 원래 소유자로 되돌린다.
        //
        // 환경변수 `G7_UPDATE_RESTORE_OWNERSHIP` 로 공유 호스팅 등 축소 필요 시 재정의 가능.
        'restore_ownership' => array_filter(array_map('trim', explode(',', env(
            'G7_UPDATE_RESTORE_OWNERSHIP',
            'storage/logs,storage/framework,storage/app/core_pending,storage/app/extension_backups,storage/app/core_backups,storage/app/core_update,bootstrap/cache,vendor,modules,modules/_pending,plugins,plugins/_pending,templates,templates/_pending,lang-packs,lang-packs/_pending,public/build/ext'
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
        //  - storage/app/extension_backups, storage/app/core_backups: 확장/코어 업데이트·롤백의
        //    임시 백업 산출물 — php-fpm 이 mkdir/쓰기 하므로 g+w 정상화 필요 (sudo update 후
        //    root g-w 로 남으면 www-data 의 백업 디렉토리 생성이 mkdir Permission denied 로 실패)
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
        //  - public/build/ext: 정적 게시본(#122) — php-fpm 이 재게시/GC 를 수행하므로
        //    restore_ownership 과 같은 근거로 g+w 정상화 대상.
        'restore_ownership_group_writable' => array_filter(array_map('trim', explode(',', env(
            'G7_UPDATE_RESTORE_OWNERSHIP_GROUP_WRITABLE',
            'storage/logs,storage/framework,storage/app/core_pending,storage/app/extension_backups,storage/app/core_backups,bootstrap/cache,vendor,modules,modules/_pending,plugins,plugins/_pending,templates,templates/_pending,lang-packs,lang-packs/_pending,public/build/ext'
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
