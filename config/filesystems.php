<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // serve => false: Laravel 이 자동 생성하는 GET/PUT /storage/{path} 라우트를 노출하지
        // 않는다 (공개#52). 이 디스크들을 HTTP 로 직접 서빙하는 정상 흐름이 G7 에 없어
        // (업로드는 전부 /api/.../attachments + StorageInterface, 확장 에셋은 public/build),
        // 인증·권한 검사 없는 임의 경로 파일 쓰기(PUT) 입구를 닫는다. 디스크 자체는 그대로 동작.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'modules' => [
            'driver' => 'local',
            'root' => storage_path('app/modules'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'plugins' => [
            'driver' => 'local',
            'root' => storage_path('app/plugins'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'attachments' => [
            'driver' => 'local',
            'root' => storage_path('app/attachments'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'settings' => [
            'driver' => 'local',
            'root' => storage_path('app/settings'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        // 확장(모듈/플러그인) 프론트엔드 IIFE/CSS 번들 병합 결과 캐시.
        // ExtensionBundleService 가 version-in-path 파일명으로 저장/서빙한다.
        'ext-bundles' => [
            'driver' => 'local',
            'root' => storage_path('app/ext-bundles'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
