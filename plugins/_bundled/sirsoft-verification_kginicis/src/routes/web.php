<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\VerificationKginicis\Http\Controllers\InicisCallbackController;
use Plugins\Sirsoft\VerificationKginicis\Http\Controllers\InicisPopupBridgeController;

/*
|--------------------------------------------------------------------------
| KG이니시스 본인인증 플러그인 라우트
|--------------------------------------------------------------------------
|
| 이니시스 매뉴얼 STEP2 → STEP3 → STEP4 흐름:
|  - POST /plugin/inicis/callback   (이니시스 → 가맹점 콜백 수신)
|      → STEP3 (authRequestUrl) 호출 → mtxid 매칭 → service::handleProviderCallback
|      → 302 redirect → /plugin/inicis/popup-bridge
|  - GET  /plugin/inicis/popup-bridge (결과 전달)
|      → 데스크톱: 부모창 postMessage + window.close
|      → 모바일: sessionStorage stash 복원 + return_url 로 redirect
*/

// 외부 PG 콜백 — CSRF 면제 (이니시스가 외부에서 form POST 로 콜백 → CSRF 토큰 없음)
Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/plugin/inicis/callback', [InicisCallbackController::class, 'handle'])
        ->name('plugin.verification_kginicis.callback');
});

Route::get('/plugin/inicis/popup-bridge', [InicisPopupBridgeController::class, 'show'])
    ->name('plugin.verification_kginicis.popup-bridge');
