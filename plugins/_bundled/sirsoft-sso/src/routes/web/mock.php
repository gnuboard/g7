<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Mock\Oidc\MockOidcController;

/*
| SSO 연동 — 모의 OIDC IdP 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
| 게이트(`sirsoft-sso:mock --enable`)가 꺼져 있으면 `Plugin::getMiddleware()` 가 붙인 `MockGateMiddleware` 가 404 로 끝낸다
| (대상: `web.plugins.sirsoft-sso.mock.*` 서브트리 — 이 아래 라우트 이름은 전부 `mock.` 으로 시작해야 한다).
*/

// 토큰 주소는 서버 간 요청이라 CSRF 토큰이 없다.
Route::withoutMiddleware([ValidateCsrfToken::class])
    ->prefix('/mock/oidc')
    ->name('mock.oidc.')
    ->middleware('throttle:120,1,sirsoft-sso.mock')
    ->group(function () {
        Route::get('/.well-known/openid-configuration', [MockOidcController::class, 'discovery'])->name('discovery');
        Route::get('/jwks', [MockOidcController::class, 'jwks'])->name('jwks');
        Route::match(['get', 'post'], '/authorize', [MockOidcController::class, 'authorize'])->name('authorize');
        Route::post('/token', [MockOidcController::class, 'token'])->name('token');
        Route::get('/end-session', [MockOidcController::class, 'endSession'])->name('end-session');
    });
