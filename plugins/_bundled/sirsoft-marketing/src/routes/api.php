<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Marketing\Http\Controllers\MarketingAdminController;
use Plugins\Sirsoft\Marketing\Http\Controllers\MarketingSettingsController;

/*
 * 마케팅 플러그인 공개 API 라우트
 * URL prefix 자동 적용: /api/plugins/sirsoft-marketing/
 */

Route::get('/settings', [MarketingSettingsController::class, 'settings'])
    ->name('settings');

/*
 * 마케팅 플러그인 관리자 API 라우트
 * 자동 prefix 적용 후 최종 URL: /api/plugins/sirsoft-marketing/admin/channels
 * 인증은 AdminBaseController 미들웨어에서 처리
 */
Route::prefix('admin')->name('admin.')->group(function () {
    Route::put('/channels', [MarketingAdminController::class, 'updateChannels'])
        ->name('channels.update');
});
