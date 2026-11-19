<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\Admin\LogController;

/*
| SSO 연동 — 관리자 「이력」 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
*/

Route::prefix('admin/logs')->name('admin.logs.')->middleware(['auth:sanctum', 'admin', 'permission:admin,sirsoft-sso.logs.view'])->group(function () {
    Route::get('/attempts', [LogController::class, 'attempts'])->name('attempts');
    Route::get('/scim', [LogController::class, 'scim'])->name('scim');
});
