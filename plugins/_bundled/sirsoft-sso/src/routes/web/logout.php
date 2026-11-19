<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\LogoutController;

/*
| SSO 연동 — 로그아웃 이동과 복귀 라우트 (web)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/web.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/plugins/sirsoft-sso).
*/

// IdP 에서 돌아오는 주소(IdP 콘솔의 Post logout redirect URI) — `{idp}` 보다 먼저 둔다.
Route::get('/logout/return', [LogoutController::class, 'return'])
    ->middleware('throttle:60,1,sirsoft-sso.logout-return')
    ->name('logout.return');

// 코어 로그아웃 응답의 `redirect_url` — 1회용 티켓만 받는다(세션 id 를 주소에 싣지 않는다).
Route::get('/logout/{idp}', [LogoutController::class, 'start'])
    ->where('idp', '[a-z][a-z0-9_]{1,49}')
    ->middleware('throttle:30,1,sirsoft-sso.logout-start')
    ->name('logout');
