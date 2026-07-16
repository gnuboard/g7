<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminCashReceiptController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtConnectivityCheckController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtCvsOperationsController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtReconciliationController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminCbtTestProductController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDeliveryController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminEscrowDenyConfirmController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminOrderListController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminSettingsStatusController;
use Plugins\Sirsoft\PayKginicis\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtCheckoutTokenController;
use Plugins\Sirsoft\PayKginicis\Controllers\CbtHashDataController;
use Plugins\Sirsoft\PayKginicis\Controllers\MobileSignatureController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentCloseReportController;
use Plugins\Sirsoft\PayKginicis\Controllers\PaymentSignatureController;
use Plugins\Sirsoft\PayKginicis\Controllers\UserReceiptController;

/*
|--------------------------------------------------------------------------
| KG Inicis Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay_kginicis (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// 결제창 서명 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/signature', [PaymentSignatureController::class, 'generate'])
    ->name('payment.signature');

// PC 표준결제창 닫힘 보고 — 주문 컨텍스트 검증 후 결제 실패/취소 이력 기록
Route::post('/payment/close-report', [PaymentCloseReportController::class, 'store'])
    ->name('payment.close-report');

// CBT 해시 데이터 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/cbt/checkout-token', [CbtCheckoutTokenController::class, 'issue'])
    ->name('payment.cbt.checkout-token');

Route::post('/payment/cbt/hash-data', [CbtHashDataController::class, 'generate'])
    ->name('payment.cbt.hash-data');

// 모바일 위변조 방지 해시(P_CHKFAKE) 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/mobile/signature', [MobileSignatureController::class, 'generate'])
    ->name('payment.mobile.signature');

// 영수증 조회 — 회원/비회원 공유 (컨트롤러 내부 분기).
// 회원: Auth::id() 매칭, 비회원: X-Guest-Order-Token 으로 GuestOrderAuthService 검증
// (코어 PublicOrderController::showByOrderNumber 와 동일 패턴).
Route::get('/user/orders/{orderNumber}/receipt', [UserReceiptController::class, 'show'])
    ->middleware('optional.sanctum')
    ->name('user.orders.receipt');

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 URL 조회 (관리자 설정 페이지 표시용)
    Route::get('/vbank-notify-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'url' => url('/plugins/sirsoft-pay_kginicis/payment/vbank-notify'),
                'mobile_url' => url('/plugins/sirsoft-pay_kginicis/payment/mobile/vbank-notify'),
            ],
        ]);
    })->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('vbank.notify.url');

    Route::get('/settings/test-mode-status', [AdminSettingsStatusController::class, 'testMode'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('settings.test-mode-status');

    // 어드민 주문 목록의 테스트 결제 배지 표시용 맵 (auto_fetch)
    Route::get('/orders/test-mode-map', [AdminOrderListController::class, 'testModeMap'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.test-mode-map');

    // 거래 조회 — TID 직접 조회
    Route::post('/transaction/query', [AdminTransactionController::class, 'query'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('transaction.query');

    // 주문번호로 거래 상태 조회 (레이아웃 확장 자동 로드용)
    Route::get('/orders/{orderNumber}/transaction-status', [AdminTransactionController::class, 'queryByOrder'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.transaction-status');

    Route::get('/orders/{orderNumber}/cbt-reconciliation', [AdminCbtReconciliationController::class, 'show'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.cbt-reconciliation.show');
    Route::post('/orders/{orderNumber}/cbt-reconciliation/refund-retry', [AdminCbtReconciliationController::class, 'retryRefund'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.cbt-reconciliation.refund-retry');

    Route::get('/orders/{orderNumber}/cbt-cvs', [AdminCbtCvsOperationsController::class, 'show'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.cbt-cvs.show');
    Route::post('/orders/{orderNumber}/cbt-cvs/simulate-notify', [AdminCbtCvsOperationsController::class, 'simulateNotify'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.cbt-cvs.simulate-notify');
    Route::post('/orders/{orderNumber}/cbt-cvs/expire', [AdminCbtCvsOperationsController::class, 'expire'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.cbt-cvs.expire');
    Route::post('/orders/{orderNumber}/cbt-cvs/recheck', [AdminCbtCvsOperationsController::class, 'recheck'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.cbt-cvs.recheck');

    // 현금영수증 별도발행
    Route::post('/orders/{orderNumber}/cash-receipt', [AdminCashReceiptController::class, 'issue'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.cash-receipt.issue');

    // 에스크로 배송등록
    Route::get('/orders/{orderNumber}/escrow-delivery', [AdminEscrowDeliveryController::class, 'formData'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.escrow-delivery.form');
    Route::post('/orders/{orderNumber}/escrow-delivery', [AdminEscrowDeliveryController::class, 'register'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.escrow-delivery.register');

    // 에스크로 구매거절확인
    Route::post('/orders/{orderNumber}/escrow-deny-confirm', [AdminEscrowDenyConfirmController::class, 'confirm'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.escrow-deny-confirm');

    // CBT (일본 결제) 테스트용 JPY 상품 자동 생성 — 운영자가 CBT 검증 시 진입 장벽 낮추기 위함
    Route::post('/cbt-test-product', [AdminCbtTestProductController::class, 'create'])
        ->middleware('permission:admin,sirsoft-ecommerce.products.create')
        ->name('cbt.test-product.create');

    // CBT 호스트 연결 진단 — 서버 egress IP + devcbt.inicis.com / cbt.inicis.com 의 TCP 443 도달성
    // (devcbt 는 KG 이니시스 측 IP 화이트리스트 등록 필요)
    Route::get('/cbt-connectivity-check', [AdminCbtConnectivityCheckController::class, 'check'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('cbt.connectivity.check');
});
