<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminConsentLogController;
use Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminPolicyVersionController;
use Plugins\Sirsoft\Gdpr\Http\Controllers\Admin\GdprAdminSettingsController;
use Plugins\Sirsoft\Gdpr\Http\Controllers\Public\GdprCookieConsentController;
use Plugins\Sirsoft\Gdpr\Http\Controllers\Public\GdprSettingsController;
use Plugins\Sirsoft\Gdpr\Http\Controllers\User\GdprConsentController;

/*
 * GDPR 플러그인 API 라우트
 *
 * URL prefix 자동 적용: /api/plugins/sirsoft-gdpr/
 * Name prefix 자동 적용: api.plugins.sirsoft-gdpr.
 */

/*
|--------------------------------------------------------------------------
| 공개 API (인증 불필요)
|--------------------------------------------------------------------------
|
| 게스트도 호출 가능. 회원이 sanctum 토큰으로 호출하면 user_id 인식.
|
| 쿠키 동의 라우트는 optional.sanctum 미들웨어로 토큰을 검증하여
| 토큰이 있으면 회원으로, 없으면 게스트로 처리한다.
| 미들웨어 없이 두면 Bearer 토큰을 보내도 $request->user() 가 null 이 되어
| 회원 동의가 g7_gdpr_user_consents 에 저장되지 못하고 history 만 남는 회귀가 발생.
|
*/
Route::get('/settings', [GdprSettingsController::class, 'show'])
    ->name('settings');

Route::post('/consent/cookie', [GdprCookieConsentController::class, 'store'])
    ->middleware('optional.sanctum')
    ->name('consent.cookie');

Route::get('/consent/cookie/status', [GdprCookieConsentController::class, 'status'])
    ->middleware('optional.sanctum')
    ->name('consent.cookie.status');

/*
|--------------------------------------------------------------------------
| 사용자 API (sanctum 인증 필수)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // 동의 동기화·이력·철회
    Route::get('/consent/me', [GdprConsentController::class, 'me'])
        ->name('consent.me');

    Route::get('/consent/history', [GdprConsentController::class, 'history'])
        ->name('consent.history');

    Route::post('/consent/revoke', [GdprConsentController::class, 'revoke'])
        ->name('consent.revoke');

    // 회원 동의 부여 (재동의/신규 동의) — Art.7(3) 자유 변경권 대칭성 보장
    Route::post('/consent/grant', [GdprConsentController::class, 'grant'])
        ->name('consent.grant');

    // 정책 버전 bump 후 *활성 선택형 동의* 만 일괄 새 버전으로 grant — "전체 항목 다시 동의" (#19).
    // 필수 쿠키 / 철회 상태는 대상 외.
    Route::post('/consent/renew-all', [GdprConsentController::class, 'renewAll'])
        ->name('consent.renew_all');
});

/*
|--------------------------------------------------------------------------
| 관리자 API (sanctum + permission)
|--------------------------------------------------------------------------
|
| permission 미들웨어 형식: permission:admin,sirsoft-gdpr.privacy.{action}
| - view  : 동의 이력·설정 조회
| - update: 쿠키 카테고리·정책 버전 등 설정 변경
|
*/
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {

    // 설정 조회 (view) / 저장 (update)
    Route::get('/settings', [GdprAdminSettingsController::class, 'show'])
        ->middleware('permission:admin,sirsoft-gdpr.privacy.view')
        ->name('settings.show');

    Route::put('/settings', [GdprAdminSettingsController::class, 'update'])
        ->middleware('permission:admin,sirsoft-gdpr.privacy.update')
        ->name('settings.update');

    // 동의 로그 조회 (view)
    Route::get('/consent-log', [GdprAdminConsentLogController::class, 'index'])
        ->middleware('permission:admin,sirsoft-gdpr.privacy.view')
        ->name('consent-log.index');

    // 정책 버전 이력 (view) — 발행 이력 페이지네이션 + 현재 최신 버전 조회
    Route::get('/policy-versions', [GdprAdminPolicyVersionController::class, 'index'])
        ->middleware('permission:admin,sirsoft-gdpr.privacy.view')
        ->name('policy-versions.index');

    Route::get('/policy-versions/current', [GdprAdminPolicyVersionController::class, 'current'])
        ->middleware('permission:admin,sirsoft-gdpr.privacy.view')
        ->name('policy-versions.current');

    // 정책 버전 단건 detail (snapshot 본문 포함) — admin 동의 이력 / 정책 버전 이력
    // 화면에서 행 클릭 시 호출. DPO 가 그 시점 정책 본문 즉시 확인 (Art.7(1) 입증 책임).
    Route::get('/policy-versions/{version}', [GdprAdminPolicyVersionController::class, 'show'])
        ->where('version', '[0-9]+')
        ->middleware('permission:admin,sirsoft-gdpr.privacy.view')
        ->name('policy-versions.show');

    // 운영자 수동 정책 버전 발행 — 자동 감지 영역 밖 변경 (정책 본문 외부 수정 등)
    Route::post('/policy-versions', [GdprAdminPolicyVersionController::class, 'store'])
        ->middleware('permission:admin,sirsoft-gdpr.privacy.update')
        ->name('policy-versions.store');
});
