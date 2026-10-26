<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\BackchannelController;

/*
| SSO 연동 — OIDC back-channel 로그아웃 수신 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
*/

// IdP 서버 → G7 직접 호출. CSRF 토큰이 없다 — 위조 방어는 logout_token 서명 검증과 jti 1회 사용이 맡는다.
Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/oidc/{idp}/backchannel-logout', [BackchannelController::class, 'logout'])
        ->where('idp', '[a-z][a-z0-9_]{1,49}')
        ->middleware('throttle:120,1,sirsoft-sso.oidc-backchannel')
        ->name('oidc.backchannel');
});
