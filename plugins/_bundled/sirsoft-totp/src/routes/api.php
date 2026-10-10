<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Totp\Http\Controllers\TotpAdminCredentialController;
use Plugins\Sirsoft\Totp\Http\Controllers\TotpEnrollmentController;

/*
 * 인증 앱(TOTP) 플러그인 API 라우트
 *
 * URL prefix 자동 적용: /api/plugins/sirsoft-totp/
 * Name prefix 자동 적용: api.plugins.sirsoft-totp.
 *
 * 로그인 2단계 인증의 코드 확인은 코어 라우트(`POST /api/admin/login/two-factor`,
 * `POST /api/auth/login/two-factor`)가 담당한다 — 이 플러그인은 프로바이더로 등록될
 * 뿐이고 로그인 경로를 따로 열지 않는다.
 */

/*
|--------------------------------------------------------------------------
| 본인 등록 관리 (sanctum 인증 필수)
|--------------------------------------------------------------------------
|
| 대상은 항상 호출자 본인이다 — 경로에 사용자 식별자를 두지 않아 남의 자격증명을
| 가리킬 수 있는 표면 자체를 만들지 않는다.
|
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/enrollment', [TotpEnrollmentController::class, 'show'])
        ->name('enrollment.show');

    Route::post('/enrollment', [TotpEnrollmentController::class, 'store'])
        ->name('enrollment.store');

    Route::post('/enrollment/confirm', [TotpEnrollmentController::class, 'confirm'])
        ->name('enrollment.confirm');

    Route::delete('/enrollment', [TotpEnrollmentController::class, 'destroy'])
        ->name('enrollment.destroy');

    Route::post('/backup-codes/regenerate', [TotpEnrollmentController::class, 'regenerateBackupCodes'])
        ->name('backup-codes.regenerate');
});

/*
|--------------------------------------------------------------------------
| 관리자 API (sanctum + 전용 권한)
|--------------------------------------------------------------------------
|
| 코드를 요구하지 않는 강제 해제이므로 회원 관리 권한과 분리한 전용 권한을 요구한다.
|
*/
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    // 회원 식별자는 **uuid** 다 — `User::getRouteKeyName()` 이 uuid 를 돌려주고 관리자 화면의
    // 주소(`/admin/users/:id`)에도 uuid 가 실린다. 종전 판본은 `int` + `[0-9]+` 제약이라
    // 화면에서 누르면 404 였다(2026-09-18 브라우저 실측). 코어 회원 라우트와 같은 결로
    // 라우트-모델 바인딩에 맡긴다.
    Route::delete('/credentials/{user}', [TotpAdminCredentialController::class, 'destroy'])
        ->middleware('permission:admin,sirsoft-totp.credentials.delete')
        ->name('credentials.destroy');
});
