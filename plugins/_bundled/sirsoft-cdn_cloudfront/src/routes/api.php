<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\CdnCloudfront\Http\Controllers\Admin\CloudFrontAdminController;

/*
 * sirsoft-cdn_cloudfront 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/sirsoft-cdn_cloudfront (PluginRouteServiceProvider 자동 적용)
 *
 * 설정 화면 보조 API 라 권한은 코어 플러그인 설정 API 와 같은 축을 쓴다 — 화면을 열 수 있는 사람은 현황을 보고,
 * 설정을 고칠 수 있는 사람만 외부(CloudFront)로 요청을 보내는 연결 시험을 누를 수 있다. 연결 시험은 외부 API 호출이라
 * 분당 횟수를 제한한다(형제 sirsoft-social_login 시험 경로와 같은 값).
 */
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    Route::get('purge-status', [CloudFrontAdminController::class, 'purgeStatus'])
        ->middleware('permission:admin,core.plugins.read')
        ->name('purge-status');

    Route::post('test-connection', [CloudFrontAdminController::class, 'testConnection'])
        ->middleware(['permission:admin,core.plugins.update', 'throttle:20,1,sirsoft-cdn_cloudfront.test-connection'])
        ->name('test-connection');
});
