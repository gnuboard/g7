<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Tosspayments\Controllers\PaymentCloseReportController;

/*
|--------------------------------------------------------------------------
| TossPayments Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-tosspayments (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// 결제창 닫힘·결제 실패 보고 — 구매자 정보 대조 후 결제 실패/취소 이력 기록.
// 브라우저 리턴 콜백(/payment/fail)은 인증·서명이 없어 주문 상태를 바꾸지 않으므로,
// 주문을 실패로 전이시키는 유일한 결제창 경로다 (다른 결제사 플러그인과 동일 계약).
Route::post('/payment/close-report', [PaymentCloseReportController::class, 'store'])
    ->name('payment.close-report');
