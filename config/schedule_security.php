<?php

// 스케줄러 Shell/Artisan 실행 보안 정책.
// 관리자 환경설정에 노출하지 않는 코드 소유 정책 — command 실행 능력은 신뢰 경계 안에서만 넓힌다.
// (스케줄 권한만 위임받은 관리자가 화이트리스트를 넓혀 임의 명령 실행을 복구하는 것을 막는다.)
return [
    'shell' => [
        // Shell 타입 스케줄 실행 자체를 켜는 opt-in 게이트 (기본 차단).
        'enabled' => env('SCHEDULE_SHELL_ENABLED', false),

        // 허용 실행 파일 basename 목록. 빈 배열이면 모든 shell 명령을 거부한다.
        // Shell 타입은 범용 크론탭 대체이므로 운영자가 python/node/php/bash 등
        // 인터프리터를 등록해 기존 스케줄러 스크립트를 주기 실행할 수 있다.
        'allowed_binaries' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SCHEDULE_SHELL_ALLOWED_BINARIES', '')),
        ))),

        // 아래 세 목록은 코드 소유 리터럴이다(env 아님) — 스케줄 권한만 위임받은 관리자가
        // 정책을 넓혀 인라인 코드 실행을 복구하지 못하게 한다.

        // 스크립트 실행형 인터프리터 — 첫 인자를 "스크립트 파일 경로" 로 강제한다.
        // 위험한 것은 인터프리터 자체가 아니라 인터프리터에게 인라인 코드/명령을 넘기는 것이므로
        // (하이픈 선두=인라인 코드 플래그 거부, 절대경로 요구, `..`/`#` 금지, basename≠artisan)
        // 를 적용해 스크립트 파일 실행만 허용한다.
        'script_interpreters' => [
            'bash', 'sh', 'dash', 'zsh', 'ksh', 'csh', 'tcsh', 'ash', 'fish',
            'php', 'php-cgi', 'python', 'python2', 'python3',
            'perl', 'ruby', 'node', 'nodejs', 'lua', 'luajit', 'tclsh',
            'rscript', 'pwsh', 'powershell',
        ],

        // 완전 거부형 — 첫 인자가 코드/애플릿/감싼 명령이라 "스크립트 파일" 모델이 성립하지 않는다.
        // allowed_binaries 에 등재돼 있어도 실행하지 않는다.
        'reject_binaries' => [
            'env', 'xargs', 'sudo', 'doas', 'make', 'awk', 'gawk', 'mawk', 'nawk', 'sed', 'find',
            'busybox', 'nohup', 'timeout', 'watch', 'nice', 'ionice', 'setsid', 'stdbuf',
            'script', 'expect', 'socat', 'nc', 'ncat', 'tee', 'flock', 'chroot', 'unshare',
        ],

        // 인터프리터 뒤 스크립트 자리에 올 수 없는 basename — 코어 Artisan 축 우회 방지.
        // `php artisan ...` 는 Artisan 타입으로 유도한다.
        'denied_script_names' => ['artisan'],
    ],

    'artisan' => [
        // 실행을 허용하는 artisan 명령 목록 (허용목록 — 여기 없으면 거부).
        //
        // 차단목록 방식은 등록 명령 238개 중 9개만 막아 나머지를 무조건 허용했고,
        // `migrate:refresh` 가 빠져 있는 등 "빠뜨림" 이 구조적으로 반복됐다.
        // 코드 생성·스키마 변경·비밀값 접근이 없는 유지보수 명령만 등재한다.
        //
        // 각 항목의 `options` 는 허용 롱옵션 이름(`--` 없이)이며, 선언되지 않은 옵션은 거부한다.
        // 실제 시그니처(`getDefinition()`)를 확인해 채운 값이다 — 경로·클래스·파일을 지정하거나
        // 안전장치를 무력화하는 옵션(`--path` `--model` `--force` `--daemon` 등)은 제외했다.
        // `max_arguments` 는 허용 위치 인자 개수이며 생략 시 0 이다.
        'allowlist' => [
            // 캐시·컴파일 산출물 정리
            'cache:clear' => ['options' => [], 'max_arguments' => 0],
            'config:clear' => ['options' => []],
            'route:clear' => ['options' => []],
            'view:clear' => ['options' => []],
            'event:clear' => ['options' => []],
            'optimize:clear' => ['options' => []],
            'hooks:clear' => ['options' => []],
            'hooks:cache' => ['options' => []],

            // 큐
            'queue:restart' => ['options' => []],
            'queue:prune-failed' => ['options' => ['hours']],
            'queue:prune-batches' => ['options' => ['hours', 'unfinished', 'cancelled']],
            'queue:work' => ['options' => [
                'queue', 'once', 'stop-when-empty', 'stop-when-empty-for',
                'delay', 'backoff', 'max-jobs', 'max-time',
                'memory', 'sleep', 'rest', 'timeout', 'tries',
            ]],

            // 만료 데이터 정리
            'auth:clear-resets' => ['options' => []],
            'sanctum:prune-expired' => ['options' => ['hours']],
            'model:prune' => ['options' => ['chunk', 'pretend']],
            'notification:cleanup' => ['options' => []],
            'layout-previews:cleanup' => ['options' => []],
            'ext-bundles:cleanup' => ['options' => []],
            'seo:prune-stats' => ['options' => ['days']],
            'schedules:prune-history' => ['options' => ['days']],
            'identity:expire-challenges' => ['options' => []],
            'identity:prune-logs' => ['options' => ['days']],
            'activity-log:prune' => ['options' => ['days']],
            'notification-log:prune' => ['options' => ['days']],
            // 실삭제가 기본이나 사용자 파일 파기는 커맨드 자체가 설정 토글·보존기간으로 이중 확인한다.
            'attachments:prune-orphans' => ['options' => ['dry-run', 'limit', 'days', 'scheduled']],
            // 잔존물 정리 — 최신 백업 1개 상시 보존 가드는 커맨드 내부 소유(옵션으로 무력화 불가).
            'storage:prune-leftovers' => ['options' => ['days', 'backup-days', 'dry-run']],

            // SEO
            'seo:warmup' => ['options' => ['layout']],
            'seo:clear' => ['options' => ['layout']],
            'seo:generate-sitemap' => ['options' => ['sync', 'rebuild', 'mode']],

            // 기타 유지보수
            'geoip:update' => ['options' => ['dry-run']],
            'search:index' => ['options' => ['filter']],
            'inspire' => ['options' => []],
        ],

        // 설치된 확장이 소유한 명령을 자동으로 허용할지 여부.
        // 확장 설치는 서버에 코드를 배치하는 행위로 이미 스케줄 권한보다 높은 신뢰를 요구하고,
        // 그 확장의 getSchedules() 명령은 이미 무인 실행되고 있다. 필요 시 끌 수 있다.
        'allow_extension_commands' => true,

        // 확장 소유 판정 기준 — 명령 인스턴스의 클래스 네임스페이스 접두사.
        // Symfony 가 실제 실행에 쓰는 레지스트리를 보므로 이름만으로는 위조할 수 없다.
        'extension_namespaces' => ['Modules\\', 'Plugins\\'],

        // 최종 거부권. 허용목록·확장 자동 허용보다 먼저 평가되어,
        // 확장이 코어 명령 이름을 가로채 등록하는 경우도 막는다.
        'denylist' => [
            // 임의 코드 실행
            'tinker',
            'invoke-serialized-closure',
            // 스키마·데이터 파괴
            'db',
            'db:wipe',
            'db:seed',
            'schema:dump',
            'migrate',
            'migrate:fresh',
            'migrate:reset',
            'migrate:rollback',
            'migrate:refresh',
            'migrate:install',
            // 비밀값·환경
            'env',
            'env:encrypt',
            'env:decrypt',
            'key:generate',
            // 서비스 상태 조작
            'schedule:run',
            'schedule:work',
            'serve',
            'down',
            'up',
            'test',
            'clear-compiled',
            // 파일 배치·코어/확장 변경
            'stub:publish',
            'vendor:publish',
            'package:discover',
            'core:update',
            'core:build',
            'core:execute-upgrade-steps',
            'core:execute-bundled-updates',
            'extension:composer-install',
            'module:install',
            'plugin:install',
            'template:install',
            'module:seed',
            'plugin:seed',
            'hotfix:rollback-stale-files',
            'playwright:issue-token',
        ],

        // 접두사 단위 거부권. `make:*` 41개를 일일이 나열하지 않는다.
        'denylist_prefixes' => ['make:'],
    ],
];
