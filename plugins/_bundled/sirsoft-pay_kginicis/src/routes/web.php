<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCvsNotifyController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCloseController;
use Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController;
use Plugins\Sirsoft\PayKginicis\Http\Middleware\InicisNotifyIpWhitelist;

// 에스크로 구매결정: 사용자 인증 필요
Route::get('/payment/escrow-confirm/{orderNumber}', [UserEscrowConfirmController::class, 'show'])
    ->middleware('auth')
    ->name('payment.escrow-confirm.show');

// 팝업 닫기 (KG 이니시스 closeUrl — 인증 불필요)
Route::get('/payment/escrow-confirm/close', [UserEscrowConfirmController::class, 'close'])
    ->name('payment.escrow-confirm.close');

// PC 표준결제창 닫기 (KG 이니시스 closeUrl — 인증 불필요)
Route::get('/payment/close', [PaymentCloseController::class, 'show'])
    ->withoutMiddleware([
        \App\Http\Middleware\SyncBoostWithDebugMode::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \App\Http\Middleware\SetLocale::class,
        \App\Http\Middleware\SetTimezone::class,
    ])
    ->name('payment.close');

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function () {
    Route::match(['get', 'post'], '/payment/cbt/callback', [CbtCallbackController::class, 'handle'])
        ->name('payment.cbt.callback');

    Route::post('/payment/cbt/cvs-notify', [CbtCvsNotifyController::class, 'handle'])
        ->middleware(InicisNotifyIpWhitelist::class)
        ->name('payment.cbt.cvs-notify');

    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    // KG 이니시스 공식 발송 IP 만 허용 (위변조/재처리 방어)
    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->middleware(InicisNotifyIpWhitelist::class)
        ->name('payment.vbank-notify');

    Route::post('/payment/mobile/vbank-notify', [PaymentCallbackController::class, 'mobileVbankNotify'])
        ->middleware(InicisNotifyIpWhitelist::class)
        ->name('payment.mobile.vbank-notify');

    // (제거됨) /payment/escrow-notify — KG 이니시스 PC 에스크로 매뉴얼에는 webhook
    // 통보 채널이 존재하지 않음. 가맹점이 outbound API 로 배송등록/구매결정/구매거절확인
    // 만 수행. 잘못 추가된 route 였음.

    // 모바일: KG 이니시스가 인증 후 P_NEXT_URL 로 POST 콜백을 전송 (모바일 표준결제 표준).
    // GET 도 허용해 일부 케이스(PG 자체 리다이렉트 패턴) 호환 — 인증/주문번호는 동일하게 P_OID 로 수신.
    Route::match(['get', 'post'], '/payment/mobile/callback', [MobileCallbackController::class, 'handle'])
        ->name('payment.mobile.callback');

    // 에스크로 구매결정 결과 수신 (KG 이니시스 → 사용자 브라우저 POST)
    Route::post('/payment/escrow-confirm/pc/return', [UserEscrowConfirmController::class, 'pcReturn'])
        ->name('payment.escrow-confirm.pc-return');

    Route::post('/payment/escrow-confirm/mobile/return', [UserEscrowConfirmController::class, 'mobileReturn'])
        ->name('payment.escrow-confirm.mobile-return');
});
