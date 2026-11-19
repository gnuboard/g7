<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\Admin\PolicyController;

/*
| SSO 연동 — 관리자 정책 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
| 최고관리자 전용 칸(사이트 전체 강제, 역할 강제, 비상 계정)은 권한과 별개로 서비스가 is_super 를 본다.
*/

Route::prefix('admin/policy')->name('admin.policy.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/', [PolicyController::class, 'show'])->middleware('permission:admin,sirsoft-sso.settings.view')->name('show');
    Route::put('/', [PolicyController::class, 'update'])->middleware('permission:admin,sirsoft-sso.settings.update')->name('update');
    Route::get('/impact', [PolicyController::class, 'impact'])->middleware('permission:admin,sirsoft-sso.settings.view')->name('impact');
});
