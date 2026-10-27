<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Sso\Http\Controllers\LinkController;
use Plugins\Sirsoft\Sso\Http\Controllers\PendingController;
use Plugins\Sirsoft\Sso\Http\Controllers\PublicIdpController;

/*
| SSO 연동 — 로그인 흐름 라우트 (api)
|
| 프리픽스·이름 접두·그룹 미들웨어는 `src/routes/api.php` 를 거쳐 PluginRouteServiceProvider 가 붙인다 (/api/plugins/sirsoft-sso).
| 요청 제한은 라우트마다 따로 센다(세 번째 인자 = 버킷 이름) — 화면마다 부르는 IdP 목록이 연결 확인 한도를 깎지 않게.
*/

$token = '[A-Za-z0-9]{48}';

// 로그인 화면 버튼용 공개 IdP 목록 — 설정, 비밀값 없음.
Route::get('/idps', [PublicIdpController::class, 'index'])
    ->middleware(['optional.sanctum', 'throttle:120,1,sirsoft-sso.idps'])
    ->name('idps');

// 처음 로그인 약관 동의 대기 — 손님. 대기 토큰과 브라우저 묶음 쿠키가 곧 권한이다.
Route::prefix('pending')->name('pending.')->group(function () use ($token) {
    Route::get('/{token}', [PendingController::class, 'show'])
        ->where('token', $token)
        ->middleware('throttle:60,1,sirsoft-sso.pending-show')
        ->name('show');
    Route::post('/{token}/complete', [PendingController::class, 'complete'])
        ->where('token', $token)
        ->middleware('throttle:20,1,sirsoft-sso.pending-complete')
        ->name('complete');
});

// 기존 회원 연결 확인 대기 — 손님.
Route::prefix('link')->name('link.')->group(function () use ($token) {
    Route::get('/{token}', [LinkController::class, 'show'])
        ->where('token', $token)
        ->middleware('throttle:60,1,sirsoft-sso.link-show')
        ->name('show');
    Route::post('/{token}/verify', [LinkController::class, 'verify'])
        ->where('token', $token)
        ->middleware('throttle:10,1,sirsoft-sso.link-verify')
        ->name('verify');
    Route::post('/{token}/email', [LinkController::class, 'email'])
        ->where('token', $token)
        ->middleware('throttle:5,1,sirsoft-sso.link-email')
        ->name('email');
});
