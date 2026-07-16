<?php

// 스케줄러 Shell/Artisan 실행 보안 정책.
// 관리자 환경설정에 노출하지 않는 코드 소유 정책 — command 실행 능력은 신뢰 경계 안에서만 넓힌다.
// (스케줄 권한만 위임받은 관리자가 화이트리스트를 넓혀 임의 명령 실행을 복구하는 것을 막는다.)
return [
    'shell' => [
        // Shell 타입 스케줄 실행 자체를 켜는 opt-in 게이트 (기본 차단).
        'enabled' => env('SCHEDULE_SHELL_ENABLED', false),

        // 허용 실행 파일 basename 목록. 빈 배열이면 모든 shell 명령을 거부한다.
        'allowed_binaries' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SCHEDULE_SHELL_ALLOWED_BINARIES', '')),
        ))),
    ],

    'artisan' => [
        // 파괴적·코드 실행형 artisan 명령 차단목록 (첫 토큰 기준).
        'denylist' => [
            'tinker',
            'db:wipe',
            'migrate:fresh',
            'migrate:reset',
            'migrate:rollback',
            'schedule:run',
            'schedule:work',
            'env:decrypt',
            'key:generate',
        ],
    ],
];
