<?php

use App\Support\SiteUrl;
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

// 결제창 닫힘·결제 실패 보고 — 주문 소유자 확인 후 결제 실패/취소 이력 기록.
// 브라우저 리턴 콜백(/payment/fail)은 인증·서명이 없어 주문 상태를 바꾸지 않으므로,
// 주문을 실패로 전이시키는 유일한 결제창 경로다 (다른 결제사 플러그인과 동일 계약).
// 주문 소유자(회원 Sanctum / 비회원 X-Guest-Order-Token)만 보고할 수 있다 — optional.sanctum 이 있어야 Bearer 토큰이 회원으로 인식된다.
Route::post('/payment/close-report', [PaymentCloseReportController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('payment.close-report');

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 웹훅 URL 조회 (관리자 설정 화면 표시·복사용).
    // 운영자가 토스 개발자센터에 그대로 붙여 넣는 값이라 사이트 주소까지 붙은 전체 URL 이어야 한다 —
    // 레이아웃에 경로만 적으면 도메인을 손으로 덧붙여야 했다 (다른 결제사 플러그인과 같은 방식).
    Route::get('/webhook-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                // 운영자가 PG 관리자에 등록할 주소 — 접속한 주소가 아니라 사이트 주소로 안내한다.
                'url' => SiteUrl::absolute('/plugins/sirsoft-tosspayments/webhook/deposit'),
            ],
        ]);
    })->middleware('permission:admin,sirsoft-ecommerce.settings.read')
        // 이름에 `.webhook.` 마디를 두지 않는다 — 코어 IP 제한 검사가 그 이름을 외부 인바운드 콜백으로 분류한다(관리자 조회라 콜백이 아니다)
        ->name('webhook-url');
});
