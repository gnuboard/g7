<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\Admin\IdpController;

/*
| SSO 연동 — 관리자 IdP 설정 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
| 관리자 판정(`admin`) 뒤 조회는 settings.view, 변경·테스트·키 발급은 settings.update.
*/

Route::prefix('admin/idps')->name('admin.idps.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    $view = 'permission:admin,sirsoft-sso.settings.view';
    $update = 'permission:admin,sirsoft-sso.settings.update';

    Route::get('/', [IdpController::class, 'index'])->middleware($view)->name('index');
    Route::post('/', [IdpController::class, 'store'])->middleware($update)->name('store');
    Route::get('/{id}', [IdpController::class, 'show'])->whereNumber('id')->middleware($view)->name('show');
    Route::put('/{id}', [IdpController::class, 'update'])->whereNumber('id')->middleware($update)->name('update');
    Route::delete('/{id}', [IdpController::class, 'destroy'])->whereNumber('id')->middleware($update)->name('destroy');
    Route::post('/{id}/test', [IdpController::class, 'test'])
        ->whereNumber('id')
        ->middleware([$update, 'throttle:20,1,sirsoft-sso.idp-test'])
        ->name('test');
    Route::post('/{id}/sp-keys', [IdpController::class, 'spKeys'])->whereNumber('id')->middleware($update)->name('sp-keys');
    Route::post('/{id}/scim-token', [IdpController::class, 'scimToken'])->whereNumber('id')->middleware($update)->name('scim-token');
});
