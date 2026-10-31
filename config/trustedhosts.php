<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Hosts
    |--------------------------------------------------------------------------
    |
    | 요청 Host 헤더가 사이트 주소(APP_URL)의 도메인·하위 도메인·www 쌍 또는 아래 목록과 맞지 않으면
    | 요청을 받지 않는다(404). Host 는 방문자가 위조할 수 있는 값이라, 그 값으로 절대 URL 을
    | 만드는 코드가 남아 있을 때의 마지막 방어선이다. App\Support\TrustedHosts 참고.
    | www 쌍: APP_URL 이 www.example.com 이면 example.com 도(정확히 일치), example.com 이면
    | www.example.com 도 자동 허용한다. IP 주소·localhost 에는 쌍이 없다.
    |
    |   enabled : false 면 검사하지 않는다 — 다른 도메인으로 접속이 막혔을 때의 비상 스위치.
    |             근본 해결은 APP_URL 을 실제 사이트 주소로 맞추거나 hosts 에 추가하는 것이다.
    |   hosts   : 사이트 주소와 다른 도메인으로도 서빙하면 콤마로 나열한다(정확히 일치만 허용).
    |   canonical_redirect : 사이트 주소가 아닌 Host(하위 도메인·www 쌍·포트·별칭)로 들어온 화면 요청을
    |             같은 경로의 사이트 주소로 301 이동시킨다. API·확장 라우트(결제·본인인증 콜백)·
    |             사이트맵·정적 파일은 대상이 아니다. App\Http\Middleware\RedirectToCanonicalHost 참고.
    |
    | 설치 완료 전과 로컬 개발(APP_ENV=local)에서는 검사하지 않는다.
    |
    */

    'enabled' => env('TRUSTED_HOSTS_ENABLED', true),

    'hosts' => env('TRUSTED_HOSTS'),

    'canonical_redirect' => env('CANONICAL_HOST_REDIRECT', true),

];
