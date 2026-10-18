<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 성능 계측 프로파일 (Benchmark Profiles)
    |--------------------------------------------------------------------------
    |
    | `g7:bench` 커맨드가 재는 코어 계측 대상 선언입니다. 확장은 자기 대상을
    | `getBenchmarkProfiles()` 오버라이드로 선언하며(모듈/플러그인 공통), 스키마는
    | 여기와 동일합니다. 계측 대상을 커맨드에 하드코딩하지 않는 이유는, 확장이
    | 설치·제거되는 설치본마다 "실제로 존재하는 대상"이 다르기 때문입니다.
    |
    | 공통 필드
    |   type   : list | screen | write | batch  (필수)
    |   label  : 표시용 설명 (선택)
    |
    | type=list — 목록 SELECT 비용 (전체 컬럼 / 목록 컬럼 / 키 컬럼 3축)
    |   table          : 대상 테이블 (필수)
    |   columns        : 목록이 실제로 select 하는 컬럼. ['*'] 는 "응답 계약상 전 컬럼
    |                    노출이라 프루닝 불가" 선언이며 이때 비교축은 select * vs select id
    |   order          : [[컬럼, 방향], ...]
    |   filters        : 화면이 실제로 거는 필터. 필터 없이 재면 인덱스 선택이 달라져 화면에서
    |                    일어나는 일과 다른 것을 잰다. 두 형태를 받는다 —
    |                      등가:   ['board_id' => 1]
    |                      연산자: ['order_status' => ['not in', [...]]]
    |                    연산자 닫힌 집합: = != <> < <= > >= like in "not in".
    |                    목록에 없는 연산자는 측정 전에 사유와 함께 거부한다.
    |                    선언형으로 재현 못 하는 술어(상관 서브쿼리·권한 스코프)가 지배적인
    |                    목록은 프로파일을 두지 않는다 — 어느 화면도 내지 않는 수치가 된다.
    |   soft_delete    : true 면 deleted_at IS NULL 부착
    |   seed_overrides : 합성 시딩 시 고정할 컬럼값 (filters 와 맞출 용도)
    |
    | type=screen — 화면 1장 응답 시간 + 실행 쿼리 건수 + N+1 후보
    |   route       : 라우트명 (권장 — URI 프리픽스 조립 금지, 사라지면 즉시 실패)
    |   route_params: 라우트 파라미터
    |   uri         : 라우트명이 없을 때만 쓰는 원시 경로
    |   method      : HTTP 메서드 (기본 GET)
    |   query       : 쿼리스트링 [키 => 값]
    |   permissions : 계측용 임시 계정에 부여할 권한 식별자 목록
    |
    | type=write — 저장 경로 1회 소요 시간
    |   callback : 'Fqcn' (invokable) 또는 ['Fqcn', 'method']. 클로저는 config:cache 를
    |              깨뜨리므로 금지하며 레지스트리가 거부한다
    |   cleanup  : 동일 형식. 측정으로 생긴 행을 되돌린다
    |
    | type=batch — 배치 커맨드 소요 시간 + 피크 메모리
    |   command   : artisan 커맨드명
    |   arguments : 커맨드 인자/옵션 [키 => 값]
    |
    */

    'profiles' => [

        // --- 목록 조회 (list) -------------------------------------------------

        // 활동 로그와 알림 발송 이력은 응답 계약상 넓은 컬럼(changes/properties, body)을
        // 그대로 노출하므로 컬럼 프루닝이 불가하다. 지연 조인만 적용했고 계측 비교축은
        // select * vs select id 다.
        'activity_logs' => [
            'type' => 'list',
            'label' => '활동 로그 목록',
            'table' => 'activity_logs',
            'columns' => ['*'],
            'order' => [['created_at', 'desc'], ['id', 'desc']],
            'soft_delete' => false,
        ],

        'notification_logs' => [
            'type' => 'list',
            'label' => '알림 발송 이력 목록',
            'table' => 'notification_logs',
            'columns' => ['*'],
            'order' => [['created_at', 'desc'], ['id', 'desc']],
            'soft_delete' => false,
        ],

        // 회원 테이블은 소프트 삭제 컬럼이 없다 (탈퇴는 status 로 표현) — 실제 스키마 기준 선언
        'users' => [
            'type' => 'list',
            'label' => '회원 목록',
            'table' => 'users',
            'columns' => ['*'],
            'order' => [['created_at', 'desc'], ['id', 'desc']],
            'soft_delete' => false,
        ],

        // --- 화면 응답 (screen) -----------------------------------------------

        'activity_logs_screen' => [
            'type' => 'screen',
            'label' => '관리자 활동 로그 목록 화면',
            'route' => 'api.admin.activity-logs.index',
            'query' => ['per_page' => 20],
            'permissions' => ['core.activities.read'],
        ],

        'users_screen' => [
            'type' => 'screen',
            'label' => '관리자 회원 목록 화면',
            'route' => 'api.admin.users.index',
            'query' => ['per_page' => 20],
            'permissions' => ['core.users.read'],
        ],

        // --- 배치 작업 (batch) ------------------------------------------------

        'sitemap' => [
            'type' => 'batch',
            'label' => '사이트맵 생성',
            'command' => 'seo:generate-sitemap',
            'arguments' => ['--sync' => true],
        ],

    ],
];
