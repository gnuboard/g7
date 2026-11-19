<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\LoginController;

/*
| SSO 연동 — 로그인 왕복 시작과 관리자 테스트 결과 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
*/

// 로그인 왕복 시작 — 브라우저가 이 주소로 오면 IdP 로 보낸다. link, reauth, test 는 1회용 시작 티켓만 받는다.
Route::get('/login/{idp}', [LoginController::class, 'start'])
    ->where('idp', '[a-z][a-z0-9_]{1,49}')
    ->middleware('throttle:30,1,sirsoft-sso.login-start')
    ->name('login');

// 관리자 「브라우저로 로그인 테스트」 결과 — 일회용(한 번 보면 지워짐), no-store.
Route::get('/test-result/{code}', [LoginController::class, 'testResult'])
    ->where('code', '[A-Za-z0-9]{40}')
    ->middleware('throttle:30,1,sirsoft-sso.test-result')
    ->name('test-result');
