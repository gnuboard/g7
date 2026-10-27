<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\MeController;

/*
| SSO 연동 — 마이페이지 연결 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
| 로그인 회원 본인만. 연결 시작은 브라우저 묶음 쿠키를 굽는다(쿠키 대기열은 Plugin::getMiddleware() 가 붙인다).
*/

Route::prefix('me')->name('me.')->middleware(['auth:sanctum', 'throttle:60,1,sirsoft-sso.me'])->group(function () {
    Route::get('/accounts', [MeController::class, 'index'])->name('accounts.index');
    Route::post('/links/start', [MeController::class, 'start'])->name('links.start');
    Route::delete('/accounts/{id}', [MeController::class, 'destroy'])->whereNumber('id')->name('accounts.destroy');
});
