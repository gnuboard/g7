<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\OAuthController;
use Plugins\Sirsoft\SocialLogin\Http\Controllers\WebhookController;
use Plugins\Sirsoft\SocialLogin\Mock\MockController;

/*
|--------------------------------------------------------------------------
| 소셜 로그인 플러그인 웹 라우트
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/sirsoft-social_login (PluginRouteServiceProvider 자동 적용)
| 이름 접두: web.plugins.sirsoft-social_login.
| 미들웨어: web (자동 적용)
|
*/

// 로그인 왕복 시작 — 브라우저가 이 주소로 오면 제공자 인가 화면으로 보낸다.
Route::get('/oauth/{provider}/start', [OAuthController::class, 'start'])
    ->where('provider', '[a-z][a-z0-9_]{1,49}')
    ->middleware('throttle:30,1,sirsoft-social_login.oauth-start')
    ->name('oauth.start');

// 관리자 연결 테스트 결과 — 일회용(한 번 보면 지워짐), no-store.
Route::get('/test-result/{code}', [OAuthController::class, 'testResult'])
    ->where('code', '[A-Za-z0-9]{40}')
    ->name('test-result');

// 페이스북 데이터 삭제 요청 진행 상태 — 회원이 확인 코드로 연다.
Route::get('/data-deletion/{code}', [WebhookController::class, 'dataDeletionStatus'])
    ->where('code', '[A-Za-z0-9]{20}')
    ->middleware('throttle:30,1,sirsoft-social_login.data-deletion')
    ->name('data-deletion.status');

// 제공자 → 브라우저 콜백과 모의 제공자. 제공자는 CSRF 토큰을 보내지 않는다(form_post 제공자 대비 POST 포함).
// 콜백의 위조 방어는 state(1회성, 서버 보관)가 맡는다.
Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::match(['get', 'post'], '/oauth/{provider}/callback', [OAuthController::class, 'callback'])
        ->where('provider', '[a-z][a-z0-9_]{1,49}')
        ->middleware('throttle:60,1,sirsoft-social_login.oauth-callback')
        ->name('oauth.callback');

    // 제공자 웹훅 — 제공자 서버가 보낸다(CSRF 토큰 없음). 위조 방어는 드라이버 검증(어드민 키·서명·발신 IP)이 맡는다.
    Route::prefix('webhooks')->name('webhooks.')->middleware('throttle:120,1,sirsoft-social_login.webhooks')->group(function () {
        Route::match(['get', 'post'], '/kakao', [WebhookController::class, 'kakao'])->name('kakao');
        Route::post('/naver', [WebhookController::class, 'naver'])->name('naver');
        Route::post('/facebook/deauthorize', [WebhookController::class, 'facebookDeauthorize'])->name('facebook.deauthorize');
        Route::post('/facebook/data-deletion', [WebhookController::class, 'facebookDataDeletion'])->name('facebook.data-deletion');
    });

    // 모의 제공자 — 게이트(`sirsoft-social_login:mock --enable`)가 꺼져 있으면 컨트롤러가 404.
    Route::prefix('mock')->name('mock.')->middleware('throttle:120,1,sirsoft-social_login.mock')->group(function () {
        Route::match(['get', 'post'], '/authorize', [MockController::class, 'authorize'])->name('authorize');
        Route::match(['get', 'post'], '/token', [MockController::class, 'token'])->name('token');
        Route::get('/userinfo', [MockController::class, 'userinfo'])->name('userinfo');
        Route::get('/.well-known/openid-configuration', [MockController::class, 'discovery'])->name('discovery');
        Route::get('/jwks', [MockController::class, 'jwks'])->name('jwks');
    });
});
