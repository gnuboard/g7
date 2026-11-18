<?php

/*
|--------------------------------------------------------------------------
| storage 경로 분류
|--------------------------------------------------------------------------
|
| `storage/` 아래 경로를 성격에 따라 네 목록으로 나눈 선언이다. 백업 대상을 고르거나, 같은 DB 를
| 쓰는 서버를 여러 대 둘 때 서버끼리 나눠 써야 하는 경로와 서버마다 따로 둬야 하는 경로를 정할 때
| 쓴다. 코어는 이 목록으로 동작을 바꾸지 않는다 — 목록 확인: `php artisan storage:paths [--json]`.
|
| 값은 `storage/` 기준 상대 경로다. 디렉터리는 그 아래 전부를 뜻하고, `-`·`_` 로 끝나는 값은 그
| 이름으로 시작하는 파일·디렉터리(예: 시각이 붙는 파일)를 뜻한다.
|
| - shared_data  : 운영 데이터. DB 행과 짝이 맞아야 하므로 서버가 여러 대면 함께 써야 하고, 백업에 넣는다.
| - server_local : 서버마다 따로 두는 파일. 다시 만들 수 있거나(번들·썸네일·사이트맵 파일) 그 서버의
|                  실행 상태(설치 표식·스케줄 실행 기록·로그)다. 서버끼리 나누지 않는다.
| - update_state : 코어·확장 업데이트가 쓰는 백업·대기 파일·진행 기록. 업데이트를 실행한 서버의 것이다.
| - temporary    : 작업 중에만 쓰는 파일. 언제 지워도 된다.
|
| 사이트맵 파일(`app/private/cache/sitemap`)은 서버마다 따로 만든다 — 그 메타 정보만 공유 캐시에
| 있으므로, 여러 서버가 한 캐시를 쓰면 파일과 메타가 서로 다른 서버의 것일 수 있다.
|
| 코어 업데이트는 이 분류와 관계없이 `storage` 전체를 덮지도 지우지도 않는다
| (`config/app.php` 의 `update.protected_paths`).
|
| 새 저장 경로를 코드에 더하면 이 목록 중 하나에 넣는다 — 빠지면 아키텍처 테스트가 실패한다
| (tests/Unit/Architecture/StoragePathsClassifiedTest.php).
*/

return [
    'shared_data' => [
        'app/settings',
        'app/modules',
        'app/plugins',
        'app/templates',
        'app/attachments',
        'app/public',
    ],

    'server_local' => [
        'app/private',
        'app/private/cache/sitemap',
        'app/g7_installed',
        'app/schedule_security.json',
        'app/schedule/run-state.json',
        'app/schedule/web-cron-token.json',
        'app/geoip',
        'app/analytics/cloud-ip-ranges.json',
        'app/ext-bundles',
        'app/thumbnails',
        'app/public-storage-broken-',
        'framework',
        'logs',
        'debug-dump',
        'installer',
        'installer-state.json',
        'installer-probe-rate.json',
    ],

    'update_state' => [
        'app/extension_backups',
        'app/extension-custom-backups',
        'app/extension_update_journal',
        'app/extension-carried-class-files.json',
        'app/extension_update_previews',
        'app/core_pending',
        'app/core_backups',
        'app/core-update',
        'app/core_update',
        'app/core-update.lock',
    ],

    'temporary' => [
        'app/temp',
        'app/tmp',
        'app/vendor-bundle-staging',
        'app/benchmarks',
        'temp',
        'composer',
    ],
];
