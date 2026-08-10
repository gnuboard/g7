<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\KcpBridgeController;
use Plugins\Sirsoft\VerificationNhnkcp\Http\Controllers\KcpCallbackController;

/*
|--------------------------------------------------------------------------
| NHN KCP 휴대폰 본인확인 플러그인 라우트
|--------------------------------------------------------------------------
|
| KCP 본인확인 V2 흐름 ③ 콜백 → ④ 결과조회 → 결과 전달:
|  - POST /plugin/nhnkcp/callback  (KCP 표준창 → 가맹점 Ret_URL 콜백 수신)
|      → 거래 매칭 → 결과조회 → 코어 verify 위임 → 302 redirect → /plugin/nhnkcp/bridge
|  - GET  /plugin/nhnkcp/bridge    (결과 전달)
|      → 데스크톱: 부모창 postMessage + window.close
|      → 모바일: sessionStorage 복귀 정보로 원 페이지 redirect
*/

// 외부 인증기관 콜백 — CSRF 면제 (KCP 표준창이 외부에서 form POST 하므로 CSRF 토큰이 없다)
Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/plugin/nhnkcp/callback', [KcpCallbackController::class, 'handle'])
        ->name('plugin.verification_nhnkcp.callback');
});

Route::get('/plugin/nhnkcp/bridge', [KcpBridgeController::class, 'show'])
    ->name('plugin.verification_nhnkcp.bridge');
