<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\OidcController;

/*
| SSO 연동 — OIDC 콜백 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
*/

// IdP → 브라우저 콜백. IdP 는 CSRF 토큰을 보내지 않는다 — 위조 방어는 1회용 state 와 브라우저 묶음 쿠키가 맡는다.
Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::match(['get', 'post'], '/oidc/{idp}/callback', [OidcController::class, 'callback'])
        ->where('idp', '[a-z][a-z0-9_]{1,49}')
        ->middleware('throttle:60,1,sirsoft-sso.oidc-callback')
        ->name('oidc.callback');
});
