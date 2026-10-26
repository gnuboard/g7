<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\Admin\AccountController;

/*
| SSO 연동 — 관리자 「회원 연결」 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
| 열람 accounts.view, 연결 추가·사전 등록·CSV accounts.provision, 해제 accounts.unlink.
*/

Route::prefix('admin/accounts')->name('admin.accounts.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [AccountController::class, 'index'])->middleware('permission:admin,sirsoft-sso.accounts.view')->name('index');
    Route::post('/', [AccountController::class, 'store'])->middleware('permission:admin,sirsoft-sso.accounts.provision')->name('store');
    Route::post('/import', [AccountController::class, 'import'])
        ->middleware(['permission:admin,sirsoft-sso.accounts.provision', 'throttle:20,1,sirsoft-sso.accounts-import'])
        ->name('import');
    Route::delete('/{id}', [AccountController::class, 'destroy'])
        ->whereNumber('id')
        ->middleware('permission:admin,sirsoft-sso.accounts.unlink')
        ->name('destroy');
});
