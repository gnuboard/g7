<?php

/*
|--------------------------------------------------------------------------
| SSO 연동 플러그인 웹 라우트
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/sirsoft-sso (PluginRouteServiceProvider 자동 적용)
| 이름 접두: web.plugins.sirsoft-sso.
| 미들웨어: web (자동 적용)
|
| 영역별 파일로 나눈다 — 영역(흐름, 프로토콜, 로그아웃, 모의 IdP)마다 다른 작업자가 같은 파일을 고치지 않게.
|
*/

foreach (['login', 'oidc', 'saml', 'logout', 'backchannel', 'mock', 'mock_saml'] as $area) {
    require __DIR__.'/web/'.$area.'.php';
}
