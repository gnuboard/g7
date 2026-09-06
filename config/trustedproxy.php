<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | 리버스 프록시(AWS ALB, CloudFront, Cloudflare, nginx/Apache, ngrok) 뒤에서
    | 구동될 때 신뢰할 프록시를 지정한다. Laravel 내장 TrustProxies 미들웨어가
    | 요청 시점에 이 값을 폴백으로 읽어 X-Forwarded-* 헤더 신뢰 여부를 결정한다
    | (Illuminate\Http\Middleware\TrustProxies::setTrustedProxyIpAddresses()).
    |
    |   null (미설정) : 아무 프록시도 신뢰하지 않음 — 기본값, 기존 설치처 동작 불변
    |   '*'           : 직전 호출 IP(REMOTE_ADDR)만 신뢰 — 동일 호스트 프록시·ALB
    |   '**'          : 내장 미들웨어에서는 '*' 과 동일하게 동작한다(호환 표기)
    |   'IP,IP/CIDR'  : 콤마 구분 목록만 신뢰
    |
    | 프록시가 여러 단인 구성(CloudFront → ALB 등)에서는 '*' 도 '**' 도 부족하다.
    | 둘 다 직전 호출 IP 하나만 신뢰하므로 X-Forwarded-For 체인의 마지막 프록시가
    | 방문자로 기록된다. 그 구성에서는 체인의 모든 프록시 IP·CIDR 를 나열해야
    | 최초 클라이언트 IP 가 해석된다 (실측 확인: docs/backend/reverse-proxy.md).
    |
    | '*' 은 앱이 프록시를 거치지 않고는 도달 불가한 구성에서만 안전하다.
    | 앱이 직접 노출된 상태에서 사용하면 방문자가 X-Forwarded-For 를 위조해
    | 기록 IP 와 IP 기반 제한을 조작할 수 있다. 상세: docs/backend/reverse-proxy.md
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
