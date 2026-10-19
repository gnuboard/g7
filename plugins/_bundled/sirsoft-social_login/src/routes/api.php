<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderTestController;

/*
|--------------------------------------------------------------------------
| 소셜 로그인 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| URL 프리픽스 자동 적용: /api/plugins/sirsoft-social_login/
| 이름 프리픽스 자동 적용: api.plugins.sirsoft-social_login.
|
| permission 미들웨어 형식: permission:admin,sirsoft-social_login.{category}.{action}
|
*/

Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    // 제공자 연결 테스트 — 활성 여부와 관계없이 왕복을 시작하고 인가 주소를 돌려준다.
    Route::post('/providers/{provider}/test-login', [ProviderTestController::class, 'testLogin'])
        ->where('provider', '[a-z][a-z0-9_]{1,49}')
        ->middleware(['permission:admin,sirsoft-social_login.settings.update', 'throttle:20,1'])
        ->name('providers.test-login');
});
