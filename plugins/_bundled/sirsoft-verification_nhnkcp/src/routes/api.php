<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\MyKcpIdentityShowController;

/*
|--------------------------------------------------------------------------
| NHN KCP 휴대폰 본인확인 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| 코어 PluginRouteServiceProvider 가 자동 적용:
|  - URL prefix: /api/plugins/sirsoft-verification_nhnkcp
|  - middleware: api
*/

// 마이페이지 본인확인 카드 — 본인의 본인확인 정보 (마스킹) 조회
Route::middleware(['auth:sanctum', 'check.user_status:active'])
    ->get('/me/identity/nhnkcp', [MyKcpIdentityShowController::class, 'show'])
    ->name('me.identity.nhnkcp.show');
