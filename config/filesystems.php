<?php

/*
|--------------------------------------------------------------------------
| 확장 저장 루트 (modules / plugins 디스크)
|--------------------------------------------------------------------------
|
| 테스트에서는 운영 데이터와 격리된 경로를 쓴다. 격리가 없으면 테스트가 실제
| `storage/app/modules/{id}/settings/*.json` 을 덮어써 운영 설정이 사라진다.
|
| 이 값이 확장 저장 위치의 단일 출처다. 각 확장이 `app()->runningUnitTests()` 로
| 같은 분기를 자기 안에 복사해 두면 한 곳만 빠뜨려도 그 확장의 테스트가 조용히
| 운영 파일을 건드린다 — 분기는 여기 한 곳에만 둔다.
|
*/
$extensionStorageRoot = env('APP_ENV') === 'testing' ? 'framework/testing' : 'app';
/**
 * 공개 디스크의 루트.
 *
 * `disks.public.root` 와 `links` 가 **같은 값을 봐야 한다** — 하나는 파일이 쌓이는 곳이고
 * 다른 하나는 웹에서 그 곳을 여는 링크의 대상이라, 갈리면 링크가 빈 트리를 가리킨다.
 * 두 자리에 따로 적으면 운영자가 한쪽만 고치고 그 사실은 어디에도 드러나지 않는다.
 */
$publicDiskRoot = storage_path('app/public');

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

        // modules · plugins · attachments · public — 확장·코어 스토리지 드라이버(StorageInterface)가
        // 게시판 슬러그·임시 키·상품 코드처럼 요청에서 온 값으로 경로를 만드는 디스크다.
        // local-safe 는 local 과 같되 `..` 상대경로 순회를 어댑터 직전에서 거부한다
        // (AppServiceProvider::createTraversalSafeLocalDisk). 드라이버의 경로 가드가 1차 방어,
        // 이것이 최후 방어선이다. 사용자 정의 드라이버라 serve=true 서명 URL 서빙은 지원하지 않는다.
        'modules' => [
            'driver' => 'local-safe',
            'allow_relative_path_traversal' => false,
            'root' => storage_path($extensionStorageRoot.'/modules'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'plugins' => [
            'driver' => 'local-safe',
            'allow_relative_path_traversal' => false,
            'root' => storage_path($extensionStorageRoot.'/plugins'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'attachments' => [
            'driver' => 'local-safe',
            'allow_relative_path_traversal' => false,
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
        //
        // `permissions` 선언이 없으면 Flysystem 이 디스크 인스턴스화 시점에 root 를
        // `0700` 으로 만든다. 그러면 **먼저 touch 한 프로세스가 독점**한다 — CLI 가 먼저면
        // 이후 php-fpm 의 쓰기가 `UnableToWriteFile` 로 죽고, `throw => true` 라 그 예외가
        // 공개 번들 엔드포인트의 500 으로 그대로 나간다. 디스크 생성 시점이 유일한 예방
        // 지점이므로 여기서 그룹 쓰기를 열어 둔다 (실패 시 fail-soft 는 서비스가 담당).
        'ext-bundles' => [
            'driver' => 'local',
            'root' => storage_path('app/ext-bundles'),
            'serve' => false,
            'throw' => true,
            'report' => false,
            'permissions' => [
                'file' => ['public' => 0664, 'private' => 0664],
                'dir' => ['public' => 0775, 'private' => 0775],
            ],
        ],

        'public' => [
            'driver' => 'local-safe',
            'allow_relative_path_traversal' => false,
            'root' => $publicDiskRoot,
            // 기본값은 형제 설정(`config/app.php` 의 `url`)과 같아야 한다 — 여기만
            // 기본값 없이 읽으면 `APP_URL` 미설정 사이트에서 이 값이 `/storage` 가 되어
            // 오리진과 마운트 접두사를 함께 잃는다(메일 본문의 이미지·하위 폴더 배치).
            'url' => env('APP_URL', 'http://localhost').'/storage',
            'visibility' => 'public',
            // 형제 여섯 디스크와 같은 명시 선언 — 이 디스크만 프레임워크 기본값에 기대면
            // 기본값이 바뀌는 순간 무인증 `PUT /storage/{path}` 입구가 여기서만 열린다.
            // 링크 불가 호스팅의 읽기 전용 서빙은 별도 라우트(`/api/system/public-storage`)다.
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            // 빈 값(`AWS_URL=`/`AWS_ENDPOINT=`)은 미설정으로 정규화 — env() 는 키가
            // 존재하면 빈 문자열을 돌려주므로, '' 가 endpoint 로 주입되면 S3 클라이언트
            // 구성이 깨진다 (cp .env.example 설치 절차 방어).
            'url' => env('AWS_URL') ?: null,
            'endpoint' => env('AWS_ENDPOINT') ?: null,
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
        // 링크 대상은 공개 디스크의 **root 와 같은 출처**여야 한다.
        //
        // 종전에는 이 자리만 리터럴이라, 운영자가 위 `disks.public.root` 를 옮긴 사이트에서
        // `storage:link` 가 **옛 위치**를 가리키는 링크를 만들었다. 그러면 파일을 쓰는 쪽과
        // 서빙하는 쪽(`PublicStorageService::rootPath()`)은 새 루트를 보는데 링크만 옛 트리를
        // 가리켜, 공개 자산이 오류도 로그도 없이 전부 404 가 된다 — 코어 복구기가 22·23차에
        // 걸쳐 닫은 것과 **같은 결함의 세 번째 출처**였다 (23차 G3 라운드 D23-3).
        public_path('storage') => $publicDiskRoot,
    ],

];
