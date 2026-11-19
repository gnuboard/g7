<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Popup\Http\Controllers\Admin\DisplaySettingsController;
use Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupController;
use Plugins\Sirsoft\Popup\Http\Controllers\Admin\PopupImageController;
use Plugins\Sirsoft\Popup\Http\Controllers\Public\PopupController as PublicPopupController;
use Plugins\Sirsoft\Popup\Http\Controllers\Public\PopupImageController as PublicPopupImageController;

/*
|--------------------------------------------------------------------------
| 팝업 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| URL 프리픽스 자동 적용: /api/plugins/sirsoft-popup/
| 이름 프리픽스 자동 적용: api.plugins.sirsoft-popup.
|
| 권한: sirsoft-popup.popups.read(조회·표시 방식) · update(등록·수정·복사·순서·일괄 노출/비노출·이미지) · delete(삭제)
| 정적 경로(summary·order·bulk-status·bulk-copy·bulk)는 {popup} 보다 먼저 선언한다.
|
*/

// 공개 — 사용자 템플릿 공통 확장점(user_global_overlay)의 주입 조각이 부른다. 인증은 선택(회원·손님 노출 대상 판정)
Route::get('/popups', [PublicPopupController::class, 'index'])
    ->middleware(['optional.sanctum', 'throttle:120,1,sirsoft-popup.popups'])
    ->name('popups.index');
Route::get('/images/{hash}', [PublicPopupImageController::class, 'show'])
    ->where('hash', '[A-Za-z0-9]{64}')
    ->middleware('throttle:600,1,sirsoft-popup.images')
    ->name('images.show');

// 관리자
Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    $read = 'permission:admin,sirsoft-popup.popups.read';
    $update = 'permission:admin,sirsoft-popup.popups.update';
    $delete = 'permission:admin,sirsoft-popup.popups.delete';

    Route::get('/popups', [PopupController::class, 'index'])->middleware($read)->name('popups.index');
    Route::get('/popups/summary', [PopupController::class, 'summary'])->middleware($read)->name('popups.summary');
    Route::get('/popups/order', [PopupController::class, 'orderList'])->middleware($read)->name('popups.order');
    Route::put('/popups/order', [PopupController::class, 'reorder'])->middleware($update)->name('popups.reorder');
    Route::patch('/popups/bulk-status', [PopupController::class, 'bulkStatus'])->middleware($update)->name('popups.bulk-status');
    Route::post('/popups/bulk-copy', [PopupController::class, 'bulkCopy'])->middleware($update)->name('popups.bulk-copy');
    Route::delete('/popups/bulk', [PopupController::class, 'bulkDestroy'])->middleware($delete)->name('popups.bulk-destroy');
    Route::post('/popups', [PopupController::class, 'store'])->middleware($update)->name('popups.store');
    Route::get('/popups/{popup}', [PopupController::class, 'show'])->whereNumber('popup')->middleware($read)->name('popups.show');
    Route::put('/popups/{popup}', [PopupController::class, 'update'])->whereNumber('popup')->middleware($update)->name('popups.update');
    Route::delete('/popups/{popup}', [PopupController::class, 'destroy'])->whereNumber('popup')->middleware($delete)->name('popups.destroy');

    Route::post('/popup-images', [PopupImageController::class, 'store'])->middleware($update)->name('popup-images.store');
    Route::delete('/popup-images/{image}', [PopupImageController::class, 'destroy'])->whereNumber('image')->middleware($update)->name('popup-images.destroy');

    // 플러그인 설정 화면(업로드 한도 안내)이 「플러그인 보기」(core.plugins.read)로도 읽는다(OR) — 읽기 전용 진입 때 403 알림 방지
    Route::get('/display-settings', [DisplaySettingsController::class, 'show'])->middleware('permission:admin,sirsoft-popup.popups.read|core.plugins.read,false')->name('display-settings.show');
});
