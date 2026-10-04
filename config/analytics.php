<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 방문자 통계 — 코드 상수
    |--------------------------------------------------------------------------
    | 운영자가 관리자 화면에서 바꾸는 값은 설정 그룹 `analytics`(storage/app/settings/
    | analytics.json, 기본값은 config/settings/defaults.json)에 있다. 이 파일은 운영자가
    | 바꿀 일이 없고 코드가 판정 기준으로 쓰는 표 — 검색엔진 목록, 헤드리스 패턴,
    | prefetch 헤더, 클라우드 IP 목록 출처, 쿠키 이름 — 만 담는다.
    */

    /*
    |--------------------------------------------------------------------------
    | 쿠키
    |--------------------------------------------------------------------------
    | visitor: 방문자 식별 쿠키. 서버가 Set-Cookie 로 심는다(HttpOnly).
    | opt_out: 「내 방문은 세지 않기」 쿠키. 트래커가 document.cookie 로 읽어야 하므로
    |          HttpOnly 가 아니다.
    */
    'cookies' => [
        'visitor' => 'g7_vid',
        'opt_out' => 'g7_analytics_optout',
    ],

    /*
    |--------------------------------------------------------------------------
    | 검색엔진 표 (ReferrerClassifier)
    |--------------------------------------------------------------------------
    | host: referrer 호스트가 이 문자열로 끝나면 해당 검색엔진으로 본다.
    | params: 검색어가 담기는 쿼리 파라미터 후보(앞에서부터 처음 발견된 값을 쓴다).
    |         HTTPS 기본화 이후 대부분의 엔진이 검색어를 넘기지 않으므로 비어 있는 것이
    |         정상이다 — 유입 경로 › 검색어 화면이 이 사실을 힌트로 알린다.
    */
    'search_engines' => [
        ['name' => 'Google', 'hosts' => ['google.com', 'google.co.kr', 'google.co.jp'], 'params' => ['q']],
        ['name' => 'Naver', 'hosts' => ['naver.com', 'search.naver.com'], 'params' => ['query']],
        ['name' => 'Daum', 'hosts' => ['daum.net', 'search.daum.net'], 'params' => ['q']],
        ['name' => 'Bing', 'hosts' => ['bing.com'], 'params' => ['q']],
        ['name' => 'Yahoo', 'hosts' => ['yahoo.com', 'yahoo.co.jp', 'search.yahoo.com'], 'params' => ['p', 'q']],
        ['name' => 'DuckDuckGo', 'hosts' => ['duckduckgo.com'], 'params' => ['q']],
        ['name' => 'Baidu', 'hosts' => ['baidu.com'], 'params' => ['wd', 'word']],
        ['name' => 'Yandex', 'hosts' => ['yandex.com', 'yandex.ru'], 'params' => ['text']],
        ['name' => 'Zum', 'hosts' => ['zum.com', 'search.zum.com'], 'params' => ['query']],
        ['name' => 'Nate', 'hosts' => ['nate.com'], 'params' => ['q']],
    ],

    /*
    |--------------------------------------------------------------------------
    | 캠페인 파라미터
    |--------------------------------------------------------------------------
    | 유입 URL 에서 캠페인을 뽑고 나면 URL 정규화 단계가 지운다(같은 페이지가 캠페인마다
    | 다른 URL 로 집계되는 것을 막는다).
    */
    'campaign_params' => [
        'name' => 'utm_campaign',
        'source' => 'utm_source',
        'medium' => 'utm_medium',
    ],

    /*
    |--------------------------------------------------------------------------
    | URL 정규화 시 제거할 추적 파라미터
    |--------------------------------------------------------------------------
    */
    'stripped_query_params' => [
        'utm_campaign', 'utm_source', 'utm_medium', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', 'igshid',
        // 보안: 비밀번호 재설정·본인인증·서명 링크의 증표와 이메일은 통계(페이지 이름·유입 주소)에 남기지 않는다.
        'token', 'email', 'verification_token', 'challenge_id', 'signature', 'expires',
    ],

    /*
    |--------------------------------------------------------------------------
    | 헤드리스 브라우저 패턴 (block_headless)
    |--------------------------------------------------------------------------
    | User-Agent 에 이 문자열이 있으면(대소문자 무시) 자동화 도구로 본다. 비콘의
    | `wd:true`(navigator.webdriver)도 같은 사유로 제외된다.
    */
    'headless_patterns' => [
        'HeadlessChrome',
        'PhantomJS',
        'Playwright',
        'Puppeteer',
        'Selenium',
        'Cypress',
    ],

    /*
    |--------------------------------------------------------------------------
    | prefetch·prerender 요청 헤더 (exclude_prefetch)
    |--------------------------------------------------------------------------
    | 헤더 이름 => 이 값들 중 하나면 미리 가져오기로 본다. 사람이 실제로 본 화면이
    | 아니므로 방문으로 세지 않는다.
    */
    'prefetch_headers' => [
        'X-Purpose' => ['preview', 'prefetch'],
        'Purpose' => ['prefetch'],
        'Sec-Purpose' => ['prefetch', 'prefetch;prerender'],
        'X-Moz' => ['prefetch'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 클라우드 IP 대역 목록 (block_cloud_ip_ranges)
    |--------------------------------------------------------------------------
    | 기본은 꺼짐. 켜면 analytics:update-cloud-ranges 가 아래 출처를 내려받아
    | storage_path 에 저장하고 주 1회 갱신한다. 파일이 없으면 진단 화면이 경고한다.
    */
    'cloud_ip_ranges' => [
        'storage_path' => 'analytics/cloud-ip-ranges.json',
        'sources' => [
            'aws' => 'https://ip-ranges.amazonaws.com/ip-ranges.json',
            'gcp' => 'https://www.gstatic.com/ipranges/cloud.json',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 코어 전환(목표) 선언
    |--------------------------------------------------------------------------
    | 확장의 getAnalyticsGoals() 와 같은 스키마다. 기록 키는 core.{key} 로 정규화된다.
    | 서버가 확실히 아는 순간(가입 완료·로그인 성공)에 CoreGoalsListener 가 기록한다.
    */
    'goals' => [
        [
            'key' => 'user_registered',
            'label' => ['ko' => '회원가입', 'en' => 'Sign-up', 'ja' => '会員登録'],
            'description' => ['ko' => '회원가입이 완료된 시점', 'en' => 'When a sign-up completes', 'ja' => '会員登録が完了した時点'],
            'category' => 'signup',
            'dedupe' => 'once_per_visitor',
        ],
        [
            'key' => 'user_logged_in',
            'label' => ['ko' => '로그인', 'en' => 'Log-in', 'ja' => 'ログイン'],
            'description' => ['ko' => '로그인에 성공한 시점', 'en' => 'When a log-in succeeds', 'ja' => 'ログインに成功した時点'],
            'category' => 'engagement',
            'dedupe' => 'once_per_visit',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 값 한계
    |--------------------------------------------------------------------------
    | 설정 화면이 막지 못하는 경로(직접 API 호출·과거 데이터)에서도 원장이 이상한 값을
    | 갖지 않도록 서비스가 이 한계로 자른다.
    */
    'limits' => [
        'max_time_spent_seconds' => 1800,
        'retention_floor_days' => 7,
        'salt_retention_days' => 5,
        'prune_chunk_size' => 1000,
    ],

];
