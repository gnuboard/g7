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
 *
 * 채널 저장은 코어 `PUT plugins/{identifier}/settings` 와 같은 plugin_settings 를 덮어쓰는
 * 우회 경로이므로 동일 권한(core.plugins.update)으로 게이트한다. `admin` 미들웨어는
 * type=admin 보유 여부만 판정하므로 그것만으로는 업무 권한 없는 관리자도 도달한다.
 */
Route::prefix('admin')->name('admin.')->middleware('permission:admin,core.plugins.update')->group(function () {
    Route::put('/channels', [MarketingAdminController::class, 'updateChannels'])
        ->name('channels.update');
});
