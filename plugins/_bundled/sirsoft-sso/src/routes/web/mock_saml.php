<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController;

/*
| SSO 연동 — 모의 SAML IdP 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
| 게이트(`sirsoft-sso:mock --enable`)가 꺼져 있으면 `Plugin::getMiddleware()` 가 붙인 `MockGateMiddleware` 가 404 로 끝낸다
| (대상: `web.plugins.sirsoft-sso.mock.*` 서브트리 — 이 아래 라우트 이름은 전부 `mock.` 으로 시작해야 한다).
*/

// SP 의 POST 바인딩 요청과 승인 폼은 교차 사이트 POST 를 흉내 낸다 — 모의 IdP 는 CSRF 토큰을 쓰지 않는다.
Route::withoutMiddleware([ValidateCsrfToken::class])
    ->prefix('/mock/saml')
    ->name('mock.saml.')
    ->middleware('throttle:120,1,sirsoft-sso.mock')
    ->group(function () {
        Route::get('/metadata', [MockSamlController::class, 'metadata'])->name('metadata');
        Route::match(['get', 'post'], '/sso', [MockSamlController::class, 'sso'])->name('sso');
        Route::get('/initiate', [MockSamlController::class, 'initiate'])->name('initiate');
        Route::post('/approve', [MockSamlController::class, 'approve'])->name('approve');
        Route::get('/slo', [MockSamlController::class, 'slo'])->name('slo');
    });
