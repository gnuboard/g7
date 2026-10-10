<?php

use App\Support\SiteUrl;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNhnkcp\Controllers\AdminEscrowDeliveryController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\AdminOrderListController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\AdminSettingsStatusController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\AdminTransactionController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\HealthCheckController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\MobileApprovalController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentCloseReportController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\PaymentRetryController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\UserReceiptController;
use Plugins\Sirsoft\PayNhnkcp\Controllers\UserVbankMockDepositController;

/*
|--------------------------------------------------------------------------
| NHN KCP Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay_nhnkcp (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// PC 결제창 닫힘 보고 — 주문 소유자(회원 Sanctum / 비회원 X-Guest-Order-Token) 확인 후 결제 실패/취소 이력 기록.
// optional.sanctum 이 있어야 Bearer 토큰이 회원으로 인식된다 (주문자 연락처 대조는 소유 증명이 아니다).
Route::post('/payment/close-report', [PaymentCloseReportController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('payment.close-report');

// 결제 재시도 준비 — 주문 소유자 확인 후 이전 실패/취소 주문을 같은 주문번호로 재결제 가능 상태로 복구
Route::post('/payment/retry', [PaymentRetryController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('payment.retry');

Route::get('/user/orders/{orderNumber}/receipt', [UserReceiptController::class, 'show'])
    ->middleware('optional.sanctum')
    ->name('user.orders.receipt');

Route::middleware(['auth:sanctum'])->group(function () {
    // 테스트 모드 가상계좌 모의입금 정보 (입금대기 상태일 때만 데이터 반환)
    Route::get('/user/orders/{orderNumber}/vbank-mock-deposit-info', [UserVbankMockDepositController::class, 'show'])
        ->name('user.orders.vbank-mock-deposit-info');

    // 모바일 결제 승인키 획득 (KCP SmartPhone Pay SOAP)
    Route::post('/mobile/approval-key', [MobileApprovalController::class, 'getApprovalKey'])
        ->name('mobile.approval-key');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 URL 조회 (관리자 설정 페이지 표시용)
    Route::get('/vbank-notify-url', fn () => response()->json([
        'success' => true,
        'data' => [
            // 운영자가 PG 관리자에 등록할 주소 — 접속한 주소가 아니라 사이트 주소로 안내한다.
            'url' => SiteUrl::absolute('/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify'),
            'escrow_common_notify_url' => SiteUrl::absolute('/plugins/sirsoft-pay_nhnkcp/payment/escrow-common-notify'),
        ],
    ]))->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('vbank.notify.url');

    Route::get('/settings/test-mode-status', [AdminSettingsStatusController::class, 'testMode'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        ->name('settings.test-mode-status');

    // 테스트 모드 주문 맵 (관리자 주문목록 배지 표시용)
    Route::get('/orders/test-mode-map', [AdminOrderListController::class, 'testModeMap'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.test-mode-map');

    // 주문번호로 거래 정보 조회 (레이아웃 확장 자동 로드용)
    Route::get('/orders/{orderNumber}/transaction-status', [AdminTransactionController::class, 'queryByOrder'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.transaction-status');

    // 에스크로 배송 등록
    Route::get('/orders/{orderNumber}/escrow-delivery', [AdminEscrowDeliveryController::class, 'formData'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.read')
        ->name('orders.escrow-delivery.form');
    Route::post('/orders/{orderNumber}/escrow-delivery', [AdminEscrowDeliveryController::class, 'register'])
        ->middleware('permission:admin,sirsoft-ecommerce.orders.update')
        ->name('orders.escrow-delivery.register');

    // 시스템 점검 (PC/모바일 결제 사전조건 진단 + 자동 chmod +x 복구) — 플러그인 설정 화면 카드라 화면 권한으로도 읽는다(OR).
    // 실행 권한 자동 복구는 컨트롤러가 플러그인 설정 수정 권한(core.plugins.update)일 때만 한다
    Route::get('/health', [HealthCheckController::class, 'check'])
        ->middleware('permission:admin,sirsoft-ecommerce.settings.read|core.plugins.update,false')
        ->name('health');
});
