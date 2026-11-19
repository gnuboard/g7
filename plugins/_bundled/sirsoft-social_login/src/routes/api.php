<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\DiagnosticsController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\ProviderTestController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\StatsController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\Admin\UserAccountsController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\LinkExistingController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\MyAccountsController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\ProviderListController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\SignupController;

/*
|--------------------------------------------------------------------------
| 소셜 로그인 플러그인 API 라우트
|--------------------------------------------------------------------------
|
| URL 프리픽스 자동 적용: /api/plugins/sirsoft-social_login/
| 이름 프리픽스 자동 적용: api.plugins.sirsoft-social_login.
|
| permission 미들웨어 형식: permission:admin,sirsoft-social_login.{category}.{action}
|
*/

// 로그인·가입 화면 버튼용 공개 제공자 목록 — 자격증명 없음.
Route::get('/providers', [ProviderListController::class, 'index'])
    ->middleware('throttle:120,1,sirsoft-social_login.providers')
    ->name('providers.index');

// 소셜 가입 모달 — 게스트. 가입 대기 토큰이 곧 권한이다.
Route::prefix('signup')->name('signup.')->group(function () {
    Route::get('/pending', [SignupController::class, 'pending'])
        ->middleware('throttle:60,1,sirsoft-social_login.signup-pending')
        ->name('pending');

    // 본인인증 가입 정책은 코어 가입과 같은 정책 키로 건다 — 정책이 켜지면 428 로 코어 본인인증 화면이 뜬다.
    Route::post('/complete', [SignupController::class, 'complete'])
        ->middleware(['identity.policy:core.auth.signup_before_submit', 'throttle:20,1,sirsoft-social_login.signup-complete'])
        ->name('complete');

    // 요청 제한은 라우트마다 따로 센다(세 번째 인자 = 버킷 이름). 이름이 없으면 같은 IP 의 모든 `throttle:N,M` 라우트가 한 버킷을 나눠
    // 화면마다 부르는 제공자 목록이 인증번호 요청 한도를 깎는다.
    Route::post('/email/request', [SignupController::class, 'requestEmailCode'])
        ->middleware('throttle:10,1,sirsoft-social_login.signup-email-request')
        ->name('email.request');
    Route::post('/email/verify', [SignupController::class, 'verifyEmailCode'])
        ->middleware('throttle:10,1,sirsoft-social_login.signup-email-verify')
        ->name('email.verify');
    Route::post('/link-existing/request', [LinkExistingController::class, 'request'])
        ->middleware('throttle:10,1,sirsoft-social_login.link-existing-request')
        ->name('link-existing.request');
    Route::post('/link-existing/verify', [LinkExistingController::class, 'verify'])
        ->middleware('throttle:10,1,sirsoft-social_login.link-existing-verify')
        ->name('link-existing.verify');
    Route::post('/discard-existing', [LinkExistingController::class, 'discard'])
        ->middleware('throttle:10,1,sirsoft-social_login.discard-existing')
        ->name('discard-existing');
});

// 마이페이지 연결 관리 — 로그인 회원 본인.
Route::prefix('me')->name('me.')->middleware(['auth:sanctum', 'check.user_status', 'throttle:60,1,sirsoft-social_login.me'])->group(function () {
    Route::get('/accounts', [MyAccountsController::class, 'index'])->name('accounts.index');
    Route::post('/accounts/{provider}/link-start', [MyAccountsController::class, 'linkStart'])
        ->where('provider', '[a-z][a-z0-9_]{1,49}')
        ->name('accounts.link-start');
    Route::delete('/accounts/{provider}', [MyAccountsController::class, 'destroy'])
        ->where('provider', '[a-z][a-z0-9_]{1,49}')
        ->name('accounts.destroy');

    // 대체 이메일 회원의 본인 재확인 — 연결된 제공자로 다시 로그인.
    Route::post('/reauth/{provider}/start', [MyAccountsController::class, 'reauthStart'])
        ->where('provider', '[a-z][a-z0-9_]{1,49}')
        ->name('reauth.start');
    Route::post('/reauth/claim', [MyAccountsController::class, 'reauthClaim'])->name('reauth.claim');
});

// 관리자 — 관리자 판정(`admin`) 뒤 화면별 권한. 조회는 settings.view, 변경·테스트는 settings.update,
// 회원 연결 열람·강제 해제는 accounts.view / accounts.unlink.
Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    $view = 'permission:admin,sirsoft-social_login.settings.view';
    $update = 'permission:admin,sirsoft-social_login.settings.update';
    $key = '[a-z][a-z0-9_]{1,49}';

    Route::get('/providers', [ProviderController::class, 'index'])->middleware($view)->name('providers.index');
    Route::post('/providers', [ProviderController::class, 'store'])->middleware($update)->name('providers.store');
    Route::post('/providers/reorder', [ProviderController::class, 'reorder'])->middleware($update)->name('providers.reorder');
    Route::post('/providers/discover', [ProviderController::class, 'discover'])
        ->middleware([$update, 'throttle:20,1,sirsoft-social_login.discover'])
        ->name('providers.discover');
    Route::put('/providers/{key}', [ProviderController::class, 'update'])->where('key', $key)->middleware($update)->name('providers.update');
    Route::delete('/providers/{key}', [ProviderController::class, 'destroy'])->where('key', $key)->middleware($update)->name('providers.destroy');
    Route::get('/providers/{key}/impact', [ProviderController::class, 'impact'])->where('key', $key)->middleware($view)->name('providers.impact');

    // 연결 테스트 — 활성 여부와 관계없이. 토큰 주소 거절 분류 / 브라우저 왕복(인가 주소 반환).
    Route::post('/providers/{provider}/test', [ProviderTestController::class, 'test'])
        ->where('provider', $key)
        ->middleware([$update, 'throttle:20,1,sirsoft-social_login.test'])
        ->name('providers.test');
    Route::post('/providers/{provider}/test-login', [ProviderTestController::class, 'testLogin'])
        ->where('provider', $key)
        ->middleware([$update, 'throttle:20,1,sirsoft-social_login.test-login'])
        ->name('providers.test-login');

    Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->middleware($view)->name('diagnostics.index');
    Route::get('/attempts', [DiagnosticsController::class, 'attempts'])->middleware($view)->name('attempts.index');
    Route::get('/webhook-events', [DiagnosticsController::class, 'webhookEvents'])->middleware($view)->name('webhook-events.index');
    Route::get('/stats', [StatsController::class, 'index'])->middleware($view)->name('stats.index');

    // 회원 목록 가입경로 필터 선택지 — 꺼진 제공자도 연결이 있으면 나온다.
    Route::get('/linked-providers', [UserAccountsController::class, 'providerOptions'])
        ->middleware('permission:admin,sirsoft-social_login.accounts.view')
        ->name('linked-providers.index');
    Route::get('/users/{uuid}/accounts', [UserAccountsController::class, 'index'])
        ->where('uuid', '[0-9a-fA-F-]{36}')
        ->middleware('permission:admin,sirsoft-social_login.accounts.view')
        ->name('users.accounts.index');
    Route::delete('/users/{uuid}/accounts/{key}', [UserAccountsController::class, 'destroy'])
        ->where(['uuid' => '[0-9a-fA-F-]{36}', 'key' => $key])
        ->middleware('permission:admin,sirsoft-social_login.accounts.unlink')
        ->name('users.accounts.destroy');
});
