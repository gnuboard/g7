<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNhnkcp\Controllers\EscrowCommonNotifyController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCallbackController;

/*
|--------------------------------------------------------------------------
| NHN KCP Plugin Web Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/sirsoft-pay_nhnkcp (PluginRouteServiceProvider 자동 적용)
| 미들웨어: web (PluginRouteServiceProvider 자동 적용)
|
| KCP Standard Pay는 브라우저 POST 콜백 방식이므로 CSRF 미들웨어를 제외합니다.
|
| IP 검증:
|   - authCallback: 브라우저 POST (사용자 IP) → IP 검증 안 함
|   - vbank-notify, escrow-common-notify: KCP 서버 직접 POST → RestrictKcpIp 가드
|     (테스트 모드 시 우회. 그누보드5 settle_kcp_common.php 동일 패턴)
|
*/

Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    // 결제 승인 콜백 (KCP → 브라우저 POST)
    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    // KCP 서버 발신 webhook (IP 화이트리스트 가드는 코어 self-gate 로 이관 —
    // Plugin::getMiddleware() 가 vbank-notify/escrow-common-notify 라우트명에만 타게팅).
    // 가상계좌 입금 통보 (KCP 서버 → 우리 서버 POST)
    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->name('payment.vbank-notify');

    // 에스크로 공통통보 (KCP 서버 → 우리 서버 POST: TX02 구매확인/취소, TX03 배송시작)
    Route::post('/payment/escrow-common-notify', [EscrowCommonNotifyController::class, 'handle'])
        ->name('payment.escrow-common-notify');
});
