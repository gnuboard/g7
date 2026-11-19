<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\SamlController;

/*
| SSO 연동 — SAML ACS, SLS, 메타데이터 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
*/

Route::get('/saml/{idp}/metadata', [SamlController::class, 'metadata'])
    ->where('idp', '[a-z][a-z0-9_]{1,49}')
    ->middleware('throttle:60,1,sirsoft-sso.saml-metadata')
    ->name('saml.metadata');

// IdP → 브라우저 → SP 의 교차 사이트 POST/GET. IdP 는 CSRF 토큰을 보내지 않는다 — 위조 방어는 state·묶음 쿠키(SP-initiated),
// IdP-initiated 허용 설정과 응답 1회 사용, 메시지 서명이 맡는다.
Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/saml/{idp}/acs', [SamlController::class, 'acs'])
        ->where('idp', '[a-z][a-z0-9_]{1,49}')
        ->middleware('throttle:60,1,sirsoft-sso.saml-acs')
        ->name('saml.acs');

    Route::match(['get', 'post'], '/saml/{idp}/sls', [SamlController::class, 'sls'])
        ->where('idp', '[a-z][a-z0-9_]{1,49}')
        ->middleware('throttle:60,1,sirsoft-sso.saml-sls')
        ->name('saml.sls');
});
