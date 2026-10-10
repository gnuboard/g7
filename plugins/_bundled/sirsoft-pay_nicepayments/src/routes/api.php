<?php

use App\Support\SiteUrl;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminEscrowController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminOrderListController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminSettingsStatusController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankNotificationController;
use Plugins\Sirsoft\PayNicepayments\Controllers\AdminVbankRefundController;
use Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCloseReportController;
use Plugins\Sirsoft\PayNicepayments\Controllers\UserReceiptController;

/*
|--------------------------------------------------------------------------
| NicePayments Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay_nicepayments (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// PC 결제창 닫힘 보고 — 주문 소유자(회원 Sanctum / 비회원 X-Guest-Order-Token) 확인 후 결제 실패/취소 이력 기록.
// optional.sanctum 이 있어야 Bearer 토큰이 회원으로 인식된다 (주문자 연락처 대조는 소유 증명이 아니다).
Route::post('/payment/close-report', [PaymentCloseReportController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('payment.close-report');

Route::get('/user/orders/{orderNumber}/receipt', [UserReceiptController::class, 'show'])
    ->middleware('optional.sanctum')
    ->name('user.orders.receipt');

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 URL 조회 (관리자 설정 페이지 표시용)
    Route::get('/vbank-notify-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                // 운영자가 PG 관리자에 등록할 주소 — 접속한 주소가 아니라 사이트 주소로 안내한다.
                'url' => SiteUrl::absolute('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify'),
            ],
        ]);
    })->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('vbank.notify.url');

    Route::get('/settings/test-mode-status', [AdminSettingsStatusController::class, 'testMode'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('settings.test-mode-status');

    // TID 단건 거래 조회
    Route::post('/transaction/query', [AdminTransactionController::class, 'query'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('transaction.query');

    // 주문번호로 나이스페이 거래 조회 (자동 조회용)
    Route::get('/orders/{orderNumber}/transaction-status', [AdminTransactionController::class, 'queryByOrder'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.transaction-status');

    // 테스트 모드 주문 맵 조회 (주문 목록 배지용)
    Route::get('/orders/test-mode-map', [AdminOrderListController::class, 'testModeMap'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.test-mode-map');

    // 가상계좌 입금 완료 건 환불 (환불 계좌 정보 필요) — PG 취소와 주문 취소까지 하므로 「환불 실행」 권한
    Route::post('/vbank-refund', [AdminVbankRefundController::class, 'refund'])
        ->middleware('permission:admin,sirsoft-ecommerce.claims.execute-refund')
        ->name('vbank.refund');

    // 에스크로 결제 목록 조회
    Route::get('/orders/{orderNumber}/escrow-payments', [AdminEscrowController::class, 'getEscrowPayments'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.escrow-payments');

    // 에스크로 배송 등록
    Route::post('/escrow/register-delivery', [AdminEscrowController::class, 'registerDelivery'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('escrow.register-delivery');

    // 가상계좌 입금통보 이력 조회 — 어드민 주문 상세 패널에서 사용.
    // OrderPaymentResource 가 payment_meta 를 노출하지 않으므로, 어드민 전용으로 통보 이력만
    // 추출해 반환 (PII 는 sanitize 됨).
    Route::get('/orders/{orderNumber}/vbank-notifications', [AdminVbankNotificationController::class, 'show'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.vbank-notifications');
});
