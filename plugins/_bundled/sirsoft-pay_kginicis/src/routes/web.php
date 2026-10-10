<?php

use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use App\Http\Middleware\SyncBoostWithDebugMode;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCvsNotifyController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCloseController;
use Plugins\Sirsoft\PayKginicis\Controllers\UserEscrowConfirmController;

// 팝업 닫기 (KG 이니시스 closeUrl — 인증 불필요)
// 주문번호 라우트보다 먼저 등록한다 — 뒤에 두면 `{orderNumber}` 가 `close` 를 가로채 닫기 주소가 오류로 끝났다.
Route::get('/payment/escrow-confirm/close', [UserEscrowConfirmController::class, 'close'])
    ->name('payment.escrow-confirm.close');

// 에스크로 구매결정: 로그인 본인 주문만 — 인증 판정은 컨트롤러가 한다(비로그인은 401 안내).
// `auth` 미들웨어는 로그인 라우트가 없는 이 앱에서 비로그인 요청을 500 으로 끝낸다.
Route::get('/payment/escrow-confirm/{orderNumber}', [UserEscrowConfirmController::class, 'show'])
    ->where('orderNumber', '^(?!close$)[A-Za-z0-9_-]+$')
    ->name('payment.escrow-confirm.show');

// PC 표준결제창 닫기 (KG 이니시스 closeUrl — 인증 불필요)
Route::get('/payment/close', [PaymentCloseController::class, 'show'])
    ->withoutMiddleware([
        SyncBoostWithDebugMode::class,
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
        SubstituteBindings::class,
        SetLocale::class,
        SetTimezone::class,
    ])
    ->name('payment.close');

Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::match(['get', 'post'], '/payment/cbt/callback', [CbtCallbackController::class, 'handle'])
        ->name('payment.cbt.callback');

    // IP 화이트리스트(InicisNotifyIpWhitelist)는 코어 self-gate 로 이관 — Plugin::getMiddleware() 가
    // cvs-notify/vbank-notify/mobile.vbank-notify webhook 라우트명에만 타게팅 (payment.callback 제외).
    Route::post('/payment/cbt/cvs-notify', [CbtCvsNotifyController::class, 'handle'])
        ->name('payment.cbt.cvs-notify');

    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->name('payment.vbank-notify');

    Route::post('/payment/mobile/vbank-notify', [PaymentCallbackController::class, 'mobileVbankNotify'])
        ->name('payment.mobile.vbank-notify');

    // (제거됨) /payment/escrow-notify — KG 이니시스 PC 에스크로 매뉴얼에는 webhook
    // 통보 채널이 존재하지 않음. 가맹점이 outbound API 로 배송등록/구매결정/구매거절확인
    // 만 수행. 잘못 추가된 route 였음.

    // 모바일: KG 이니시스가 인증 후 P_NEXT_URL 로 POST 콜백을 전송 (모바일 표준결제 표준).
    // GET 도 허용해 일부 케이스(PG 자체 리다이렉트 패턴) 호환 — 인증/주문번호는 동일하게 P_OID 로 수신.
    Route::match(['get', 'post'], '/payment/mobile/callback', [MobileCallbackController::class, 'handle'])
        ->name('payment.mobile.callback');

    // 에스크로 구매결정 결과 수신 (KG 이니시스 → 사용자 브라우저 POST)
    // 확인창이 발급한 일회용 증표가 경로에 실린 주소만 기록한다(증표 없는 주소는 배포 순간 열려 있던
    // 확인창 호환용 — 기록하지 않고 같은 응답만 준다).
    Route::post('/payment/escrow-confirm/pc/return/{nonce}', [UserEscrowConfirmController::class, 'pcReturn'])
        ->where('nonce', '[A-Za-z0-9]{40}')
        ->name('payment.escrow-confirm.pc-return.bound');

    Route::post('/payment/escrow-confirm/mobile/return/{nonce}', [UserEscrowConfirmController::class, 'mobileReturn'])
        ->where('nonce', '[A-Za-z0-9]{40}')
        ->name('payment.escrow-confirm.mobile-return.bound');

    Route::post('/payment/escrow-confirm/pc/return', [UserEscrowConfirmController::class, 'pcReturn'])
        ->name('payment.escrow-confirm.pc-return');

    Route::post('/payment/escrow-confirm/mobile/return', [UserEscrowConfirmController::class, 'mobileReturn'])
        ->name('payment.escrow-confirm.mobile-return');
});
