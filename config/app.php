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
    | 사용자가 선택 가능한 timezone을 제한합니다.
    |
    */

    'supported_timezones' => [
        'Asia/Seoul',
        'Asia/Tokyo',
        'America/New_York',
        'America/Los_Angeles',
        'Europe/London',
        'Europe/Paris',
        'UTC',
    ],

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

    'version' => env('APP_VERSION', '7.0.0-beta.1'),

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
        'targets' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_TARGETS', 'app,bootstrap,config,database,docs,lang,resources,routes,public,tests,upgrades,artisan,composer.json,composer.json.default,composer.lock,package.json,package-lock.json,vite.config.js,vite.config.core.js,vitest.config.ts,tsconfig.json,phpunit.xml,.editorconfig,.gitattributes,.gitignore,README.md,CHANGELOG.md,modules/_bundled,plugins/_bundled,templates/_bundled')))),
        'excludes' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_EXCLUDES', 'node_modules,.git,bootstrap/cache')))),
        'backup_only' => array_filter(array_map('trim', explode(',', env('G7_UPDATE_BACKUP_ONLY', 'vendor')))),
        'backup_extra' => ['storage/app/settings'],
    ],

];
