<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController;

/*
|--------------------------------------------------------------------------
| TossPayments Plugin Web Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/sirsoft-tosspayments (PluginRouteServiceProvider 자동 적용)
| 미들웨어: web (PluginRouteServiceProvider 자동 적용)
|
*/

// 결제 성공 콜백 (토스페이먼츠 → 브라우저 리다이렉트)
Route::get('/payment/success', [PaymentCallbackController::class, 'success'])
    ->name('payment.success');

// 결제 실패 콜백 (토스페이먼츠 → 브라우저 리다이렉트)
Route::get('/payment/fail', [PaymentCallbackController::class, 'fail'])
    ->name('payment.fail');
