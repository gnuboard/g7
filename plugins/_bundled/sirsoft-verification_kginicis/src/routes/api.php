<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\VerificationKginicis\Http\Controllers\MyInicisIdentityShowController;

/*
|--------------------------------------------------------------------------
| KG이니시스 본인인증 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| 코어 PluginRouteServiceProvider 가 자동 적용:
|  - URL prefix: /api/plugins/sirsoft-verification_kginicis
|  - middleware: api
*/

// 마이페이지 본인인증 카드 — 본인의 본인확인 정보 (마스킹) 조회
Route::middleware(['auth:sanctum', 'check.user_status:active'])
    ->get('/me/identity/inicis', [MyInicisIdentityShowController::class, 'show'])
    ->name('me.identity.inicis.show');
