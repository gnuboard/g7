<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Tosspayments\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\Tosspayments\Controllers\WebhookController;

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

// 웹훅 (토스페이먼츠 서버 → POST). 토스는 CSRF 토큰을 보내지 않으므로 면제.
// 서명·IP 화이트리스트가 없어 secret 대조로 위조를 방지한다.
Route::withoutMiddleware([ValidateCsrfToken::class])
    ->group(function () {
        // 가상계좌 입금통보 (DEPOSIT_CALLBACK)
        Route::post('/webhook/deposit', [WebhookController::class, 'deposit'])
            ->name('webhook.deposit');

        // 결제상태 변경 (PAYMENT_STATUS_CHANGED)
        Route::post('/webhook/payment-status', [WebhookController::class, 'paymentStatus'])
            ->name('webhook.payment-status');
    });
